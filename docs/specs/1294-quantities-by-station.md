# #1294 — Track each BOM line quantity by station

Circuit `l`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype. Back-end seulement : la grille par station est #1307, le compagnon qui affiche le lieu est #1304 ; ici on livre le calcul et la ressource qu'ils liront.

## Contexte

Une ligne n'a qu'un `fulfilledQuantity` global. Le flux a besoin, par article et par station, de ce qui est à apporter, en route, sur place, sorti, en fabrication et consommé. Les sources existent : besoins matières par ligne de job (`ProjectJobMaterialNeedsLoader`, calculés par type), station d'une étape (`IndustryProjectBomLine::plannedStructureId` → `IndustryStructureConfig::getLocationId`), lecture des containers par station (#1293, `ProjectContainerReadings`), runs des jobs liés (`IndustryProjectJobMatch`), consommation des jobs démarrés (`StartedJobConsumption`). Le stock n'appartient pas à une ligne : il se lit par type et par station.

## Règles

R1. **Une entrée par type de la BOM.** `ProjectStationQuantities::of(IndustryProject)` renvoie une `TypeStationQuantities` par `typeId` distinct des lignes de la BOM (ordre `typeName`, puis `typeId`) : `typeId`, `typeName`, `lineIds` (toutes les lignes du type, matière ou job, ordre d'id), `stations`. Une matière n'a qu'une ligne par type, les lignes de job d'un même type sont des variantes (efficacité, runs) : chaque ligne de la BOM est dans exactement une entrée.

R2. **Station d'une ligne de job.** Station de l'étape = `locationId` de la configuration de structure (du propriétaire) désignée par `plannedStructureId`. Pas de `plannedStructureId`, configuration absente ou sans `locationId` : station `null` (non résolue), jamais une station inventée. Un job lancé ailleurs que prévu compte à la station du plan (le vrai lieu reste dans `jobMatches`).

R3. **Besoin.** Pour chaque ligne de job `component` ou `final` dont les besoins sont connus, chaque `typeId => quantité` de ses besoins matières s'ajoute au `needed` de ce type à la station de la ligne (R2). Les lignes de job `blueprint` (copie, invention) n'apportent pas de besoin.

R4. **Consommé.** Pour chaque ligne de job, les quantités consommées par ses runs démarrées (matches dont le statut n'est pas `cancelled` ni `reverted`, plafond aux runs de la ligne, `intdiv(besoin × runs démarrées / runs de la ligne)`) s'ajoutent au `consumed` du type à la station de la ligne. Même formule que `StartedJobConsumption` (extraite en méthode de ligne, sortie identique).

R5. **En fabrication.** Pour chaque ligne de job `component` ou `final` d'activité fabrication ou réaction, `inProduction` du type de la ligne à la station de la ligne = `intdiv(requiredQuantity × min(runs actives, runs de la ligne) / runs de la ligne)`, runs actives = runs des matches en statut `active`, `paused` ou `ready` (un job `delivered` est sorti de la machine, un job annulé ne produit rien). Ligne à 0 run (couverte par le stock) : 0.

R6. **Sur place et sorti.** Pour une station S connue (R2) avec un container de projet situé en S, lecture `known` : `raw` = quantité du type dans ce container (0 si le type n'y est pas, c'est la seule lecture où zéro est une valeur). `remaining = max(0, needed − consumed)`. `onSite = min(raw, remaining)`, `out = raw − onSite` (ce que S ne consomme plus : produit fini, surplus, article à emporter ailleurs). `toBring = max(0, remaining − onSite)`.

R7. **Jamais zéro quand on ne sait pas.** Lecture `unknown` (raison de #1293 reprise telle quelle), station sans container de projet en S (`no_container_at_station`) ou station non résolue (`station_unresolved`) : `onSite`, `out` et `toBring` valent `null` et `unknownReason` porte la raison ; `needed`, `consumed` et `inProduction` restent calculés (ils ne dépendent pas d'un asset). Un container de projet sans lieu (avant #1320) n'est pas attribué à une station : il n'alimente aucune entrée. Quand le projet en porte au moins un, une station sans container localisé porte `container_location_unknown` au lieu de `no_container_at_station` (#1353).

R8. **En route : réservé à #1295.** `inTransit` vaut `null` partout : aucune donnée ne dit aujourd'hui ce qui voyage (`IndustryProjectCourierContract` ne porte pas les lignes d'un contrat). Pas de `0`. Tant que #1295 n'est pas là, `toBring` inclut ce qui voyage. Les achats (`IndustryProjectPurchase`) ne sont pas attribués à une station (pas de destination) et restent lus par `purchasedQuantity` de la ligne.

R9. **Stations d'une entrée.** Une entrée `StationQuantity` par station où le type a un besoin, une consommation, une fabrication ou un stock lu non nul, plus l'entrée `locationId = null` pour les besoins ou fabrications d'étapes sans station. Ordre : station du plan dans l'ordre de `ProjectContainerStations::of`, puis les autres par `locationId`, `null` en dernier. `stationName` = nom de la structure du plan à ce lieu, `null` sinon. Une station dont tout est à 0 et lue sans stock n'a pas d'entrée.

R10. **Plan ou consommation indisponibles.** Si les besoins ne se calculent pas (`ProjectJobMaterialNeedsLoader::load` renvoie `null`), chaque entrée a `stations = null` et `unknownReason = plan_unavailable`. Si une ligne de job a des runs démarrées sans besoin connu (règle de `StartedJobConsumption::unknown`), idem avec `consumption_unknown`. Ni besoin ni consommation inventés.

R11. **Lecture seule.** Aucune écriture, aucun appel ESI, pas de Mercure (aucune action asynchrone), aucun montant. `fulfilledQuantity`, `ProjectLineFulfillments`, `GET /bom` et leurs appelants gardent leur comportement : le physique par station et la comptabilité globale cohabitent (un apport compte dans `fulfilledQuantity` même s'il n'est dans aucun container).

R12. **Ressource.** `GET /industry/projects/{projectId}/station-quantities` (`ProjectStationQuantitiesResource`, `Link` vers `IndustryProjectResource`, `paginationEnabled: false`, `skip_null_values: false`), une entrée par type (R1). Calculée à chaque lecture, rien de persisté. Membre accepté : 200 ; étranger au projet : 403 ; projet inexistant ou uuid invalide : 404 ; non authentifié : 401.

R13. **Front, couche de données seulement.** Types et module de store `stationQuantities.ts` (chargement par projet, état `unknown` conservé, jamais converti en 0). Aucun écran : #1307 (grille) et #1304 (compagnon) lisent ce store.

## Exemples (tests rouges)

Projet « Ishtar », propriétaire de la corporation C ; stations A (100) et B (200) dans le plan, un container de projet par station ; job F (final, 10 runs, station B) a pour besoins 100 Zydrine et 40 « Composant X » ; job K (component, produit Composant X, 8 runs, 40 unités, station A) a pour besoin 80 Tritanium.

| Cas | Lecture | Résultat |
|---|---|---|
| Zydrine à B, containers vides | B `known` | B : needed 100, consumed 0, onSite 0, out 0, toBring 100 |
| Zydrine : 60 dans le container de B | B `known` | onSite 60, toBring 40 |
| Zydrine : 130 dans le container de B | B `known` | onSite 100, out 30, toBring 0 |
| 5 des 10 runs de F démarrées, Zydrine : 30 dans B | B `known` | needed 100, consumed 50, remaining 50, onSite 30, toBring 20 |
| Tritanium à A : container de A `unknown` (`corporation_assets_not_synced`) | A `unknown` | needed 80, onSite/out/toBring `null`, `unknownReason` repris |
| Composant X : job K actif 8 runs | A et B | A : inProduction 40, needed 0, onSite 0, out 0, toBring 0 ; B : needed 40, toBring 40 |
| Composant X : job K livré, 40 dans le container de A | A `known` | A : inProduction 0, onSite 0, out 40 ; B : toBring 40 |
| Composant X : 15 dans le container de B | B `known` | B : onSite 15, toBring 25 |
| Produit final : 20 dans le container de B | B `known` | B : needed 0, out 20 |
| Container de projet sans lieu contenant 50 Zydrine | — | aucune entrée n'en tient compte |
| Job F sans `plannedStructureId` | — | entrée `locationId = null` : needed 100, onSite/out/toBring `null`, `station_unresolved` |
| Station du plan sans container de projet | — | `no_container_at_station`, `null` |
| Plan non calculable | — | `stations = null`, `plan_unavailable` |
| 4 runs sur 10 démarrées sur la ligne à 100 Zydrine | — | consumed 40 ; `StartedJobConsumption::of` renvoie la même valeur (non-régression) |
| Job `cancelled` | — | pas de consommé ni d'inProduction |
| Ligne à 0 run | — | inProduction 0, aucun besoin |

## Cas limites

- Deux lignes de job du même type (efficacités différentes) : besoins, consommés et fabrications s'additionnent dans l'entrée du type.
- Un type absent de tous les containers lus : `raw = 0`, valeur `known`.
- `typeName` absent d'un asset : l'entrée prend le nom de la ligne de BOM.
- Quantités entières, jamais négatives.

## Accès et fraîcheur

Lecture : membre accepté (`ProjectAccessChecker::assertAcceptedMember`), comme `container-readings`. Assets de la corporation du propriétaire filtrés par ses divisions visibles (comportement de #1293, repris sans changement) : un participant d'une autre corporation voit les quantités des containers du propriétaire. Fraîcheur : cache d'assets (sync à la demande), `assetsSyncedAt` non repris ici (disponible dans `container-readings`).

## Tests existants modifiés

Aucun attendu : l'extraction de la consommation par ligne laisse `StartedJobConsumptionTest` et `StartedJobConsumptionReaderTest` verts sans toucher une assertion.

## Hors périmètre

Transferts et « en route » (#1295) ; BPC rattaché à une étape (#1296) ; stock intermédiaire, Pivot et Slot Tracker par station (#1166, #1167, #1168) ; apport de stock d'un membre (#1318) ; migration des projets (#1320) ; grille et compagnon (#1307, #1304) ; attribution des achats à une station ; bascule de `fulfilledQuantity` et des cartes existantes.

## Glossaire

Ajout : **Quantité par station** (Quantity by station), états **à apporter**, **sur place**, **sorti**, **en fabrication**, **consommé**, **en route** (réservé).

## Décisions

D1. Back-end et couche de données front seulement : l'écran est #1307, le compagnon #1304 (comme #1293 : le calcul d'abord).
D2. Un enregistrement par type et par station, pas par ligne : le stock ne se rattache pas à une ligne, plusieurs lignes de job partagent un type ; chaque ligne reste référencée par `lineIds`.
D3. `out` = stock d'un container de station au-delà de ce que la station consomme encore : une seule lecture du container porte « sur place » et « sorti », sans double compte.
D4. `inTransit = null` jusqu'à #1295 ; `toBring` inclut ce qui voyage.
D5. Station d'une étape = station du plan (R2), pas la structure du job lié ; un job lancé ailleurs est un écart à relire.
D6. `fulfilledQuantity` et `GET /bom` inchangés : aucune suppression sans accord de Sylvain.

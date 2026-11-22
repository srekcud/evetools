# #1364 — Courses à faire entre les stations d'un projet (lecture)

Base `origin/main`, circuit `l`, mode nuit : décisions prises sur la recommandation (#1359, commentaire du 2026-11-22). Back-end seul : l'onglet Transport du compagnon est #1359, la valorisation d'un départ depuis le stock perso est #1365.

## Contexte

L'onglet Transport doit dire « quoi porter, d'où, vers où ». Les sources existent mais aucun endpoint ne les joint : `ProjectStationQuantities::of` (à apporter, sorti, en fabrication par type et station, #1294), `ProjectStationTransfers::of` (tronçons déclarés, en route, #1295), `PurchasesToDeliverBuilder::build` (achats pas encore au container, par système d'achat), `PersonalStockReader::of` (stock perso par station, #1324), `CharacterStructureAccess::statusOf` (accès d'un personnage, #1299), `ItemVolumes::volumeOf` (m³ SDE). Il manque le système solaire des stations, le volume, l'échéance des jobs et l'accès par personnage du lecteur.

## Règles

R1. **Course.** Une course = un départ → une station d'arrivée, avec ses lignes. Clé `key` = `<départ>><arrivée>` où départ = `locationId`, ou `s<solarSystemId>` sans station. Une course sans ligne n'existe pas.
R2. **Arrivée.** Toute station `S` d'une `TypeStationQuantities` avec `toBring > 0` (ordre du plan, R9 de #1294) est une demande `(type, S, toBring)`. `toBring = null` (lecture inconnue, station non résolue) : aucune course vers `S` pour ce type, une entrée `unreadable {typeId, typeName, locationId, reason}` avec la raison de la station ; `stations = null` : une entrée `unreadable` par type, `locationId = null`.
R3. **Départs candidats, dans cet ordre.** (a) achats au hub : lignes de `PurchasesToDeliver` du type, ordre du builder (Jita, autres, par nom), disponible = `missingQuantity` ; (b) stations du projet `A ≠ S` dans l'ordre du plan, disponible prêt = `out(A)`, disponible en fabrication = `max(0, inProduction(A) − toBring(A) − inTransit(A))` (valeurs `null` : A n'est pas candidat) ; (c) stock perso du lecteur, stations de `PersonalStock::stationsOf(type)` dans leur ordre (quantité décroissante), hors `S` elle-même.
R4. **Premier qui tient tout, sinon glouton.** Pour chaque demande (stations dans l'ordre du plan, types par `typeName` puis `typeId`) : le premier candidat dont le disponible (prêt + en fabrication) couvre `toBring` la sert seul ; sinon chaque candidat donne `min(disponible, reste)` dans l'ordre. Le disponible d'un candidat diminue de ce qu'il a donné (partagé entre demandes). Un reste non couvert ne fait pas de course. Aucune optimisation.
R5. **Lignes.** Une ligne par (type, état, source) dans une course : `state` `ready` (achat au hub, `out`, stock perso) | `in_production` (part en fabrication) | `in_transit` (R6) ; `source` `purchase` | `project` | `personal_stock` | `transfer`. Une station A prend d'abord son prêt puis son en fabrication.
R6. **En route.** Pour chaque trajet de transfert (`routeId`) dont le dernier tronçon a `toLocationId` non nul : ligne `in_transit`, `source = transfer`, quantité = `departed(premier tronçon) − arrived(dernier)` (formule de `InTransitByStation`), course = départ du premier tronçon → arrivée du dernier. Quantité 0 : pas de ligne. Quantité illisible : `quantity = null`, `unknownReason = transfer_unreadable`.
R7. **Échéance.** Ligne `in_production` : `readyAt` = plus tardive `endDate` des matches `active`, `paused` ou `ready` des lignes de job `component`/`final` fabrication ou réaction du type planifiées à A ; une `endDate` nulle parmi elles : `readyAt = null`. Autres états : `readyAt = null`.
R8. **Volume.** `unitVolume` = `ItemVolumes::volumeOf(typeId)` ; `volume` de la ligne = `round(quantité × unitVolume, 6)`, `null` si l'un est `null`. `volume` de la course = somme arrondie à 6 décimales, `null` dès qu'une ligne est `null`. Jamais 0 pour un inconnu.
R9. **Lieux.** `departure` et `arrival` : `{locationId, stationName, solarSystemId, solarSystemName}`. Station du plan : nom et système de la configuration (`ProjectContainerStation`). Autre station : système par `PurchaseOriginResolver::solarSystemIdOfLocation` (NPC par `StaStation`, structure par `CachedStructure` puis configuration), nom du stock perso ou du tronçon. Achat au hub : `locationId = null`, `stationName = null`, système d'achat. Inconnu : `null`, la course reste.
R10. **Accès du lecteur, par personnage.** Statut d'une extrémité pour un personnage c : `CharacterStructureAccess::statusOf(c, locationId)` (NPC toujours `granted`) ; sans `locationId` : `granted` si le système est Jita, sinon `unknown`. `access = granted` si un personnage est `granted` aux deux bouts ; course omise si chaque personnage est `denied` à au moins un bout ; sinon `unknown`. `carrierCharacterIds` = personnages du lecteur `granted` aux deux bouts (ordre des personnages), seul identifiant de personnage exposé.
R11. **Ordre.** Courses par rang du plan de l'arrivée, puis départ (stations du plan dans l'ordre, achats, autres stations par `locationId`). Lignes par état (`ready`, `in_production`, `in_transit`) puis `typeName`. Le tri « ici » est du front (#1359), il lit `departure.locationId`.
R12. **Stock perso illisible.** `hasPersonalAssetsOf(lecteur)` faux : `personalStockUnknownReason = personal_assets_not_synced` ; `PersonalStock::unknownReason()` non nul : cette raison. Dans les deux cas aucun départ (c) ; le reste est calculé.
R13. **Projet terminé.** `completed` : `legs = []`, `unreadable = []`, pas de lecture d'assets.
R14. **Lecture seule.** Rien d'écrit, aucun appel ESI, pas de Mercure, aucun montant. `station-quantities`, `purchases-to-deliver`, `station-transfers` et `intermediate-stock-proposal` inchangés.
R15. **Ressource.** `GET /industry/projects/{projectId}/transport-legs`, membre accepté (`ProjectAccessChecker::assertAcceptedMember`) : 200 ; étranger : 403 ; projet inexistant ou uuid invalide : 404 ; non authentifié : 401. Seuls les personnages et le stock perso du lecteur sont lus.

## Exemples (tests rouges)

Plan : A (structure 1 035 000 000 001, système 30000001) puis B (structure 1 035 000 000 002, système 30000002). Lecteur : personnages c1, c2. Volumes : Composant X 10 m³, Zydrine 0,01 m³, Nocxium inconnu. Sans mention, c1 `granted` partout.

| # | Lectures | Courses attendues |
|---|---|---|
| E1 | X : A out 40 ; B toBring 40 | A>B : X ready 40 `project`, volume 400 |
| E2 | X : A inProduction 40 (job fin 2026-11-23T10:00), out 0 ; B toBring 40 | A>B : X in_production 40, readyAt 10:00 |
| E3 | X : A out 15, inProduction 25 (deux jobs, fins 10:00 et 14:00) ; B toBring 40 | A>B : ready 15 + in_production 25 readyAt 14:00 |
| E4 | X : station C (hors plan, 60003760) out 50, A out 10 ; B toBring 40 | A ne tient pas tout, C oui : 60003760>B ready 40 seule |
| E5 | X : A out 10, C out 20 ; B toBring 40 | glouton : A>B 10, 60003760>B 20 ; 10 sans course |
| E6 | Zydrine : achat Jita manquant 100 ; B toBring 100 | s30000142>B : ready 100 `purchase`, volume 1, départ `locationId null` |
| E7 | Zydrine : achat Jita 60 ; A out 40 ; B toBring 100 | glouton : s30000142>B 60, A>B 40 |
| E8 | X : trajet A→B, departed 30, arrived 10 ; B toBring 10, A out 10 | A>B : ready 10 + in_transit 20 `transfer` |
| E9 | X : trajet illisible | A>B : in_transit `quantity null`, `transfer_unreadable`, volume `null` |
| E10 | X : stock perso 50 à 60003760 ; B toBring 40 ; aucun autre départ | 60003760>B : ready 40 `personal_stock` |
| E11 | X : stock perso 30 à 60003760 ; B toBring 40 | 60003760>B : 30 ; 10 sans course |
| E12 | Stock perso d'un container masqué | `personalStockUnknownReason = container_in_hidden_division`, pas de départ perso |
| E13 | Nocxium : A out 5 ; B toBring 5 | ligne `unitVolume null`, `volume null`, course `volume null` |
| E14 | c1 : A granted, B denied ; c2 : A unknown, B granted | `access unknown`, `carrierCharacterIds []` |
| E15 | c1 : A denied ; c2 : B denied | course omise |
| E16 | c1 granted A et B ; c2 denied A | `access granted`, `carrierCharacterIds [c1]` |
| E17 | Lecteur sans personnage | `legs []` |
| E18 | X : B container `unknown` (`corporation_assets_not_synced`) | pas de course vers B ; `unreadable [{X, B, corporation_assets_not_synced}]` |
| E19 | Plan non calculable | `legs []` (hors en route) ; une entrée `unreadable` par type, `plan_unavailable` |
| E20 | Système de 60003760 absent du SDE | course gardée, `solarSystemId` et `solarSystemName` `null` |
| E21 | Projet `completed` | `legs []`, `unreadable []` |
| E22 | Achat d'origine inconnue | pas de course ; `unreadable [{type, null, purchase_origin_unknown}]` |
| E23 | Membre accepté / étranger / projet inconnu / anonyme | 200 / 403 / 404 / 401 |

## Cas limites

- Quantité en partie en route : `toBring` de B l'exclut déjà (#1295) ; seule la part en route forme la ligne `in_transit`.
- Un achat au hub dont le type n'a aucune station `toBring > 0` (besoin à une station non résolue, ou déjà couvert) : pas de course ; la station non résolue est dans `unreadable`.
- Transfert déclaré pas encore parti : non déduit (`InTransitByStation` ne compte que le parti), la ligne reste `ready` ; le front (#1359) rapproche les tronçons déclarés.
- Achat au hub dont `missingQuantity` est `null` (livraison illisible) : pas de départ, une entrée `unreadable {typeId, typeName, null, purchase_delivery_unknown}` quand le type a une demande.
- Stock perso à la station d'arrivée : pas de course (apporter depuis son stock, #1318).
- Quantités entières, jamais négatives ; aucun `?? 0` sur une lecture.

## Tests existants contredits

Aucun attendu. `PurchaseOriginResolver::solarSystemIdOf` délègue à la nouvelle méthode sans changer de sortie (`CourierOverviewBuilderTest`, `PurchasesToDeliverBuilderTest` verts).

## Hors périmètre

Onglet Transport, déclarer et arrivé (#1359) ; valorisation d'un départ depuis le stock perso (#1365) ; tri « ici » ; optimisation des départs ; découpage d'une course en tronçons (route Jita → hub → station) ; volume reconditionné ; Mercure.

## Décisions (mode nuit)

- D1 « Sur place ou sorti » au départ : seul `out(A)` part ; `onSite(A)` est déjà réservé aux étapes de A (R6 de #1294) et le déplacer affamerait A.
- D2 Station planifiée d'un achat : une ligne d'achat n'a pas de `plannedStructureId` (seules les lignes de job le reçoivent, `IndustryProjectService`) ; l'arrivée est la station du plan qui a son type à apporter (R2-R4).
- D3 Ordre des départs : achats au hub d'abord (achetés pour ce besoin), puis stations du projet, puis stock perso.
- D4 Départ sans station (achat au hub) : Jita accessible (station NPC), autre système inconnu.
- D5 Plusieurs jobs en fabrication : une ligne, échéance la plus tardive.
- D6 Terme « Course » (EN *haul leg*) : « Trajet » (`WorkTransportLeg`, #1285) et « Tronçon » (#1295) sont déjà pris ; l'URL garde `transport-legs` (issue).

## Glossaire

Ajouté dans `docs/glossary.md` : **Course** (*Haul leg*).

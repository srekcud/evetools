# #1295 — Transfer step between stations, detected from assets or declared

Circuit `l`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype. Back-end et couche de données front : la grille par station est #1307, le détail de carte #1310, le compagnon #1304 ; ici on livre le calcul et les ressources qu'ils liront.

## Contexte

`inTransit` vaut `null` partout depuis #1294 (R8) : aucune donnée ne dit ce qui voyage. Le hub de courier existe depuis #1286 (`CourierHub`, `CourierHubSettings`, `IndustryProjectService::favoriteSystemsOf()->courierHub`) ; les volumes se lisent par `ItemVolumes::volumeOf()`. Le mot « transfert » est déjà pris : le **virement** (`TransferPlanner`) solde l'argent. Le nouveau concept se nomme **transfert entre stations** (`StationTransfer`), jamais « transfer » seul.

## Règles

R1. **Un transfert est un tronçon.** `IndustryProjectStationTransfer` : projet, `routeId` (uuid partagé par les tronçons d'un trajet), `legIndex` (0, 1…), départ et arrivée (`solarSystemId` obligatoire, `locationId` nullable : un hub ou un système sans station n'a pas de lieu), porteur (`carrierKind` `courier` | `jump_bridge` | `pilot`, `carrierCharacterId` et `contractId` nullables), `lines` (JSON : `typeId`, `quantity`, `departureBaseline`, `arrivalBaseline`, les deux dernières `null` si illisibles à la déclaration), `declaredBy`, `declaredAt`, `arrivedAt` (nullable, posé à la main). Aucun montant stocké.

R2. **Trajet.** `TransferRoute::legs(fromSystemId, toSystemId, hubSystemId)` (fonction pure, d'après le prototype) : même système → un tronçon `pilot` ; Jita (`EveConstants::JITA_SOLAR_SYSTEM_ID`) vers un système X : X = hub → `courier` Jita→hub ; sinon `courier` Jita→hub puis `jump_bridge` hub→X ; X vers Jita : `jump_bridge` X→hub (omis si X = hub) puis `courier` hub→Jita ; ailleurs → un tronçon `jump_bridge`. Le courier va toujours de Jita au hub (décision de Sylvain, #1286).

R3. **Déclarer.** `POST /industry/projects/{projectId}/station-transfers` : `fromSolarSystemId`, `fromLocationId?`, `toSolarSystemId`, `toLocationId?`, `lines[{typeId, quantity}]`, `carrierCharacterId?`, `contractId?`. Le service calcule les tronçons (R2) avec le hub du propriétaire et les crée d'un coup ; `carrierCharacterId` et `contractId` vont sur le premier tronçon. Les baselines (R5) sont lues à la déclaration. Membre accepté ; projet terminé : 409 ; départ et arrivée identiques (même `locationId`, ou même système sans lieu) : 422 ; ligne vide, quantité < 1, `typeId` absent de la BOM, système inconnu du SDE, `carrierCharacterId` qui n'est pas un personnage du déclarant : 422 ; deux lignes du même type : sommées. Réponse 201 avec le premier tronçon.

R4. **Lire.** `GET /industry/projects/{projectId}/station-transfers` (membre accepté, 403 étranger, 404 projet inconnu, 401 anonyme) : tous les tronçons du projet, ordre `declaredAt`, `routeId`, `legIndex`. Chaque tronçon porte départ et arrivée (noms de système SDE, nom de station si le lieu est une station du plan), porteur, déclarant, `state` (R6), `expectedArrivalAt`, `volume` en m³ (R7), et par ligne `typeName`, `quantity`, `volume`, `departed`, `arrived`, `inTransit`, `departureSource` (`assets` | `declared`), `arrivalSource` (`assets` | `declared` | `pending`), `unknownReason`. Calculé à chaque lecture.

R5. **Détection par les assets.** Lecture des containers de projet (#1293, `ProjectContainerReadings::of`). Le départ est **mesurable** si `fromLocationId` a un container de projet lu `known` à la déclaration (baseline posée) et maintenant ; l'arrivée de même avec `toLocationId`. `left = clamp(0, quantity, departureBaseline − quantité actuelle au départ)` ; `arrivedMeasured = clamp(0, quantity, quantité actuelle à l'arrivée − arrivalBaseline)`. Lecture actuelle `unknown` alors que la mesure est possible : valeur `null`, `unknownReason` repris de la lecture, jamais 0.

R6. **États par ligne et par tronçon.** `departed` : tronçon 0 avec départ mesurable → `left` (source `assets`) ; tronçon 0 sinon → `quantity` (la déclaration vaut départ, source `declared`) ; tronçon k > 0 → `arrived` du tronçon k−1 (source `declared`). `arrived` : `arrivedAt` posé → `quantity` (source `declared`) ; sinon arrivée mesurable → `arrivedMeasured` (source `assets`) ; sinon arrivée non mesurable (pas de container de projet au lieu d'arrivée) → 0, source `pending`. `inTransit = max(0, departed − arrived)`, `null` si `departed` ou `arrived` est `null`. `state` du tronçon : `arrived` (toutes les lignes arrivées), `unknown` (une ligne `null`), `awaiting_departure` (aucune ligne partie), `in_transit` sinon.

R7. **Volume.** `volume = quantity × ItemVolumes::volumeOf(typeId)` en m³ (arrondi 6 décimales), par ligne ; `volume` du tronçon = somme, `null` avec `unknownReason = volume_unknown` si un type n'a pas de volume, jamais 0.

R8. **Durée attendue.** `expectedArrivalAt = declaredAt + durée du porteur` : `pilot` 30 min, `jump_bridge` 120 min (prototype), `courier` `CourierQuoteCalculator::DAYS_TO_COMPLETE` jours. Indicatif, jamais utilisé pour décider d'un état.

R9. **Arrivée à la main.** `POST /industry/projects/{projectId}/station-transfers/{id}/arrive` (`EmptyInput`) : déclarant, propriétaire ou admin ; pose `arrivedAt` ; tronçon précédent non arrivé : 409 ; déjà arrivé : 409 ; 403, 404, 401 comme R4. Retourne le tronçon.

R10. **Supprimer.** `DELETE …/station-transfers/{id}` (provider qui renvoie la ressource) : déclarant, propriétaire ou admin ; supprime aussi les tronçons suivants du même `routeId` ; tronçon arrivé : 409 (l'historique d'un trajet fait ne s'efface pas). Projet terminé : 409.

R11. **En route à la station.** Par trajet (`routeId`), ligne par ligne : `departed` = celui du premier tronçon, `arrived` = celui du dernier, `inTransit = max(0, departed − arrived)`, `null` si l'un est `null`. `inTransit` d'un type à la station S = somme de ces `inTransit` pour les trajets dont le dernier tronçon arrive en `locationId = S` ; zéro seulement quand aucun trajet ne vise S pour ce type.

R12. **Quantités par station (#1294 modifié).** `StationQuantityCalculator::compute` reçoit `inTransitByLocationAndType` (`array<int, array<int, int|null>>`). `StationQuantity::inTransit` = valeur R11 (0 sans trajet, `null` si inconnu). `toBring = max(0, remaining − onSite − inTransit)` ; `inTransit` `null` → `toBring` `null` avec `unknownReason = transfer_unreadable`. Une station avec seulement un `inTransit` > 0 a une entrée (R9 de #1294). Les assertions de #1294 qui figeaient `inTransit = null` passent à 0 ou à la valeur R11 : changement voulu (R8 de #1294 le réservait à cette issue).

R13. **Temps réel.** Déclarer, arriver et supprimer publient `station_transfer_changed` via `MercurePublisherService::publishGroupProjectEvent` (topic projet existant `/group-project/{id}/events`, donnée : `routeId`, `legIndex`, `action` `declared` | `arrived` | `deleted`). Pas de nouvel événement du fil d'activité (`ProjectActivityKind` inchangé). Aucune sync planifiée : la détection est calculée à la lecture.

R14. **Front, couche de données seulement.** Types et module `stationTransfers.ts` (chargement par projet, déclarer, arriver, supprimer, état `unknown` et `null` conservés, jamais convertis en 0). Pas de câblage de `station_transfer_changed` dans les rafraîchissements de la page (aucun écran ne lit encore le store : #1307). Aucun écran : #1307, #1309 (attendu en pointillé), #1310 et #1304 lisent ce store.

## Exemples (tests rouges)

Hub = C-J6MT, stations du plan A (système S1, lieu 100, container de projet) et B (système S2, lieu 200, container de projet), Jita 30000142.

| Cas | Résultat |
|---|---|
| Jita → A (S1 ≠ hub) | trajet de 2 tronçons : `courier` Jita→hub, `jump_bridge` hub→S1 |
| Jita → hub | 1 tronçon `courier` |
| A → Jita | `jump_bridge` S1→hub puis `courier` hub→Jita |
| A (S1) → B (S2) | 1 tronçon `jump_bridge` |
| A → A' même système | 1 tronçon `pilot` |
| Déclarer 100 Zydrine de A vers B (baseline A 150, baseline B 0), container A à 150, B à 0 | `awaiting_departure`, departed 0, arrived 0, inTransit 0 |
| A tombe à 40, B à 0 | left 100 (plafonné), arrived 0, `in_transit`, inTransit 100 |
| A à 40, B à 70 | arrived 70, inTransit 30 |
| B à 130 | arrived 100 (plafonné), `arrived`, inTransit 0 |
| Container de B `unknown` (`corporation_assets_not_synced`) | arrived `null`, inTransit `null`, `unknown`, raison reprise |
| Départ sans container de projet (Jita) | departed = quantité (déclaré) ; arrivée à A mesurée |
| Tronçon hub→A : tronçon 0 non arrivé | 409 sur `arrive` ; departed du tronçon 1 = arrived du tronçon 0 |
| `arrive` sur le tronçon 0 | `arrivedAt` posé, arrived = quantité, `arrivalSource = declared` |
| Zydrine 100 à A, trajet Jita→A déclaré, rien arrivé | station A : inTransit 100, toBring = max(0, 100 − 0 − 100) = 0 |
| Station A, trajet vers A avec lecture `unknown` | inTransit `null`, toBring `null`, `transfer_unreadable` |
| Type sans volume SDE | `volume = null`, `volume_unknown` |
| 2 lignes du même type | une ligne sommée |

## Cas limites

- Quantités entières, jamais négatives ; `routeId` généré par le serveur.
- Un projet sans transfert : `GET` renvoie `[]`, `inTransit` vaut 0 aux stations lues.
- Déclaration sans baseline lisible : départ ou arrivée non mesurable (source `declared` ou `pending`), documenté dans `unknownReason` seulement quand la lecture actuelle est inconnue.
- Consommation entre la déclaration et la lecture : l'arrivée mesurée baisse ; l'arrivée à la main prime.

## Accès et fraîcheur

Lecture et déclaration : `ProjectAccessChecker::assertAcceptedMember` ; arrivée et suppression : déclarant, propriétaire ou admin (`isAdminOrOwner`). Les lectures de containers sont celles de #1293 (corporation du propriétaire) ; aucune donnée d'un autre projet. Fraîcheur : cache d'assets.

## Hors périmètre

Transferts détectés sans déclaration (le cache d'assets ne garde pas d'historique : il faudrait des instantanés ; à décider avec #1307) ; coût en ISK d'un tronçon (le coût d'import est calculé par #1286) ; lien automatique d'un contrat de courier à un tronçon ; grille, cartes en pointillé et compagnon (#1307, #1309, #1304) ; migration des projets existants (#1320).

## Glossaire

Ajouts : **Transfert entre stations** (Station transfer), **Trajet** (Route), **Tronçon** (Leg), états **en attente de départ**, **en route**, **arrivé**, **inconnu** ; **en route** (#1294) n'est plus réservé.

## Décisions

D1. Tronçon persisté, trajet = tronçons de même `routeId` : un courier puis un pont sont deux transferts (critère d'acceptation) avec leurs propres départ, arrivée et porteur.
D2. Détection par comparaison d'une baseline posée à la déclaration avec la lecture actuelle (le cache d'assets n'a pas d'historique) : un transfert sans déclaration n'est pas détecté.
D3. Pas de montant ni de coût ; volumes en m³ calculés à la lecture.
D4. `toBring` exclut ce qui voyage depuis cette issue ; `inTransit` null rend `toBring` null (jamais de valeur plausible).
D5. Un membre accepté peut déclarer (il agit sur son propre travail) ; seule la suppression et l'arrivée sont limitées au déclarant, propriétaire ou admin.

# Spec #263 — Minage groupé : quantités minées par participant à partir des instantanés du ledger ESI

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-03, à valider par Sylvain avant `/tdd-red`.
- **État du code de référence** : `origin/main` @ `a6aead59` (#262 fusionné).
- **Maquette de référence** : `mockups/grouped-mining-v4.html` (branche `docs/mining-specs`) : `opHeader()` (boutons « Démarrer l'opération », « Terminer l'opération », « Corriger une quantité »), `participantRows()` (badges « ✓ début n/n », « ✗ début 1/2 », « parti HH:MM · fin lue », « à reconnecter » + « Relancer »), carte « Démarrage » de `renderPlanned()`, `renderClosing()` (« En attente du ledger de fin », « Lecture des instantanés de fin N / M personnages », table « Instantanés de fin », cartes « Départ anticipé » et « Correction »), modale `end`, bouton « Je pars » du compagnon.
- **Dépend de** : #262 (opération, membres, personnages inscrits), #261 (glossaire Minage, PR #326).

## 1. Contexte

#262 crée l'opération de minage et inscrit les personnages des participants ; seul le statut `planned` est atteint. Ce lot fait vivre l'opération : on la **démarre**, on la **termine**, un participant peut **partir plus tôt**, et chaque personnage inscrit reçoit un instantané du ledger de minage ESI au début et à la fin. La quantité minée d'une opération en découle, par personnage, par participant et par type, sans déclaration manuelle.

Constat dans le code (lu le 2026-11-03) :

| Élément | État | Usage dans #263 |
|---|---|---|
| `MiningSyncService::syncAll()` | lit `GET /characters/{id}/mining/` via `EsiClient::getPaginated()` pour chaque personnage du compte, alimente `MiningEntry` (onglet Revenus › Minage), publie `syncStarted` → `syncProgress` → `syncCompleted`/`syncError` sur `/user/{id}/sync/mining` | non modifié (ses trois fichiers de tests simulent `getPaginated()`) ; la lecture d'un personnage est **réécrite** dans un lecteur dédié qui a besoin de `Expires` |
| `EsiClient::getPaginatedWithExpiry()` → `PaginatedEsiResponse{items, expiresAt}` | `expiresAt` = plus petit `Expires` des pages, `null` si une page n'en a pas | lecture de chaque instantané ; `expiresAt` fixe l'heure où l'instantané de fin devient lisible |
| `TokenManager::getValidAccessToken()` | lève `EveAuthRequiredException` (non rejouée par Messenger) pour un token révoqué et marque le personnage à reconnecter | personnage « à reconnecter », quantité inconnue |
| `MiningEntry` | une ligne par (personnage, date, type, système), quantité cumulée du jour | non utilisé : un instantané garde sa propre copie, `MiningEntry` est réécrit à chaque sync |
| Messenger | transport `async` AMQP (RabbitMQ), `in-memory://` en test, aucun `DelayStamp` dans le code | premier message différé du projet (`DelayStamp`) |
| `Psr\Clock\ClockInterface` | injecté dans `ReprocessingProfileRecalculator`, `EsiClient` ; `MockClock` dans les tests | horloge de toutes les règles temporelles de ce lot |
| `MercurePublisherService::getTopicsForUser()` | 16 topics, dont `/user/{id}/mining-operations` (#262) | 17e topic `/user/{id}/sync/mining-operation-snapshot` |
| `MiningOperationStatus` | `planned`, `in_progress`, `closing`, `completed` déclarés | #263 atteint `in_progress` et `closing` ; `completed` reste à #265 |

## 2. Modèle

### Entités (une seule migration Doctrine)

| Entité / table | Changement | Contraintes |
|---|---|---|
| `MiningOperation` / `mining_operations` | + `startedAt` datetime_immutable nullable ; + `endedAt` datetime_immutable nullable ; + `solarSystemId` int nullable ; + `solarSystemName` varchar(100) nullable | — |
| `MiningOperationMember` / `mining_operation_members` | + `departedAt` datetime_immutable nullable | — |
| `MiningOperationCharacter` / `mining_operation_characters` | porte ses instantanés (`OneToMany`, `cascade: persist, remove`, `orphanRemoval`) et ses corrections (idem) | inchangé |
| `MiningLedgerSnapshot` / `mining_ledger_snapshots` (nouveau) | `id` UUID ; `operationCharacter` ; `kind` varchar(10) `MiningLedgerSnapshotKind` ; `status` varchar(20) `MiningLedgerSnapshotStatus` ; `referenceAt` datetime_immutable (instant que l'instantané doit refléter : démarrage, acceptation, fin, départ, relance) ; `readyAt` datetime_immutable nullable ; `takenAt` datetime_immutable nullable ; `esiExpiresAt` datetime_immutable nullable ; `failureReason` varchar(255) nullable | `operation_character_id` `ON DELETE CASCADE` ; `UNIQUE(operation_character_id, kind)` |
| `MiningLedgerSnapshotLine` / `mining_ledger_snapshot_lines` (nouveau) | `id` UUID ; `snapshot` ; `date` date_immutable ; `typeId` int ; `solarSystemId` int ; `quantity` bigint | `snapshot_id` `ON DELETE CASCADE` ; `UNIQUE(snapshot_id, date, type_id, solar_system_id)` |
| `MiningQuantityCorrection` / `mining_quantity_corrections` (nouveau) | `id` UUID ; `operationCharacter` ; `typeId` int ; `originalQuantity` bigint nullable (`null` = quantité inconnue) ; `correctedQuantity` bigint ; `correctedBy` → `users` nullable ; `correctedByName` varchar(255) nullable ; `correctedAt` datetime_immutable | `operation_character_id` `ON DELETE CASCADE` ; `corrected_by_id` `ON DELETE SET NULL` ; index `(operation_character_id, type_id)` |

### Enums (`src/Enum/`)

| Enum | Cas (valeur) |
|---|---|
| `MiningLedgerSnapshotKind` | `Start` (`start`), `End` (`end`) |
| `MiningLedgerSnapshotStatus` | `Scheduling` (`scheduling`, instantané de fin en attente de son heure de lecture), `Waiting` (`waiting`, heure de lecture connue), `Taken` (`taken`), `ReconnectRequired` (`reconnect_required`), `Failed` (`failed`), `OutOfWindow` (`out_of_window`) |
| `MinedQuantityState` | `Measuring` (`measuring`), `Known` (`known`), `Unknown` (`unknown`) |
| `MinedQuantityUnknownReason` | `ReconnectRequired` (`reconnect_required`), `ReadFailed` (`read_failed`), `OutOfWindow` (`out_of_window`), `LedgerInconsistent` (`ledger_inconsistent`) |

`MiningLedgerSnapshotStatus::isSettled()` : vrai pour `taken`, `reconnect_required`, `failed`, `out_of_window`.

## 3. Règles métier

### Démarrer

- **R1 — Démarrer.** `POST /api/mining-operations/{id}/start` avec `{solarSystemId: int|null}` passe une opération `planned` à `in_progress`. Administration (#262 R17). `startedAt` = heure de l'horloge. Réponse `200`, l'opération. Statut autre que `planned` : `400` « Only a planned operation can be started ».
- **R2 — Système de l'opération.** `solarSystemId` est facultatif. Renseigné, il doit exister dans le SDE (`MapSolarSystemRepository::find()`), sinon `400` « Unknown solar system » ; `solarSystemName` reçoit son nom. Il ne change plus ensuite. `null` : pas de filtre (R13).
- **R3 — Instantané de début.** Au démarrage, chaque personnage inscrit d'un membre `accepted` reçoit un instantané `start` au statut `waiting`, `referenceAt` = `readyAt` = `startedAt`, et un message `TakeMiningLedgerSnapshot` sans délai. Les personnages d'un membre `pending` n'en reçoivent pas.
- **R4 — Inscrit en retard.** Dans une opération `in_progress`, un personnage reçoit son instantané de début (R3, `referenceAt` = heure de l'horloge) quand son membre devient `accepted` : à l'adhésion si elle est acceptée automatiquement (#262 R9), sinon à l'acceptation par `PATCH` (#262 R15).
- **R5 — Plus d'acceptation en clôture.** `PATCH …/members/{id}` `{status: "accepted"}` sur une opération dont le statut n'accepte plus de participants (`closing`, `completed`) : `400` « This operation is no longer accepting participants ». Amende #262 R15.

### Terminer et partir

- **R6 — Terminer.** `POST /api/mining-operations/{id}/end` (corps vide) passe une opération `in_progress` à `closing`. Administration. `endedAt` = heure de l'horloge. Réponse `200`, l'opération. Statut autre que `in_progress` : `400` « Only an operation in progress can be ended ». Chaque personnage d'un membre `accepted` non parti, dont l'instantané de début est `taken`, reçoit un instantané `end` au statut `scheduling`, `referenceAt` = `endedAt`, et un message `TakeMiningLedgerSnapshot` sans délai.
- **R7 — Partir plus tôt (« Je pars »).** `POST /api/mining-operations/{id}/depart` (corps vide) : un membre `accepted` (propriétaire compris) d'une opération `in_progress` part. `departedAt` = heure de l'horloge ; sa membership reste `accepted` et garde son rôle. Ses personnages dont l'instantané de début est `taken` reçoivent un instantané `end` comme en R6, `referenceAt` = `departedAt`. Réponse `200`, l'opération. Refus : sans membership `accepted` `404` « Membership not found » ; statut autre que `in_progress` `400` « Only an operation in progress can be left early » ; déjà parti `400` « You already left this operation ». Un personnage sans instantané de début `taken` n'a pas d'instantané de fin.
- **R8 — Un départ n'est pas un retrait.** `POST …/leave` (#262 R16) reste réservé à une opération `planned` pour un membre `accepted`. Un participant parti garde ses quantités, compte dans `participantsCount` et ne peut plus repartir. Le propriétaire et les admins restent administrateurs après leur départ (R6).
- **R9 — La clôture reste une clôture.** #263 ne fait jamais passer une opération à `completed`. Une opération `closing` dont tous les instantanés de fin sont réglés (`isSettled()`) reste `closing` ; `endSnapshotsSettled` = `endSnapshotsTotal` le signale (R16). Les paiements, l'archive et `completed` viennent avec #265.

### Lecture d'un instantané (handler `TakeMiningLedgerSnapshotHandler`)

- **R10 — Lecture ESI.** `MiningLedgerReader::read(Character)` appelle `TokenManager::getValidAccessToken()` puis `EsiClient::getPaginatedWithExpiry('/characters/{eveCharacterId}/mining/', $token)` (toutes les pages). Chaque élément `{date, type_id, solar_system_id, quantity}` devient une ligne de l'instantané. Une liste vide est un ledger lu sans ligne, pas une erreur. Le résultat porte `expiresAt` (`PaginatedEsiResponse::$expiresAt`).
- **R11 — Heure de lecture de l'instantané de fin.** Un instantané `scheduling` est lu une première fois pour connaître l'expiration du cache ESI : `readyAt` = `expiresAt` + 5 s (`LEDGER_EXPIRY_MARGIN_SECONDS`), ou `referenceAt` + 600 s (`LEDGER_CACHE_SECONDS`) quand `expiresAt` est `null` ; statut `waiting` ; nouveau message `TakeMiningLedgerSnapshot` avec `DelayStamp(readyAt − maintenant)`. Les lignes de cette première lecture ne sont pas gardées : elles peuvent dater d'avant `referenceAt`.
- **R12 — Prise de l'instantané.** Un instantané `waiting` reçu avant `readyAt` est remis en file avec le délai restant, sans appel ESI. Reçu à `readyAt` ou après, il est lu (R10) : statut `taken`, `takenAt` = maintenant, `esiExpiresAt` = `expiresAt`, lignes enregistrées. Un instantané déjà réglé est ignoré (message rejoué), sans appel ESI.
- **R13 — Échecs.** Rien n'est rattrapé en silence, chaque échec est journalisé en `warning` avec le personnage et l'opération :
  - `EveAuthRequiredException`, ou `EsiApiException` de statut 401 ou 403 : `reconnect_required` ;
  - autre `EsiApiException` (5xx, 420/429 après le rejeu d'`EsiClient`, réseau) : `failed`, `failureReason` = message de l'exception ;
  - toute autre exception remonte à Messenger (rejeu du transport `async`) et l'instantané reste `scheduling` / `waiting`.
- **R14 — Fenêtre de 30 jours.** Le ledger ESI ne garde que 30 jours (`LEDGER_RETENTION_DAYS`). Un instantané de fin dont la date UTC de lecture dépasse de plus de 29 jours la date UTC de `startedAt` passe à `out_of_window` sans appel ESI. Borne exacte côté ESI : [À CONFIRMER : la ligne du 30e jour est-elle encore servie ?].
- **R15 — Relancer.** `POST /api/mining-operations/{operationId}/characters/{id}/snapshot-retry` (corps vide) relance l'instantané d'un personnage inscrit, par le propriétaire du personnage ou un administrateur de l'opération (sinon `403` « Only the character owner or an admin can retry its snapshot »). Réponse `204`. Relançable :
  - le début, dans une opération `in_progress`, s'il est `reconnect_required` ou `failed` : nouvel instantané `waiting`, `referenceAt` = `readyAt` = maintenant (le minage d'avant la relance n'est pas compté) ;
  - la fin, dans une opération `closing`, si elle est `reconnect_required` ou `failed`, ou `scheduling` / `waiting` avec un `readyAt` dépassé de plus de 5 min (`STALLED_SNAPSHOT_SECONDS`, message perdu) : statut `waiting`, `readyAt` = maintenant, lecture immédiate (le minage d'après la fin et jusqu'à la relance peut être compté) ;
  - sinon `400` « This snapshot cannot be retried ».

### Quantité minée

- **R16 — Calcul par personnage.** `MinedQuantityCalculator::between(start, end, ?solarSystemId)` :
  - pour chaque ligne de l'instantané de fin (date, type, système) : fin − début de la même clé ; une clé absente de l'instantané de début vaut 0 (le ledger lu est complet : pas de ligne = rien miné ce jour-là) ;
  - une clé présente au début et absente à la fin est ignorée (sortie de la fenêtre de 30 jours) ;
  - les différences sont sommées par type, toutes dates confondues (opération qui passe minuit UTC) ;
  - opération avec système : seules les lignes de ce système comptent ; sans système, toutes comptent ;
  - une différence négative rend la quantité du personnage **inconnue** (`ledger_inconsistent`) ; un type dont la somme vaut 0 n'est pas listé.
- **R17 — État d'un personnage.** Dans l'ordre :
  - pas d'instantané de début (membre accepté après le démarrage sans encore d'instantané) ou début `waiting` : `measuring` ;
  - début `reconnect_required` / `failed` / `out_of_window` : `unknown`, raison correspondante (`read_failed` pour `failed`) ;
  - opération `in_progress` et personnage non parti, ou fin `scheduling` / `waiting` : `measuring` ;
  - fin `reconnect_required` / `failed` / `out_of_window` : `unknown`, raison correspondante ;
  - fin `taken` : `known` (R16) ou `unknown` (`ledger_inconsistent`).
  Une quantité inconnue n'est jamais affichée ni sommée comme 0.
- **R18 — Participant.** Quantités d'un participant = somme par type de ses personnages. État : `unknown` si un personnage est `unknown` (après R20), sinon `measuring` si un personnage est `measuring`, sinon `known`. `totalQuantity` (unités, tous types) n'est renseigné que pour `known`.

### Correction

- **R19 — Corriger.** `POST /api/mining-operations/{operationId}/characters/{id}/quantity-corrections` avec `{typeId, quantity}` enregistre une correction : administration, opération `closing` seulement (`400` « Quantities can only be corrected while the operation is closing »), personnage qui n'est pas `measuring` (`400` « This quantity is still being measured »). `typeId` doit exister dans le SDE (`400` « Unknown type »). `quantity` entier ≥ 0 (`422`). Personnage d'une autre opération ou inconnu : `404` « Character not found ». Réponse `204`.
- **R20 — Effet d'une correction.** La dernière correction d'un (personnage, type) remplace la quantité mesurée de ce type. Un personnage `unknown` corrigé au moins une fois devient `known`, avec ses seuls types corrigés. Les corrections ne s'effacent pas ; une nouvelle correction s'ajoute à l'historique.
- **R21 — Traçabilité.** Chaque correction garde `originalQuantity` (quantité mesurée du type au moment de la correction : `null` si le personnage était `unknown`, 0 si mesurée sans ligne de ce type), l'auteur (`correctedBy`, `correctedByName` = son personnage principal) et `correctedAt`. Le ledger renvoie la dernière correction de chaque type avec ces champs.

### Ressources renvoyées

- **R22 — `MiningOperation`** (#262 R22) gagne `startedAt`, `endedAt`, `solarSystemId`, `solarSystemName`, `myDepartedAt` (`null` sans membership ou sans départ).
- **R23 — `MiningOperationMember`** (#262 R23) gagne `departedAt`.
- **R24 — `MiningOperationLedger`** (`GET …/ledger`, participation #262 R17) : `operationId`, `status`, `startedAt`, `endedAt`, `solarSystemId`, `solarSystemName`, `endSnapshotsReadyAt` (plus grand `readyAt` des instantanés de fin `waiting`, `null` s'il n'y en a pas), `endSnapshotsPending` (vrai s'il reste un instantané de fin `scheduling`), `endSnapshotsSettled`, `endSnapshotsTotal`, `participants` : `[{memberId, characterName, departedAt, state, totalQuantity, quantities: [{typeId, typeName, quantity}], characters: [{id, eveCharacterId, name, state, unknownReason, totalQuantity, startSnapshot, endSnapshot, canRetry, quantities: [{typeId, typeName, quantity, measuredQuantity, correction}]}]}]`. Un instantané : `{status, referenceAt, readyAt, takenAt}` ou `null`. Une correction : `{originalQuantity, correctedQuantity, correctedByName, correctedAt}` ou `null`. Participants : propriétaire puis `joinedAt` ; personnages par nom ; types par `typeName`. Membres `pending` absents. `canRetry` applique R15 pour l'utilisateur courant.

### Temps réel

- **R25 — Topic.** `getTopicsForUser()` ajoute `/user/{userId}/sync/mining-operation-snapshot` (17 topics). `CLAUDE.md`, section Mercure, ajoute `mining-operation-snapshot` aux types sync au moment du vert.
- **R26 — Progression par personnage.** Pour chaque instantané lu, le handler publie vers chaque utilisateur de `MiningOperationAudience::userIdsOf()`, syncType `mining-operation-snapshot`, avec `data` = `{operationId, operationCharacterId, characterName, kind, status, readyAt, settled, total}` (`settled`/`total` : instantanés réglés et attendus de ce `kind` dans l'opération) :
  - `syncStarted` avant l'appel ESI ;
  - `syncProgress` (progression `settled × 100 / total`) quand un instantané de fin reçoit son `readyAt` (R11) ;
  - `syncCompleted` quand il est `taken` ;
  - `syncError` quand il est `reconnect_required`, `failed` ou `out_of_window`.
  `syncStarted` et `syncError` reçoivent un paramètre facultatif `?array $data = null`, sans changement pour les appelants existants.
- **R27 — Événements d'opération.** `publishMiningOperationEvent()` (#262 R26) publie aussi `operation_started` (`{}`), `operation_ended` (`{}`), `member_departed` (`{memberId}`) et `quantity_corrected` (`{operationCharacterId, typeId}`).
- **R28 — Front.** La vue détail recharge l'opération, les membres et le ledger sur `operation_started`, `operation_ended`, `member_departed`, `quantity_corrected` de l'opération affichée, et recharge le ledger sur tout message `mining-operation-snapshot` dont `data.operationId` est l'opération affichée.

## 4. Endpoints API Platform

Toutes les opérations : `security: "is_granted('ROLE_USER')"`, `paginationEnabled: false`, préfixe `/api`, même exigence UUID sur `{id}` / `{operationId}` qu'en #262. Ordre des refus : `401`, `404` (via `UuidOrNotFound`), `403`, puis règles métier.

| Ressource | Opération | `uriTemplate` | `uriVariables` | Entrée | Provider / Processor | Réponse |
|---|---|---|---|---|---|---|
| `MiningOperationResource` | `Post` | `/mining-operations/{id}/start` | `id` → `Link(fromClass: self::class)` | `StartMiningOperationInput` (`?int $solarSystemId`, `Assert\Positive`) | `StartMiningOperationProcessor` | 200 (R1-R3) |
| | `Post` | `/mining-operations/{id}/end` | `id` → `Link(fromClass: self::class)` | `EmptyInput` | `EndMiningOperationProcessor` | 200 (R6) |
| | `Post` | `/mining-operations/{id}/depart` | `id` → `Link(fromClass: self::class)` | `EmptyInput` | `DepartMiningOperationProcessor` | 200 (R7) |
| `MiningOperationLedgerResource` (sans identifiant) | `Get` | `/mining-operations/{operationId}/ledger` | `operationId` → `Link(fromClass: MiningOperationResource::class)` | — | `MiningOperationLedgerProvider` | 200 (R24) |
| `MiningOperationCharacterResource` | `Post` | `/mining-operations/{operationId}/characters/{id}/snapshot-retry` | `operationId` → `Link(fromClass: MiningOperationResource::class)`, `id` → `Link(fromClass: self::class)` | `EmptyInput` | `RetryMiningLedgerSnapshotProcessor`, `output: false`, `status: 204` | 204 (R15) |
| | `Post` | `/mining-operations/{operationId}/characters/{id}/quantity-corrections` | idem | `CorrectMinedQuantityInput` (`int $typeId` `Assert\Positive`, `int $quantity` `Assert\PositiveOrZero`) | `CorrectMinedQuantityProcessor`, `output: false`, `status: 204` | 204 (R19) |

`{id}` de `MiningOperationCharacterResource` est l'identifiant de la ligne `MiningOperationCharacter` (renvoyé dans `MiningOperationLedger.participants[].characters[].id`), pas celui du `Character`.

Fichiers : `src/ApiResource/MiningOperation/` (deux ressources), `src/ApiResource/Input/MiningOperation/` (deux DTO), `src/State/Processor/MiningOperation/` (cinq processors), `src/State/Provider/MiningOperation/MiningOperationLedgerProvider.php`, `src/Message/TakeMiningLedgerSnapshot.php` (routé `async`), `src/MessageHandler/TakeMiningLedgerSnapshotHandler.php`. Règles dans les entités (`MiningOperation::start()/end()`, `MiningOperationMember::depart()`, `MiningLedgerSnapshot::schedule()/take()/fail()`) et dans `src/Service/MiningOperation/` : `MiningOperationLifecycle` (R1-R7, R15), `MiningLedgerReader` (R10), `MiningLedgerSnapshotTaker` (R11-R14, R26), `MinedQuantityCalculator` et `MinedQuantity` (R16-R18, R20), `MiningLedgerSnapshotDispatcher` (envoi des messages `TakeMiningLedgerSnapshot` après le `flush`, avec `DelayStamp`). Les processors restent minces.

## 5. Écrans (front)

Direction visuelle : celle de la maquette v4 et de #262 (cartes `slate-900`, Share Tech Mono pour les heures, badges). Toutes les heures sont en EVE time (UTC), au format `HH:MM`. Noms longs tronqués avec infobulle.

| Où | Contenu |
|---|---|
| Détail, `planned` | bouton « Démarrer l'opération » (administration) → modale : système facultatif (recherche `GET /api/sde/solar-systems?q=`, motif `EscalationFormModal`), aide « L'instantané de début du ledger de chaque personnage est pris au démarrage. Un inscrit en retard a son instantané à l'acceptation. », bouton « Démarrer ». Carte « Démarrage » : même aide |
| Détail, en-tête `in_progress` | méta « {système} · démarrée {HH:MM} EVE » ; bouton « Terminer l'opération » (administration) → modale `end` réduite : « Chaque personnage a son instantané de fin lu après l'expiration du cache ESI (10 min au plus). Ce qui est miné ensuite ne compte pas. », bouton « Terminer à {HH:MM} » ; bouton « Je pars » (membre `accepted` non parti) avec confirmation |
| Détail, inscrits `in_progress` | badge par participant : « ✓ début {n}/{m} » (vert), « ✗ début {n}/{m} » (ambre) s'il manque un début, « parti {HH:MM} · fin lue » / « parti {HH:MM} · fin à {HH:MM} » ; ligne ambre « {personnage} à reconnecter : instantané de début non lu, quantité inconnue » avec lien Personnages et bouton « Relancer » (R15) |
| Détail, `closing` | bandeau « En attente du ledger de fin » tant qu'un instantané de fin n'est pas lu : « L'opération est terminée à {HH:MM}. L'ESI met le ledger de minage en cache 10 min : l'instantané de fin de chaque personnage sera lu à {HH:MM}. Rien n'est estimé en attendant. » + compte à rebours vers `endSnapshotsReadyAt` (« calcul de l'heure de lecture… » tant que `endSnapshotsPending`). Puis carte « Lecture des instantanés de fin {settled} / {total} personnages ». Table « Instantanés de fin » : Personnage, Participant, Instantané de fin (« en file », « prêt à {HH:MM} », « ✓ lu {HH:MM} », « ✗ token invalide · quantité inconnue » + « Relancer », « ✗ lecture impossible », « ✗ hors des 30 jours du ledger »), Quantité (`inconnue` en ambre, « – » en mesure) |
| Détail, `closing` | carte « Quantités minées » : une ligne par participant (état, total) dépliable par personnage et par type ; marque « corrigé » avec infobulle « {auteur}, {HH:MM} · mesuré : {valeur ou inconnue} » ; bandeau ambre si un participant est `unknown` ; aide de filtre « Compté dans {système} seulement » ou « Compté dans tous les systèmes : le minage hors opération pendant la fenêtre est inclus » |
| Détail, `closing`, colonne droite | carte « Départ anticipé » (participants partis et heure) ; carte « Correction » (texte de la maquette) ; bouton « Corriger une quantité » (administration) → modale : personnage, type (liste des types mesurés dans l'opération, tous personnages confondus ; pas de recherche SDE dans ce lot : aucun endpoint de recherche de type générique n'existe, seul `/pve/search-types`), quantité, rappel de la valeur mesurée |

Store `stores/miningOperation.ts` : `start(id, solarSystemId)`, `end(id)`, `depart(id)`, `fetchLedger(id)`, `retrySnapshot(operationId, operationCharacterId)`, `correctQuantity(operationId, operationCharacterId, typeId, quantity)`. Le compagnon et son bouton « Je pars » sont hors périmètre : le bouton est dans la page détail.

### Clés i18n (fr / en)

| Clé | fr | en |
|---|---|---|
| `miningOperation.start.action` | Démarrer l'opération | Start operation |
| `miningOperation.start.title` | Démarrer « {label} » | Start “{label}” |
| `miningOperation.start.system` / `.systemNone` | Système (facultatif) / Tous les systèmes | Solar system (optional) / All systems |
| `miningOperation.start.hint` | L'instantané de début du ledger de chaque personnage est pris au démarrage. Un inscrit en retard a son instantané à l'acceptation. | Each character's start ledger snapshot is taken at start. A late participant gets theirs when accepted. |
| `miningOperation.start.submit` | Démarrer | Start |
| `miningOperation.running.meta` | {system} · démarrée {time} EVE | {system} · started {time} EVE |
| `miningOperation.end.action` | Terminer l'opération | End operation |
| `miningOperation.end.title` | Terminer l'opération ? | End the operation? |
| `miningOperation.end.hint` | Chaque personnage a son instantané de fin lu après l'expiration du cache ESI (10 min au plus). Ce qui est miné ensuite ne compte pas. | Each character's end snapshot is read once the ESI cache expires (10 min at most). Anything mined afterwards does not count. |
| `miningOperation.end.submit` | Terminer à {time} | End at {time} |
| `miningOperation.depart.action` / `.confirm` | Je pars / Votre instantané de fin sera pris maintenant. Vous ne pourrez pas revenir. | I'm leaving / Your end snapshot will be taken now. You cannot come back. |
| `miningOperation.snapshot.startOk` / `.startMissing` | ✓ début {taken}/{total} / ✗ début {taken}/{total} | ✓ start {taken}/{total} / ✗ start {taken}/{total} |
| `miningOperation.snapshot.departedRead` / `.departedWaiting` | parti {time} · fin lue / parti {time} · fin à {ready} | left {time} · end read / left {time} · end at {ready} |
| `miningOperation.snapshot.reconnectStart` | {name} à reconnecter : instantané de début non lu, quantité inconnue | {name} needs reconnecting: start snapshot not read, quantity unknown |
| `miningOperation.snapshot.retry` | Relancer | Retry |
| `miningOperation.snapshot.queued` / `.readyAt` / `.read` | en file / prêt à {time} / ✓ lu {time} | queued / ready at {time} / ✓ read {time} |
| `miningOperation.snapshot.reconnect` / `.failed` / `.outOfWindow` | ✗ token invalide · quantité inconnue / ✗ lecture impossible / ✗ hors des 30 jours du ledger | ✗ invalid token · quantity unknown / ✗ read failed / ✗ beyond the 30-day ledger |
| `miningOperation.closing.waitingTitle` | En attente du ledger de fin | Waiting for the end ledger |
| `miningOperation.closing.waitingText` | L'opération est terminée à {end}. L'ESI met le ledger de minage en cache 10 min : l'instantané de fin de chaque personnage sera lu à {ready}. | The operation ended at {end}. ESI caches the mining ledger for 10 min: each character's end snapshot will be read at {ready}. |
| `miningOperation.closing.nothingEstimated` | Rien n'est estimé en attendant. | Nothing is estimated in the meantime. |
| `miningOperation.closing.readIn` / `.computingReadyAt` | lecture dans / calcul de l'heure de lecture… | read in / working out the read time… |
| `miningOperation.closing.progress` | Lecture des instantanés de fin | Reading end snapshots |
| `miningOperation.closing.progressCount` | {settled} / {total} personnages | {settled} / {total} characters |
| `miningOperation.closing.snapshots` / `.snapshotsHint` | Instantanés de fin / ledger ESI par personnage · différence fin − début | End snapshots / ESI ledger per character · end minus start |
| `miningOperation.closing.columns.character` / `.participant` / `.endSnapshot` / `.quantity` | Personnage / Participant / Instantané de fin / Quantité | Character / Participant / End snapshot / Quantity |
| `miningOperation.closing.departures` / `.noDeparture` / `.departed` | Départ anticipé / Aucun départ sur cette opération. / {name} est parti à {time} : l'instantané de fin de ses personnages a été pris à son départ. | Early departure / Nobody left this operation early. / {name} left at {time}: their characters' end snapshots were taken when they left. |
| `miningOperation.quantities.title` | Quantités minées | Mined quantities |
| `miningOperation.quantities.unknown` / `.measuring` | inconnue / en mesure | unknown / measuring |
| `miningOperation.quantities.unknownBanner` | {name} a une quantité inconnue : elle n'est pas comptée comme 0. Un Owner ou un Admin peut la corriger. | {name} has an unknown quantity: it is not counted as 0. An owner or admin can correct it. |
| `miningOperation.quantities.systemFilter` / `.allSystems` | Compté dans {system} seulement / Compté dans tous les systèmes : le minage hors opération pendant la fenêtre est inclus | Counted in {system} only / Counted in every system: mining outside the operation during the window is included |
| `miningOperation.quantities.units` | {count} u | {count} u |
| `miningOperation.correction.action` / `.title` | Corriger une quantité / Corriger une quantité | Correct a quantity / Correct a quantity |
| `miningOperation.correction.card` | L'Owner ou un Admin peut corriger une quantité (alt oublié, minage hors site). La correction est tracée : qui, quand, valeur d'origine. | The owner or an admin can correct a quantity (forgotten alt, mining off site). The correction is tracked: who, when, original value. |
| `miningOperation.correction.character` / `.type` / `.quantity` / `.measured` / `.submit` | Personnage / Type / Quantité / Mesuré : {value} / Corriger | Character / Type / Quantity / Measured: {value} / Correct |
| `miningOperation.correction.corrected` / `.trace` | corrigé / {author}, {time} · mesuré : {value} | corrected / {author}, {time} · measured: {value} |

## 6. Exemples chiffrés (tests rouges)

Données communes : celles de #262 (Aria propriétaire d'O « Anomalie Spodumain — 20:00 », Brann `accepted` avec `Brann Holt` et `Brann II`). Systèmes S1 et S2, types Spodumain et Bistot : identifiants pris dans la fixture SDE des tests. Horloge : `MockClock`. Quantités en unités. Une ligne de ledger s'écrit `(date, type, système, quantité)`.

### Calcul (`MinedQuantityCalculator`, sans base)

| # | Début | Fin | Système d'O | Quantité attendue |
|---|---|---|---|---|
| Q1 (issue) | `(2026-11-05, Spodumain, S1, 12 000)` lu à 20:00 | `(2026-11-05, Spodumain, S1, 47 500)` lu à 21:42 | — | Spodumain 35 500 |
| Q2 (minuit) | `(2026-11-05, Spodumain, S1, 5 000)` lu le 05/11 à 23:00 | `(2026-11-05, Spodumain, S1, 9 000)`, `(2026-11-06, Spodumain, S1, 3 000)` lu le 06/11 à 01:40 | — | Spodumain 7 000 (4 000 + 3 000) |
| Q3 (nouveau type) | `(2026-11-05, Spodumain, S1, 12 000)` | `(2026-11-05, Spodumain, S1, 12 000)`, `(2026-11-05, Bistot, S1, 2 000)` | — | Bistot 2 000 ; Spodumain non listé |
| Q4 (filtre) | `(2026-11-05, Spodumain, S1, 12 000)` | `(2026-11-05, Spodumain, S1, 47 500)`, `(2026-11-05, Spodumain, S2, 8 000)` | S1 | Spodumain 35 500 |
| Q5 (sans filtre) | idem Q4 | idem Q4 | — | Spodumain 43 500 |
| Q6 (ledger vide au début) | aucune ligne | `(2026-11-05, Spodumain, S1, 6 000)` | — | Spodumain 6 000 |
| Q7 (rien miné) | `(2026-11-05, Spodumain, S1, 12 000)` | `(2026-11-05, Spodumain, S1, 12 000)` | — | `known`, aucune quantité, total 0 |
| Q8 (ligne sortie de la fenêtre) | `(2026-10-06, Bistot, S1, 900)`, `(2026-11-05, Spodumain, S1, 12 000)` | `(2026-11-05, Spodumain, S1, 47 500)` | — | Spodumain 35 500 ; Bistot ignoré |
| Q9 (incohérent) | `(2026-11-05, Spodumain, S1, 12 000)` | `(2026-11-05, Spodumain, S1, 11 000)` | — | `unknown`, `ledger_inconsistent` |

### État et agrégation

| # | Situation | Attendu |
|---|---|---|
| U1 | `Brann II` : début `reconnect_required` ; `Brann Holt` : Q1 | `Brann II` `unknown` (`reconnect_required`), `totalQuantity` `null` ; participant Brann `unknown`, `totalQuantity` `null` (jamais 35 500) |
| U2 | Brann : `Brann Holt` Q1, `Brann II` Q3 | participant `known`, Spodumain 35 500, Bistot 2 000, total 37 500 |
| U3 | O `in_progress`, début `taken`, personnage non parti | `measuring` |
| U4 | O `closing`, fin `waiting` | `measuring` |
| U5 | fin `failed` (502) | `unknown`, `read_failed` |
| U6 | U1 puis correction de `Brann II` Spodumain = 20 000 par Aria | `Brann II` `known`, Spodumain 20 000, correction `{originalQuantity: null, correctedQuantity: 20000, correctedByName: "Aria Solberg"}` ; Brann `known`, total 55 500 |
| U7 | `Brann Holt` Q1, correction Spodumain = 30 000, puis 33 000 | Spodumain 33 000, `measuredQuantity` 35 500, correction `{originalQuantity: 35500, correctedQuantity: 33000}` ; 2 corrections en base |
| U8 | `Brann Holt` Q1, correction Bistot = 1 000 | Spodumain 35 500, Bistot 1 000 (`originalQuantity` 0) |

### Cycle de vie (`POST …/start`, `…/end`, `…/depart`)

| # | Entrée | Sortie attendue |
|---|---|---|
| L1 | Aria, O `planned`, horloge 2026-11-05T20:00:00Z, `{solarSystemId: S1}` | `200`, `in_progress`, `startedAt` 20:00, `solarSystemName` de S1 ; 4 instantanés `start` `waiting` (Aria Solberg, Aria II, Brann Holt, Brann II) ; 4 messages sans `DelayStamp` |
| L2 | idem, `{solarSystemId: null}` | `200`, `solarSystemId` `null` |
| L3 | Aria, `{solarSystemId: 1}` | `400` « Unknown solar system » ; O reste `planned` |
| L4 | Brann `member` | `403` « Only the operation owner or an admin can perform this action » |
| L5 | Aria, O `in_progress` | `400` « Only a planned operation can be started » |
| L6 | Cyra `pending` au démarrage | pas d'instantané pour `Cyra Lune` |
| L7 | O `in_progress`, Aria accepte Cyra à 20:13 | instantané `start` de `Cyra Lune`, `referenceAt` 20:13, message envoyé |
| L8 | O `in_progress`, Brann2 (C1) rejoint à 20:20 (accepté d'office) | instantané `start` de ses personnages à 20:20 |
| L9 | O `closing`, Aria accepte Cyra | `400` « This operation is no longer accepting participants » |
| L10 | Aria, O `in_progress`, horloge 21:30:00 | `200`, `closing`, `endedAt` 21:30 ; un instantané `end` `scheduling` par personnage dont le début est `taken` ; messages sans délai |
| L11 | Aria, O `planned` | `400` « Only an operation in progress can be ended » |
| L12 | Brann `accepted`, O `in_progress`, horloge 21:05 | `200` ; `departedAt` 21:05 ; `Brann Holt` et `Brann II` : instantané `end` `scheduling`, `referenceAt` 21:05 ; `participantsCount` inchangé |
| L13 | Brann parti, de nouveau `…/depart` | `400` « You already left this operation » |
| L14 | Brann, O `closing` | `400` « Only an operation in progress can be left early » |
| L15 | Dorn sans membership | `404` « Membership not found » |
| L16 | L12 puis L10 | les instantanés de fin de Brann ne sont pas recréés |
| L17 | `Brann II` début `reconnect_required`, puis L10 | pas d'instantané `end` pour `Brann II` |
| L18 | Brann `accepted`, O `in_progress`, `POST …/leave` | `400` « Only a planned operation can be left » (#262 Q3 inchangé) |

### Lecture différée (handler, horloge simulée, ESI simulé)

| # | Situation | Sortie attendue |
|---|---|---|
| H1 | début `waiting`, `readyAt` 20:00, horloge 20:00:02, ESI 2 pages (`X-Pages: 2`) | `taken`, `takenAt` 20:00:02, lignes des deux pages ; `syncStarted` puis `syncCompleted` vers Aria et Brann |
| H2 (cache pas expiré) | fin `scheduling`, `referenceAt` 21:30:00, horloge 21:30:01, ESI `Expires: Thu, 05 Nov 2026 21:37:12 GMT` | `waiting`, `readyAt` 21:37:17, lignes non gardées ; message renvoyé avec `DelayStamp(436 000)` ; `syncProgress` avec `readyAt` |
| H3 | H2, message reçu à 21:35:00 | renvoyé avec `DelayStamp(137 000)`, aucun appel ESI |
| H4 | H2, message reçu à 21:37:17, ESI `(2026-11-05, Spodumain, S1, 47 500)` | `taken`, Q1 avec un début à 12 000 → 35 500 |
| H5 | fin `scheduling`, réponse sans `Expires` | `readyAt` = `referenceAt` + 600 s = 21:40:00 |
| H6 | `TokenManager` lève `EveAuthRequiredException` | `reconnect_required` ; `syncError` ; `warning` journalisé ; pas d'exception remontée |
| H7 | ESI 403 | `reconnect_required` |
| H8 | ESI 502 | `failed`, `failureReason` renseigné ; `syncError` |
| H9 | exception Doctrine pendant l'enregistrement | remontée à Messenger ; instantané toujours `waiting` |
| H10 | message rejoué sur un instantané `taken` | aucun appel ESI, rien de publié |
| H11 (30 jours) | `startedAt` 2026-10-05T20:00Z, fin lue le 2026-11-04 (30 jours après) | `out_of_window` sans appel ESI ; quantité `unknown` (`out_of_window`) |
| H12 | `startedAt` 2026-10-06T20:00Z, fin lue le 2026-11-04 (29 jours) | lue normalement |

### Relance et correction

| # | Entrée | Sortie attendue |
|---|---|---|
| R-1 | Brann, O `in_progress`, `Brann II` début `reconnect_required`, horloge 20:40 | `204` ; début `waiting`, `referenceAt` 20:40 ; message envoyé |
| R-2 | Cyra (`pending`, pas propriétaire du personnage), même requête | `403` « Only the character owner or an admin can retry its snapshot » |
| R-3 | Aria, début `taken` | `400` « This snapshot cannot be retried » |
| R-4 | Aria, O `closing`, fin `failed` | `204`, fin `waiting`, `readyAt` = maintenant |
| R-5 | Aria, O `closing`, fin `waiting` avec `readyAt` dépassé de 6 min | `204` |
| R-6 | Aria, O `closing`, fin `waiting` avec `readyAt` dans 3 min | `400` « This snapshot cannot be retried » |
| C-1 | Aria, O `closing`, U6 | `204`, U6 |
| C-2 | Brann `member` | `403` « Only the operation owner or an admin can perform this action » |
| C-3 | Aria, O `in_progress` | `400` « Quantities can only be corrected while the operation is closing » |
| C-4 | Aria, fin `waiting` | `400` « This quantity is still being measured » |
| C-5 | Aria, `quantity: -1` | `422`, violation `quantity` |
| C-6 | Aria, `typeId` absent du SDE | `400` « Unknown type » |
| C-7 | Aria, personnage d'une autre opération | `404` « Character not found » |

### Ledger (`GET …/ledger`)

| # | Entrée | Sortie attendue |
|---|---|---|
| G1 | Aria, O `closing` à 21:31, 4 fins `waiting` (`readyAt` 21:37:17 à 21:39:50) | `endSnapshotsReadyAt` 21:39:50, `endSnapshotsSettled` 0, `endSnapshotsTotal` 4, tous `measuring` |
| G2 | idem avec un instantané encore `scheduling` | `endSnapshotsPending` vrai |
| G3 | 4 fins `taken` | `endSnapshotsSettled` 4 ; O toujours `closing` |
| G4 | Cyra `pending` | `403` « You do not have access to this operation » ; Cyra absente des participants vus par Aria |
| G5 | O `planned` | `200`, participants listés, aucun instantané, tous `measuring` |

### Temps réel

| # | Entrée | Sortie attendue |
|---|---|---|
| T1 | `getTopicsForUser('user-uuid-123')` | contient `/user/user-uuid-123/sync/mining-operation-snapshot` ; 17 topics |
| T2 | L1 | `operation_started` vers Aria et Brann |
| T3 | L12 | `member_departed` `{memberId: Brann}` vers Aria et Brann |
| T4 | H1 | sur `/user/{Aria}/sync/mining-operation-snapshot` : `started` puis `completed`, `data.operationId` = O, `kind` `start`, `settled` 1, `total` 4 |
| T5 | Front : détail d'O ouvert, message `mining-operation-snapshot` pour O puis pour P2 | `fetchLedger(O)` appelé une fois |

### Front

| # | Entrée | Sortie attendue |
|---|---|---|
| F1 | détail `planned`, Aria (owner) | bouton « Démarrer l'opération » ; Brann `member` ne le voit pas |
| F2 | modale de démarrage, système choisi S1 | `start(O, S1)` ; sans choix : `start(O, null)` |
| F3 | détail `in_progress`, Brann non parti | boutons « Je pars » ; pas de « Terminer l'opération » pour Brann |
| F4 | participant avec `Brann II` début `reconnect_required` | badge « ✗ début 1/2 », ligne « Brann II à reconnecter… », bouton « Relancer » |
| F5 | `closing`, `endSnapshotsReadyAt` 21:39:50 | bandeau « En attente du ledger de fin », « lu à 21:39 », compte à rebours |
| F6 | `closing`, U1 | quantité `inconnue` pour `Brann II` et Brann, bandeau ambre, jamais « 0 » |
| F7 | correction U7 | marque « corrigé », infobulle « Aria Solberg, {heure} · mesuré : 35 500 » |
| F8 | textes en fr puis en en | uniquement les clés i18n de la locale (`expectOnlyLocaleTexts`) |

## 7. Cas limites

- **Cache ESI au démarrage.** L'instantané de début lu à 20:00 peut venir d'un cache rempli jusqu'à 10 min plus tôt : le minage des 10 min qui précèdent le démarrage peut être compté. Accepté (les mineurs commencent au démarrage) ; pas d'attente au démarrage, qui ferait perdre du minage de l'opération.
- **Cache ESI à la fin.** Jamais lu avant l'expiration du cache en place à la fin (R11) : le minage des dernières minutes est compté. Le minage entre la fin et l'heure de lecture (10 min au plus) l'est aussi ; la modale de fin le dit.
- **Token invalide.** Début non lu : quantité inconnue, personnage « à reconnecter », relance possible tant que l'opération est en cours (minage d'avant la relance perdu). Fin non lue : relance en clôture, ou correction.
- **Personnage sans début, membre parti, fin demandée.** Pas d'instantané de fin ; quantité inconnue jusqu'à une correction.
- **Retrait en cours d'opération (décision de l'orchestrateur, nuit).** Une fois l'opération sortie de Planifiée, le retrait d'un membre par un administrateur (#262 R14) est refusé (409 `Conflict`, « Mining operation already started: use departure instead »). Aucun instantané ni aucune correction n'est donc supprimé par un retrait ; le participant qui s'en va passe par « Je pars » (`POST …/depart`). Le retrait reste possible tant que l'opération est Planifiée (aucun instantané n'existe encore). Exemple de test : Aria (Owner) retire Brann d'une opération En cours → 409, Brann reste `accepted`, ses instantanés sont intacts.
- **Personnage supprimé du compte, compte supprimé.** Cascade : ses instantanés et corrections disparaissent. Correction d'un auteur supprimé : `correctedBy` `null`, `correctedByName` conservé.
- **Opération en clôture depuis plus de 30 jours.** Les instantanés de fin encore non lus passent à `out_of_window` à leur lecture ; la relance aussi.
- **Deux personnages du même compte, même ledger ?** Non : un ledger par personnage.
- **Message différé perdu** (worker arrêté, file purgée). L'instantané reste `waiting` ; la relance est ouverte 5 min après `readyAt` (R15).
- **Opération sans participant qui démarre.** Impossible : le propriétaire a au moins un personnage (#262 R1).
- **Démarrage avant ou après `plannedStartAt`.** Autorisé ; `plannedStartAt` reste l'heure annoncée.

## 8. Hors périmètre

- Statut `completed`, paiements, marquage payé, archive, récapitulatif (#265) ; valorisation, base de valorisation, parts, part du booster, part corporation (#264) ; fleet storage (#266) ; sites et scans, ISK/h indicatif pendant l'opération, répartition sur les roches (#267) ; temps pondéré (#270).
- Suivi au cycle de minage (impossible, cache ESI de 600 s).
- Compagnon (fenêtre détachée, « Je pars » depuis le compagnon, alerte « ledger prêt »), « Annuler » un départ, présence en ligne / AFK.
- Choix du système à la création ou modification ensuite ; plusieurs systèmes par opération.
- Mise à jour de `MiningEntry` depuis les instantanés, ou extraction de la lecture ESI de `MiningSyncService`.
- Vaisseau par personnage (colonne « Vaisseau » de la maquette).
- Notification in-app ou push quand le ledger de fin est prêt.

## 9. Termes du glossaire

Utilisés tels quels (section Minage, PR #326) : **Opération de minage**, **Participant**, **Ligne du ledger de minage**, **Instantané du ledger** (`MiningLedgerSnapshot`), **Quantité minée d'une opération** (`MinedQuantity`, inconnue jamais 0).

Termes proposés, à ajouter au glossaire après validation (non ajoutés ici) :

| Terme FR | Term EN | Définition proposée | Symbole(s) |
|---|---|---|---|
| **Instantané de début** | Start snapshot | Instantané du ledger d'un personnage inscrit, pris au démarrage de l'opération ou à l'acceptation d'un inscrit en retard. | `MiningLedgerSnapshotKind::Start` |
| **Instantané de fin** | End snapshot | Instantané du ledger d'un personnage inscrit, lu après l'expiration du cache ESI en place à la fin de l'opération ou au départ anticipé de son participant. | `MiningLedgerSnapshotKind::End` |
| **Heure de lecture** | Ledger ready time | Heure à partir de laquelle l'instantané de fin d'un personnage peut être lu : expiration du cache ESI en place à la fin, plus une marge. | `MiningLedgerSnapshot::$readyAt` |
| **Départ anticipé** | Early departure | Fin de l'opération pour un seul participant (« Je pars ») : ses instantanés de fin sont pris, il reste participant et garde ses quantités. | `MiningOperationMember::depart()`, `$departedAt` |
| **Correction de quantité** | Quantity correction | Quantité d'un type fixée par un Owner ou un Admin pour un personnage, à la place de la quantité mesurée, tracée avec son auteur, son heure et la valeur d'origine. | `MiningQuantityCorrection` |

## 10. Décisions prises en autonomie

| # | Décision | Alternative écartée | Raison |
|---|---|---|---|
| 1 | Heure de lecture de la fin = `Expires` d'une première lecture à la fin + 5 s (repli : fin + 600 s) | attendre toujours fin + 600 s | L'issue demande « après l'expiration du cache, en-tête `Expires` » ; l'attente réelle est souvent inférieure à 10 min, et l'heure affichée est exacte. Coût : un appel ESI de plus par personnage. |
| 2 | Les lignes de la première lecture de fin sont jetées | les garder si `Expires` ≈ maintenant + 600 s (cache tout juste rempli) | Sans `Last-Modified` dans `EsiClient`, on ne sait pas si elles datent d'avant la fin. |
| 3 | Instantané de début lu tout de suite, sans attente | attendre l'expiration du cache au démarrage | Attendre ferait perdre le minage du début de l'opération ; l'excès possible (10 min avant le démarrage) est documenté. |
| 4 | Lecture asynchrone par personnage (`TakeMiningLedgerSnapshot`, `DelayStamp` sur AMQP) | lecture dans la requête HTTP | 20 personnages × pages ESI dépasseraient le temps d'une requête ; la progression Mercure par personnage le suppose. Premier `DelayStamp` du projet. |
| 5 | Nouveau `MiningLedgerReader` sur `getPaginatedWithExpiry()`, `MiningSyncService` inchangé | extraire la lecture de `MiningSyncService` (piste de l'issue) | `MiningSyncService` est couvert par trois fichiers de tests qui simulent `getPaginated()` ; l'extraction les casserait pour un gain nul dans ce lot. |
| 6 | Instantané = copie complète du ledger lu (≤ 30 jours) dans `mining_ledger_snapshot_lines` | ne garder que les lignes depuis `startedAt` ; ou réutiliser `MiningEntry` | La copie rend le calcul indépendant de la sync Revenus › Minage et règle le passage de minuit et les mises à jour tardives du jour précédent sans cas particulier. |
| 7 | Clé absente du début = 0, clé absente de la fin ignorée, différence négative = inconnue | refuser toute clé absente | Le ledger lu est complet (toutes les pages), donc une ligne absente est un 0 ESI, pas une donnée manquante ; une baisse est une incohérence ESI à signaler. |
| 8 | Système choisi au démarrage (facultatif), figé | système à la création, modifiable | #262 a écarté le système de la création ; le démarrage est le moment où l'organisateur sait où il mine, sans endpoint de modification. |
| 9 | « Je pars » = `POST …/depart`, distinct de `…/leave` | étendre `leave` à l'opération en cours | `leave` retire le membre et libère ses personnages ; un départ garde le participant et ses quantités. |
| 10 | Le propriétaire et les admins peuvent partir et gardent leurs droits | interdire le départ au propriétaire | Un propriétaire qui part doit encore pouvoir terminer l'opération ou corriger. |
| 11 | Inscrit en retard : début pris à l'acceptation ; plus d'acceptation en clôture | début au démarrage pour tous, ou acceptation en clôture sans quantité | Fidèle à la maquette (« instantané pris à l'acceptation ») ; accepter en clôture créerait un participant sans mesure possible. |
| 12 | Correction par (personnage, type), en clôture seulement, historique conservé | correction par participant ; correction aussi en cours | La quantité mesurée est par personnage et par type ; la maquette ne montre « Corriger » qu'en clôture. Retirer une correction est hors périmètre (on corrige de nouveau). |
| 13 | Relance par le propriétaire du personnage ou un administrateur ; le début se relance en cours d'opération en perdant le minage d'avant | pas de relance du début | La maquette affiche « Relancer » à côté du début non lu ; le pilote se reconnecte puis relance lui-même. |
| 14 | `EveAuthRequiredException` et 401/403 → `reconnect_required`, autres `EsiApiException` → `failed`, le reste remonte à Messenger | tout attraper ; ou tout laisser à Messenger | Chaque échec ESI connu donne un état visible ; une erreur inattendue reste visible dans le transport `failed` et la relance (5 min) débloque le cas. |
| 15 | Fenêtre de 30 jours comptée en dates UTC, au plus 29 jours d'écart | pas de contrôle (ESI renverrait un ledger tronqué) | Un ledger tronqué donnerait une quantité fausse mais plausible ; borne à confirmer en jeu. |
| 16 | Quantités calculées à la lecture du ledger, jamais stockées | colonne de quantité par personnage | Une seule source (les instantanés et corrections) ; #264 décidera s'il faut figer. |
| 17 | Topic `/user/{id}/sync/mining-operation-snapshot` (syncType unique), `data.operationId` pour router | un topic par opération | Même mécanisme que les autres syncs (`stores/sync.ts` range par `syncType`), aucun changement de `TokenProvider`. |
| 18 | Ressource `MiningOperationLedger` unique (participants → personnages → types) | deux ressources (par participant, par personnage) | L'écran les montre ensemble ; l'état du participant (R18) reste une règle serveur. |
| 19 | Pas de nouvelle maquette : la v4 fait foi, écrans réduits à ce lot | refaire une maquette #263 | Même choix que #262 (décision 21) : une seconde version risquerait de diverger de la maquette retenue. |
| 20 | Horloge `Psr\Clock\ClockInterface` injectée dans le cycle de vie, le handler et le dispatcher ; les entités reçoivent l'heure en paramètre | `new \DateTimeImmutable()` dans les entités (#262) | Toutes les règles de ce lot dépendent de l'heure ; #262 n'en avait aucune. |
| 21 | Modale de correction limitée aux types déjà mesurés dans l'opération ; l'API accepte tout `typeId` du SDE | ajouter une recherche de type SDE | Aucun endpoint de recherche de type générique (`/pve/search-types` est propre au PVE) ; le cas courant est la correction d'un type mesuré. |
| 22 | Retrait d'un membre refusé hors Planifiée (409), départ via « Je pars » | supprimer ses instantanés avec lui (proposition initiale de l'architecte) | Ne supprime aucune donnée ; décision de l'orchestrateur en mode nuit, à relire par Sylvain. |

# Spec #364 — Minage groupé : vaisseau de chaque personnage d'une opération

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-04, à valider par Sylvain.
- **Circuit** : `circuit:m`, sans note de câblage. **Dépend de** : #263 (instantanés, fermée), #311 (coques de boost, fermée), #317 (saison, lot 1 dans main).
- **Maquette** : `mockups/grouped-mining-v4.html` (étape 20, `renderSeason`, tableau « ISK/h par vaisseau », `shipRows` ; colonne « Vaisseau » de `snapshotRows`). Direction HUD EVE, noms longs à tester.
- **Périmètre interdit** : Industry, Group Industry, Stockpile. Hors lot : sites et « meilleurs sites par système » (#267, #305).

## 1. Modèle

Une migration : `mining_operation_characters` + `ship_type_id` integer nullable. `null` = vaisseau inconnu (jamais lu, illisible, ou opération antérieure à ce lot : aucun rattrapage). Aucun vaisseau par défaut. Nouvelle méthode de l'entité `MiningOperationCharacter::recordShip(?int)` et `getShipTypeId(): ?int`.

## 2. Règles métier

- **R1 — Moment de la lecture.** Le vaisseau est lu une seule fois, quand l'instantané **de début** du personnage est pris (`MiningLedgerSnapshotTaker::take`, chemin final `Taken` d'un instantané `Start`), jamais pour un instantané de fin ni pendant la phase `Scheduling`. Un changement de vaisseau en cours d'opération n'est pas vu. Un nouvel essai d'instantané de début (`RetryMiningLedgerSnapshotProcessor`) relit le vaisseau et remplace la valeur.
- **R2 — Appel ESI.** `GET /characters/{id}/ship/` via `EsiClient` (jeton du personnage, budget d'erreurs 420 et cache gérés par le client) dans un lecteur dédié `MiningCharacterShipReader::read(Character): ?int` qui rend `ship_type_id`. Réponse sans `ship_type_id` entier : `null`. Jeton absent, `EveAuthRequiredException`, toute `EsiApiException` : `null`.
- **R3 — Illisible = inconnu, jamais bloquant.** Un échec du lecteur n'échoue pas l'instantané : il se règle `Taken` normalement, le vaisseau reste `null` et un avertissement est journalisé (opération, personnage, raison). Le personnage reste compté dans les heures et la quantité.
- **R4 — Lecture API.** Chaque personnage de `MiningOperationLedgerResource.participants[].characters[]` gagne `shipTypeId` (`?int`) et `shipName` (`?string`, nom SDE via `TypeNameResolver`, `null` si `shipTypeId` est `null`). Clés `null` présentes. Mêmes droits qu'aujourd'hui (participants).
- **R5 — Booster.** Un vaisseau dont le type est une valeur de `MiningBoostShip` (Porpoise, Orca, Rorqual) est un vaisseau de booster : il ne mine pas, sans valeur ni ISK/h.
- **R6 — Saison par vaisseau.** `MiningSeasonSummary` gagne `ships` et `unknownShipCharactersCount`. Sont comptés les personnages des opérations **connues** du périmètre (parts gelées, R3 de #317) ayant un instantané de début lu (`takenAt` non nul) :
  - minutes d'un personnage = de `takenAt` de son instantané de début à `departedAt` du membre s'il existe, sinon `endedAt` de l'opération, jamais négatif ;
  - valeur d'un personnage = `MiningCharacterValuation::minedValue` du valorisateur sur l'opération gelée (prix gelés) ; une valeur `null` sur une opération gelée est une `LogicException` (le gel exige quantités et prix connus) ;
  - personnage sans vaisseau connu : exclu de `ships`, compté dans `unknownShipCharactersCount` (une fois par opération et par personnage) ;
  - ligne par type de vaisseau : `shipTypeId`, `charactersCount` (personnages distincts, par `eveCharacterId`, sur la période), `minutes` (somme), `minedValue` (somme), `iskPerHour` = `round(minedValue × 60 / minutes)` (0,5 vers le haut), `isBooster` ;
  - ligne booster (R5) : `minedValue` et `iskPerHour` `null`, `isBooster` vrai, ses minutes comptées ;
  - `iskPerHour` `null` si `minutes` vaut 0 ;
  - ordre : `iskPerHour` décroissant (null en dernier), puis `shipTypeId` croissant ;
  - opération inconnue (non gelée) : ses personnages n'entrent ni dans `ships` ni dans `unknownShipCharactersCount` (leur valeur est inconnue ; les heures du joueur restent comptées par #317).
- **R7 — Calcul pur.** `MiningSeasonOperation` gagne en dernier paramètre `characters` (défaut `[]`, pour ne pas casser les tests existants) : liste de `MiningSeasonCharacter {characterKey: string, ?int shipTypeId, int minutes, ?int minedValue}`. `MiningSeasonCalculator::summarise` produit `ships`. Le calcul ne connaît pas `MiningBoostShip` : le lecteur renseigne `isBooster` dans `MiningSeasonCharacter` (champ `isBooster: bool`). `MiningSeasonSummary` gagne `ships` et `unknownShipCharactersCount` en derniers paramètres avec défauts `[]` et `0`.
- **R8 — Saison, API.** `GET /api/mining-operations/season` gagne `ships: [{shipTypeId, shipName, charactersCount, minutes, minedValue, iskPerHour, isBooster}]` et `unknownShipCharactersCount`. `shipName` résolu par `MiningSeasonProvider` (`TypeNameResolver`). Droits et périmètre inchangés (corporation de l'utilisateur).
- **R9 — Temps réel.** Aucune nouvelle action asynchrone : la lecture du vaisseau s'exécute dans l'instantané de début, déjà publié sur `mining-operation-snapshot` par `MiningLedgerSnapshotTaker` (`syncCompleted`, topic déclaré dans `MercurePublisherService::getTopicsForUser()`). Le message de données gagne `shipTypeId` pour un instantané de début (`null` si inconnu) ; le front recharge le ledger comme il le fait déjà sur cet événement.

## 3. Exemples chiffrés (G en ISK, minutes par personnage)

| # | Opérations connues | Résultat `ships` |
|---|---|---|
| 1 | A : Hulk-1 120 min 70 M, Hulk-2 120 min 50 M, Skiff-1 90 min 20 M | Hulk : 2 persos, 240 min, 120 M, 30 000 000/h ; Skiff : 1 perso, 90 min, 20 M, 13 333 333/h (ordre : Hulk, Skiff) |
| 2 | Ex. 1 + B : Hulk-1 60 min 30 M | Hulk : 2 persos (Hulk-1 compté une fois), 300 min, 150 M, 30 000 000/h |
| 3 | A : Orca-1 120 min, Hulk-1 120 min 40 M | Hulk 20 000 000/h en tête ; Orca : 1 perso, 120 min, valeur `null`, ISK/h `null`, `isBooster` vrai, en dernier |
| 4 | A : Hulk-1 120 min 40 M, personnage sans vaisseau 120 min 30 M | `ships` = Hulk seul ; `unknownShipCharactersCount` 1 |
| 5 | Une opération inconnue avec Hulk-1 | `ships` `[]`, `unknownShipCharactersCount` 0 |
| 6 | Personnage sans instantané de début lu | absent de `ships` et du compteur |
| 7 | Hulk-1 7 min, 1 000 000 | 8 571 429/h |
| 8 | Personnage parti à 50 min d'une opération de 120 min | 50 min |
| 9 | Hulk-1 0 minute (parti à l'instant de l'instantané), 5 M | `iskPerHour` `null`, valeur 5 M comptée |
| 10 | Aucune opération | `ships` `[]`, compteur 0 |

Lecture du vaisseau : `ship_type_id` 22544 (Hulk) → `shipTypeId` 22544 ; réponse ESI 403 → `null`, instantané `Taken` quand même, avertissement journalisé ; réponse `{}` → `null` ; instantané de fin → aucun appel `/ship/` ; instantané en `Scheduling` → aucun appel ; retry de l'instantané de début avec un nouveau vaisseau → valeur remplacée.

## 4. Écrans (front)

- **Liste des participants (ledger)** : chaque personnage affiche son vaisseau (`shipName`) à côté de son nom (colonne « Vaisseau » de la maquette, étape instantanés) ; `shipName` `null` : « Vaisseau inconnu » (clé i18n), jamais un nom par défaut. Noms longs à la ligne (`overflow-wrap:anywhere`).
- **Saison** (`MiningSeasonPanel.vue`) : bloc « ISK/h par vaisseau » à gauche du tableau des joueurs : colonnes Vaisseau, Persos, Heures, Valeur, ISK/h, barre proportionnelle à l'ISK/h le plus haut (comme les joueurs). Ligne booster : valeur « – » et mention « booster, ne mine pas », sans barre. Sous le tableau, si `unknownShipCharactersCount > 0` : « N personnages sans vaisseau connu, hors de ce tableau ». `ships` vide : état vide, pas de faux tableau. i18n `en.json` et `fr.json`, termes du glossaire.

## 5. Cas limites

- Personnage inscrit en cours d'opération : son vaisseau est lu à son propre instantané de début.
- Opérations passées (avant la migration) : `ship_type_id` `null`, vaisseau inconnu, leurs personnages comptés dans `unknownShipCharactersCount` si l'opération est connue et l'instantané de début lu.
- Personnage dans un Orca qui mine : traité en booster (R5), décision produit de l'issue.
- Vaisseau absent du SDE (`shipName` introuvable) : `TypeNameResolver` rend son repli habituel, pas de blocage.

## 6. Décisions prises en autonomie

1. Lecture unique au début (issue : « à confirmer ») ; relecture à la fin non retenue (coût ESI, un seul état par personnage).
2. Aucune nouvelle colonne d'état : `null` suffit pour « inconnu », la cause va au journal (R3).
3. Le calcul par vaisseau utilise les valeurs figées par personnage du valorisateur, pas une nouvelle donnée stockée.
4. Niveau 2 attendu : migration, nouvel appel ESI (scope déjà demandé, aucun nouveau scope), ressource saison enrichie.

## 7. Glossaire (à ajouter)

| FR | EN | Définition |
|---|---|---|
| **Vaisseau d'un personnage** | Character ship | Type de vaisseau lu à l'ESI à l'instantané de début d'un personnage inscrit ; inconnu si illisible, jamais un défaut. |
| **ISK/h par vaisseau** | ISK per hour by ship | Valeur figée des personnages d'un type de vaisseau sur la saison, divisée par leurs heures de présence ; vaisseau de booster sans valeur. |

## 8. Fichiers

`migrations/` (une), `src/Entity/MiningOperationCharacter.php`, `src/Service/MiningOperation/MiningCharacterShipReader.php`, `MiningLedgerSnapshotTaker.php`, `MiningSeasonCharacter.php`, `MiningSeasonOperation.php`, `MiningSeasonCalculator.php`, `MiningSeasonSummary.php`, `MiningSeasonReader.php`, `src/State/Provider/MiningOperation/MiningOperationLedgerProvider.php`, `MiningSeasonProvider.php`, `src/ApiResource/MiningOperation/MiningSeasonResource.php` ; `frontend/src/stores/miningOperation.ts`, `components/mining-operation/MiningStartSnapshots.vue` (ou le composant qui liste les personnages du ledger), `views/MiningOperation/MiningSeasonPanel.vue`, `miningOperationSeason.ts`, `en.json`, `fr.json`, `docs/glossary.md`.

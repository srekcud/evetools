# #916 lot correctif rouge backend (après revue)

Aucun commit. `src/` : un seul squelette (voir plus bas). Base isolée `eve_app_test916` créée (database:create + migrations:migrate, 166 migrations) puis supprimée.

## Squelette ajouté dans src/

`CharacterWalletReadingRepository::record(Character $character, ?\DateTimeImmutable $readAt, \DateTimeImmutable $nextReadAt): void`, corps vide. Sans lui, `createMock(CharacterWalletReadingRepository::class)` refuse de configurer `record`.

## Contrat de `record()` (imposé par les tests)

- Upsert natif, une seule instruction SQL sur `character_wallet_reading` : `INSERT INTO character_wallet_reading (...) VALUES (...) ON CONFLICT (character_id) DO UPDATE SET ...` (le test regarde la requête exécutée : une requête, motif `INSERT INTO character_wallet_reading ... ON CONFLICT (character_id) DO UPDATE`). Colonnes : `id` (UUID à générer, pas de défaut en base), `character_id`, `read_at`, `next_read_at`.
- `next_read_at` est toujours écrasé par `$nextReadAt`.
- `read_at` : `$readAt` s'il n'est pas null, sinon la valeur existante conservée (`COALESCE(EXCLUDED.read_at, character_wallet_reading.read_at)`). Premier enregistrement avec `readAt` null : ligne créée avec `read_at` NULL.
- Les dates sont écrites telles quelles (`format('Y-m-d H:i:s')`), les tests passent des instants UTC.
- Le personnage doit déjà être flushé (FK). Pas de flush de l'EntityManager nécessaire pour la lecture : l'upsert part directement sur la connexion. Je n'asserte donc pas de `flush` pour l'écriture de la lecture (constat 2a : la lecture est enregistrée après succès et après échec, vérifié par l'appel à `record`). Le `flush` des transactions dans la boucle est inchangé.
- Le service ne doit plus appeler `findOneByCharacter` ni `persist(CharacterWalletReading)` (le test unitaire le vérifie).
- Vérification de satisfiabilité : j'ai posé temporairement une implémentation (INSERT ... ON CONFLICT, UUID v7) : les 10 tests d'intégration passent (19 assertions). Squelette remis ensuite.

## Tests (rouge = échoue sur le code actuel)

Constat 1, déclencheur manuel (déjà verts, garde-fous contre mutant : un `new SyncWalletTransactions()` avec `dueOnly` à true par défaut, ou le retrait de la branche `wallet` de `messagesFor`, les ferait passer au rouge) :
- `TriggerableSyncsTest::testWalletDispatchesItsMessageForEveryCharacterWhateverTheirNextReadAt` (`assertEquals`).
- `SyncWalletTransactionsHandlerTest::testManualTriggerQueuesEveryCharacterWithTheWalletScopeEvenWhenNotDue` (dû, pas dû, retry en cours, jamais lu, sans scope, sans token ; ordre du repository). `queuedCharacterIds` reçoit un message optionnel.
- `TriggerSyncProcessorTriggerSyncTest` : `testTriggeringAWalletSyncDispatchesItForEveryCharacter...` (type `wallet`) et `testTheLegacyWalletActionDispatches...` (action `sync_wallet`).

Constat 2, `tests/Unit/Service/Sync/WalletTransactionSyncServiceTest.php` (28 tests, 13 rouges) :
- (a) `testSuccessfulRead...`, `testReadOfZeroTransactions...`, `testFailedReadRecordsNoReadAt...`, `testFailureOnTheSecondPage...` : attendent un appel `record` avec les dates exactes (`readAt` null sur échec). Rouge : `record` jamais appelé.
- (b) `testFailureToRecordASuccessfulReadPublishesSyncErrorAndNeverSyncCompleted` : rouge, l'exception de la base remonte sans `syncError`.
- (c) `testFailureToRecordTheFailedReadKeepsTheOriginalEsiErrorAndPublishesSyncError` : message d'erreur publié = `ESI timeout`. Rouge : l'exception de la base remonte, aucun `syncError`. Pour que le test échoue aussi sur le code actuel, `databaseGoesDown()` fait lever à la fois `record` et `EntityManager::flush`. Le test n'impose pas que le service avale l'exception : si elle remonte, elle doit être celle d'ESI.
- (d) `testNewPurchasesAreLinkedBeforeSyncCompletedIsPublished` : ordre exact `started, in_progress, link, completed`. Rouge : `completed` précède `link`.
- (e) `testNextReadAtIsTheEarliestExpiresOfThePagesRead` (provider : le plus tôt en 2e page, puis en 1re page, résultat 21:03:30 dans les deux cas) ; `testNextReadAtFallsBackToOneHourAfterTheReadWhenOnePageHasNoExpires` (provider : page sans Expires en 2e puis en 1re, résultat 21:04:10). Deux ordres chacun pour tuer les mutants `first`/`last` et `in_array` partiel.
- Adaptés : plus de `persistedReadings` attendues ; `readingOf`/`datesOf` remplacés par `recordedReadings`/`recordedDates()`. Supprimés car sans objet : `testSuccessfulReadUpdatesTheExistingReading...` et `testSameExpiresAsThePreviousRead...` (le service ne connaît plus la ligne existante, c'est le repository qui la met à jour : couvert par l'intégration) ; `testFailedReadOfANeverReadCharacter...` fusionné avec `testFailedReadRecordsNoReadAt...` (même appel côté service). Les deux tests d'early return vérifient `record` non appelé, après l'appel du service.
- Nouveau : `testReadingIsRecordedThroughTheRepositoryUpsertAndNeverLoadedNorPersistedAsAnEntity`.

Constat 3, `tests/Integration/Repository/CharacterWalletReadingRepositoryTest.php` (10 tests, 5 rouges, tous sur `record`) :
- deux `record` successifs = une ligne mise à jour, l'autre personnage intact ;
- premier `record` avec `readAt` null puis un succès : `readAt` renseigné ;
- un échec (`readAt` null) conserve le `readAt` existant ;
- mêmes dates deux fois : acceptées ;
- une seule requête SQL, `INSERT ... ON CONFLICT (character_id) DO UPDATE`.
Pas de vraie concurrence simulée (deux connexions) : la séquence insert puis conflit sur un personnage jamais lu vérifie l'absence de violation d'unicité, la requête unique vérifie l'atomicité.

Constat 4, `tests/Unit/Service/Sync/WalletReadingStateReaderTest.php` (5 tests, tous verts : garde-fous) :
- nouveau `testCharacterWhoseWalletScopeWasRevokedAfterAReadIsMissingScopeAndExposesNoDate` (missing_scope avec lecture, et no_token avec lecture, dates null) ;
- le test de l'échec jamais lu asserte maintenant `readAt` ET `nextReadAt` à null (avant : seulement le statut) ;
- utilisateur sans personnage => `[]` existait déjà (`testUserWithoutCharacterHasNoState`).
Point à confirmer : ces assertions figent le comportement actuel du reader (dates à null hors statut `read`, donc `nextReadAt` du retry n'est pas exposé pour `never_read`). La spec ne tranche pas. Si l'orchestrateur veut exposer le `nextReadAt` du retry, c'est à changer ici.

## Sorties

- Unit ciblé (5 classes, 122 tests) : 13 échecs, tous dans `WalletTransactionSyncServiceTest`, raisons ci-dessus.
- Integration `CharacterWalletReadingRepositoryTest` avec `TEST_TOKEN=916` : 10 tests, 5 échecs (`Failed asserting that null is not null`, `actual size 0 matches expected size 1`), squelette vide.

## Autres tests qui figent la forme touchée

`grep findOneByCharacter tests` : doubles de `WalletReadingStateReaderTest`, `SyncWalletTransactionsHandlerTest`, `ScheduledCharacterSyncFanOutTest` (lecture seule côté lecteur/handler, inchangés, le service seul perd l'usage). Aucun test `Currently…Issue916` ni caractérisation existant. Le front (`frontend/tests/`) n'a pas été touché par ce lot.

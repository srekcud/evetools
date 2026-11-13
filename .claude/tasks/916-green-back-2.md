# #916 lot correctif vert backend

Aucun commit, aucun test modifié, aucun commentaire ajouté.

## Fichiers
- `src/Repository/CharacterWalletReadingRepository.php` : `record()` = un seul `INSERT ... ON CONFLICT (character_id) DO UPDATE` via la connexion DBAL. `id` en `Uuid::v7()` (même famille que le générateur de l'entité), `next_read_at` toujours écrasé, `read_at = COALESCE(EXCLUDED.read_at, character_wallet_reading.read_at)`. Personnage non persisté : `LogicException`. Dates écrites en `Y-m-d H:i:s` (constante `DATABASE_DATE_FORMAT`).
- `src/Service/Sync/WalletTransactionSyncService.php` : plus de `find`/`persist` de la lecture (`readingOf` supprimé, import de l'entité retiré). `recordSuccessfulReading` appelé dans le try (échec => catch => `syncError`, jamais `syncCompleted`). `recordFailedReading` appelle `record(null, +900 s)` ; si l'enregistrement échoue, l'erreur est journalisée (`Failed to record the failed wallet reading`) et `syncError` publie l'erreur ESI d'origine. `linkNewPurchases` avant `syncCompleted`.

## Mort conservé
`CharacterWalletReading::setReadAt/setNextReadAt/setCharacter` et `findOneByCharacter` restent : utilisés par le reader, le handler et les tests d'intégration.

## Sorties
- Unit : suite entière 17093 tests OK (notices/deprecations préexistantes) ; Sync + MessageHandler 614 OK.
- Integration (TEST_TOKEN=916, base `eve_app_test916` créée, migrée puis supprimée) : repository + reader 12 tests OK ; `tests/Integration/State` + `tests/Integration/Service/Sync` 136 OK.
- Functional : ProjectJobAndPurchaseTrackingApiTest + GroupSaleApiTest 40 OK.
- PHPStan sur les 2 fichiers : 0 erreur. Deptrac : 0 violation (79 skipped).

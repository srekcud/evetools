# #916 phase verte backend

## Implémenté
- `EsiClient::getWithExpiry` : GET non paginé, `expiresAt()` sur les en-têtes, `get()` inchangé.
- `WalletTransactionSyncService` : lecture via `getWithExpiry`, `readAt`/`nextReadAt` (min des Expires, +3600 s si un Expires manque, constante `WALLET_CACHE_TTL_SECONDS`), échec : `readAt` inchangé, `nextReadAt` = maintenant + 900 s (`WALLET_RETRY_DELAY_SECONDS`). Constructeur : + `CharacterWalletReadingRepository`, `Symfony\Component\Clock\ClockInterface`. L'enregistrement du succès est hors du `try` (un échec de flush n'est pas rejoué en doublon).
- `CharacterWalletReading` : `OneToOne` + `onDelete: CASCADE`. Migration générée par `doctrine:migrations:diff` sur base isolée `eve_app_test916` : `migrations/Version20261113205931.php` (commentaires générés retirés, description ajoutée). `doctrine:migrations:migrate` puis `doctrine:schema:validate` : mapping et schéma synchronisés. Base supprimée à la fin.
- `CharacterWalletReadingRepository` : `findOneByCharacter`, `findByCharacters` (une requête).
- `SyncWalletTransactions(bool $dueOnly = true)` ; `SyncWalletTransactionsHandler` : filtre « dû » (aucun enregistrement ou `nextReadAt <= maintenant`), dénominateur du compteur `SyncTracker` = tous les personnages actifs (inchangé). `dueOnly: false` : `TriggerSyncProcessor` (`sync_wallet`) et `TriggerableSyncs::messagesFor('wallet')`. `UserTriggerableSyncs` ne déclenche pas `wallet` (type absent de `MESSAGE_CLASSES_BY_TYPE`), rien à changer.
- `SyncScheduler` : `SyncWalletTransactions` à 5 minutes ; `SyncTracker::EXPECTED_INTERVALS['wallet']` = 300.
- `WalletReadingStateReader::forUser` (principal d'abord, statuts `no_token` > `missing_scope` > `never_read` > `read`, dates null hors statut `read`).
- `ProjectSaleSuggestionListResource` porte l'`#[ApiResource]` (`Get`, `uriVariables`/`Link`), `ProjectSaleSuggestionResource` devient une classe simple ; les deux ressources de liste ont `normalizationContext: ['skip_null_values' => false]` (sinon `readAt`/`nextReadAt` null disparaissent de la réponse, exigé par les tests fonctionnels ; même pattern que `WalletResource`).
- Providers d'achat et de vente adaptés. `docs/modules/industry.md` : une phrase dans la ligne « Jobs et achats d'un projet ».
- `deptrac.yaml` : `skip_violations` pour `WalletReadingStateReader -> WalletReadingResource` (les tests imposent ce couple Service -> ApiResource ; précédent : `ProjectContainerService`).

## Gates
- Unit complet : 17 058 tests, 1 échec : `tests/Unit/Scheduler/SyncSchedulerLockTest::testTwoConsumersSharingCacheAndLockEmitEachDueMessageOnlyOnce` (voir blocage).
- Integration complet (base isolée migrée) : 801 verts, `MigrationsReplayTest` compris. Functional complet : 1 005 verts. Pas de N+1 (gardes 2 vs 4 personnages verts).
- PHPStan sur tout `src` : 0 erreur. Deptrac : 0 violation (79 ignorées).

## Points bloquants / à décider
- `SyncSchedulerLockTest` (hors liste rouge) liste en dur les messages dus après 30 minutes ; `SyncWalletTransactions` (groupe des 5 minutes) arrive désormais en tête. Attendu : `[SyncWalletTransactions::class, SyncCharacterOrders::class, ...]`. Test à adapter par la phase rouge, je ne touche pas `tests/`.
- Commentaires périmés laissés tels quels (règle : ne pas éditer de commentaire) : `// Wallet transactions sync every hour` dans `SyncScheduler.php` et `// 1h` à côté de `'wallet' => 300` dans `SyncTracker.php`. À retirer ou corriger si l'orchestrateur l'autorise.
- Rappel déploiement : migration `Version20261113205931` (`doctrine:migrations:migrate`). Pas de commande console ni de seed. Les personnages sont « jamais lus » jusqu'au premier tick de 5 minutes.
- `Expires` : si une page n'a pas d'en-tête lisible, `nextReadAt = readAt + 3600 s` (aligné sur `getPaginatedWithExpiry`).

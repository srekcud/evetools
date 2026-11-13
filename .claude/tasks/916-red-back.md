# #916 phase rouge backend

Base isolée `eve_app_testn916` (schema:update --force) pour Integration/Functional, supprimée à la fin. `.claude/state/tdd-phase` non touché, aucun commit.

## Squelettes créés dans src/ (sans logique, à remplacer en phase verte)

- `src/Service/ESI/EsiClient.php` : `getWithExpiry(string $endpoint, ?EveToken $token = null): PaginatedEsiResponse` renvoie `new PaginatedEsiResponse([], null)`.
- `src/Entity/CharacterWalletReading.php` : colonnes `id`, `character`, `readAt` (nullable), `nextReadAt`, getters/setters fluents. Mapping volontairement SANS contrainte : `ManyToOne`, pas d'unique, pas de `onDelete: CASCADE`. La phase verte doit passer en `OneToOne` (ou `unique: true`) + `onDelete: 'CASCADE'` + migration (non créée) ; les tests unicité et cascade le réclament.
- `src/Repository/CharacterWalletReadingRepository.php` : `findOneByCharacter(Character): ?CharacterWalletReading` renvoie `null`, `findByCharacters(list<Character>): list<CharacterWalletReading>` renvoie `[]`.
- `src/Service/Sync/WalletReadingStateReader.php` : `final readonly`, constructeur `(CharacterWalletReadingRepository)`, `forUser(User): list<WalletReadingResource>` renvoie `[]`.
- `src/ApiResource/Industry/WalletReadingResource.php` : `characterName`, `status` (string), `readAt`, `nextReadAt` (`?string`, null par défaut).
- `src/ApiResource/Industry/Project/ProjectSaleSuggestionListResource.php` : classe simple (`id`, `suggestions`, `walletReadings`), sans `#[ApiResource]` ; l'opération `GET sale-suggestions` reste pour l'instant sur `ProjectSaleSuggestionResource`.
- `src/ApiResource/Industry/PurchaseSuggestionListResource.php` : ajout de `public array $walletReadings = []`.
- Pas ajouté : constantes `WALLET_CACHE_TTL_SECONDS` / `WALLET_RETRY_DELAY_SECONDS` (les tests assertent 3600 s et 900 s en valeurs littérales), ni les paramètres de constructeur des services existants (PHP ignore les arguments en trop, les tests échouent sur l'assertion).
- Expérience transitoire : `SyncScheduler` passé à 5 minutes pour relever l'ordre d'émission du test de runs manqués, puis restauré (`git checkout`). `SyncScheduler.php` est inchangé.

## Contrats de constructeur que la phase verte doit respecter (imposés par les tests)

- `WalletTransactionSyncService(esiClient, transactionRepository, entityManager, logger, mercurePublisher, purchaseLinker, CharacterWalletReadingRepository, Symfony\Component\Clock\ClockInterface)`.
- `SyncWalletTransactionsHandler(characterRepository, messageBus, logger, syncTracker, CharacterWalletReadingRepository, ClockInterface)`.
- `PurchaseSuggestionProvider(..., accessChecker, WalletReadingStateReader)` : le reader en dernier.
- `ProjectSaleSuggestionProvider(security, projectRepository, accessChecker, transactionRepository, WalletReadingStateReader)`.
- `ProjectSaleSuggestionProvider::provide()` renvoie `ProjectSaleSuggestionListResource` ; opération `Get` (plus `GetCollection`) sur `/industry/projects/{projectId}/sale-suggestions`, donc `ApiResource` déplacé sur la liste.
- Le reader utilise `$user->getCharacters()` et `$user->getMainCharacter()` (les tests unitaires des ventes passent des `User` doublés qui ne configurent que ces deux méthodes, donc pas `Character::isMain()`).
- La sync met en file via `findByCharacters()` (handler, reader) et `findOneByCharacter()` (service) ; les doubles configurent les deux.
- Statut `no_token` > `missing_scope` > `never_read` (readAt null) > `read`. `readAt`/`nextReadAt` en `format(ATOM)` (`2026-11-13T20:04:10+00:00`).

## Tests écrits (sortie rouge, raison)

| Fichier | Tests | Rouge | Raison |
|---|---|---|---|
| `tests/Unit/Service/ESI/EsiClientTest.php` | 5 ajoutés | 5 | items vides au lieu de ceux de la réponse ; `Expected EsiApiException` (403, réseau) |
| `tests/Unit/Service/Sync/WalletTransactionSyncServiceTest.php` | 8 ajoutés, 15 existants adaptés (24 au total) | 18 | le service appelle encore `get()` (aucune lecture persistée, `getWithExpiry` jamais appelé, dates inchangées) |
| `tests/Unit/MessageHandler/SyncWalletTransactionsHandlerTest.php` (nouveau) | 7 | 3 | pas dû (+1 s), retry en cours, mélange : tout personnage avec scope est encore mis en file. Les 4 autres (dû à la seconde, jamais lu, retard après worker arrêté, sans scope) passent déjà, ce sont des garde-fous |
| `tests/Unit/Service/Sync/WalletReadingStateReaderTest.php` (nouveau) | 4 | 3 | `forUser()` renvoie `[]` ; « utilisateur sans personnage » passe (garde-fou) |
| `tests/Unit/State/Provider/Industry/PurchaseSuggestionProviderTest.php` | 3 ajoutés (8) | 3 | `walletReadings` vide (chemin avec suggestion, sans suggestion, projet sans ligne achetable = sortie anticipée) |
| `tests/Unit/State/Provider/Industry/Project/ProjectSaleSuggestionProviderTest.php` | 3 ajoutés, 14 adaptés (25) | 17 | `Failed asserting that an array is an instance of ProjectSaleSuggestionListResource` |
| `tests/Unit/Scheduler/SyncSchedulerIntervalsTest.php` | 1 ajouté, 1 adapté | 2 | `'wallet' => 3600` au lieu de 300 |
| `tests/Unit/Scheduler/SyncSchedulerMissedRunsTest.php` | 1 adapté | 1 | `SyncWalletTransactions` passe en tête de l'ordre d'émission (groupe des 5 minutes) |
| `tests/Unit/Service/Admin/SyncTrackerTest.php` | 1 adapté | 1 | `['wallet', 3600]` devient 300 (`SyncTracker::EXPECTED_INTERVALS` à changer avec le schedule) |
| `tests/Integration/Repository/CharacterWalletReadingRepositoryTest.php` (nouveau) | 5 | 5 (4 échecs, 1 erreur) | repository squelette (`[]`/`null`), pas d'unicité, FK sans cascade (`ForeignKeyConstraintViolationException` sur la suppression du personnage) |
| `tests/Integration/Service/Sync/WalletReadingStateReaderTest.php` (nouveau) | 2 | 2 | états vides ; garde N+1 sur 2 vs 4 personnages |
| `tests/Integration/State/Provider/Industry/Project/ProjectSaleSuggestionProviderTest.php` | 2 adaptés | 2 | liste enveloppée attendue |
| `tests/Integration/State/Processor/Industry/Project/SaleFromWalletTransactionTest.php` | 2 adaptés (sur 7) | 2 | liste enveloppée attendue |
| `tests/Integration/MessageHandler/ScheduledCharacterSyncFanOutTest.php` | 1 ajouté, data provider adapté (stub du repository de lectures) | 1 | personnage non dû mis en file |
| `tests/Functional/Api/Industry/ProjectJobAndPurchaseTrackingApiTest.php` | 1 ajouté | 1 | `walletReadings` vide au lieu de `read` + `no_token` |
| `tests/Functional/Api/Industry/Project/GroupSaleApiTest.php` | 1 ajouté, 1 adapté (`member` devient `suggestions`) | 2 | clé `suggestions` absente de la réponse |

Totaux : 41 tests ajoutés (31 Unit : 5 + 8 + 7 + 4 + 3 + 3 + 1 ; 10 Integration/Functional : 5 + 2 + 1 + 2), une trentaine d'existants adaptés. Le front est traité à part (`.claude/tasks/916-red-front.md`).

## Exigences « un cas par champ ESI lu »

Seul champ lu côté ESI dans cette PR : l'en-tête `Expires` (`getWithExpiry`). Absent : `expiresAt === null` ; illisible (`not a date`) : `null`, identique à `getPaginatedWithExpiry`. Le service tranche ensuite (`readAt + 3600 s`). Aucune valeur par défaut sur les champs de transaction (inchangé : `(int) $txData[...]` déjà présent, hors périmètre).

## Endpoints d'écriture

Aucun : les deux routes sont des GET sans corps ni contrainte UNIQUE API. Le doublon est couvert au niveau base (`testASecondReadingOfTheSameCharacterIsRejectedByTheDatabase`). Attendu `null` d'une réponse API : `assertArrayHasKey('readAt'|'nextReadAt')` puis comparaison à `null`, sans `??`.

## Tests existants cassés attendus avant la phase verte

- `WalletTransactionSyncServiceTest` : 15 tests existants adaptés, dont 10 rouges avant la phase verte (doubles `get` devenus `getWithExpiry`, comptages de `persist` filtrés sur `CachedWalletTransaction`, `flush` passé de `once` à `atLeastOnce` car la lecture ajoute un flush).
- `SyncSchedulerIntervalsTest::testScheduledSyncIntervalsInSeconds`, `SyncSchedulerMissedRunsTest`, `SyncTrackerTest::testGetAll…` : le tick de 5 minutes. Le test d'égalité `testEveryScheduledSyncIsTracked…` ne casse que si le schedule et `SyncTracker::EXPECTED_INTERVALS` ne sont pas changés ensemble.
- `ProjectSaleSuggestionProviderTest` (Unit, 14 existants), Integration `ProjectSaleSuggestionProviderTest` et `SaleFromWalletTransactionTest`, Functional `GroupSaleApiTest::testOwnerReadsTheSaleSuggestions…` : forme enveloppée.
- Integration complet sur la base `schema:update` : `MigrationsReplayTest` échoue tant que la migration manque (attendu). `AccountDeletionServiceTest::testX1…`, `AccountDeletionMiningOperationTest` (4) et `InvTypeNameIndexTest` échouent à cause de la base construite par `schema:update` et non par les migrations ; sans rapport avec #916, à ne pas imputer à la phase verte.
- Unit complet : 53 échecs, tous dans les fichiers listés ci-dessus, aucun ailleurs.

## Cherche des tests voisins

- `grep` sur `getWithExpiry`, `syncCharacterTransactions`, `SyncWalletTransactions`, `sale-suggestions`, `ProjectSaleSuggestion`, `PurchaseSuggestion*`, `'wallet'`/`3600` : tout est traité ci-dessus. Les lectures de `/wallet/transactions/` de `PveSyncService`, `ScanContractsProcessor`, `ScanLootSalesProcessor` restent sur `get()`, sans rapport.
- `CharacterSyncHandlersTest` (handler par personnage) : inchangé, le filtre « dû » vit dans le fan-out seul (règle 5).
- `grep "Currently.*Issue916|CARACTÉRISATION"` : rien. Aucun test ne figeait la valeur fausse (3600 s) hors `SyncSchedulerIntervalsTest` et `SyncTrackerTest`.
- Front : les specs `groupDistributionSaleSuggestions`, `saleSuggestionsPanel`, `groupIndustryDetailSaleSuggestions`, `jobsTablePurchases`, `groupProjectUrls` figent la forme de `sale-suggestions` (tableau) et de `purchase-suggestions` ; ce sont des fichiers du lot front, déjà modifiés dans l'arbre.

## Points à trancher ou à surveiller pour la phase verte

1. Déclencheur manuel : `TriggerSyncProcessor` (admin) et `UserTriggerableSyncs` (`'wallet'`) envoient `SyncWalletTransactions`. Avec le filtre « dû », un clic ne lance plus rien tant que `nextReadAt` n'est pas passé. Le spec classe « forcer une lecture » hors périmètre, mais l'effet sur ces deux déclencheurs n'est pas mentionné ; aucun test ne le fige.
2. Compteur `SyncTracker('wallet')` : le spec dit « personnages mis en file sur les personnages éligibles ». Les tests figent seulement `2/3 chars queued` (tous dus, trois actifs, dénominateur = actifs renvoyés par `findActiveWithValidTokens`). Si « éligibles » veut dire « avec scope », le cas non dû n'est pas figé.
3. N+1 : `Character::eveToken` est le côté inverse d'un OneToOne, donc chargé par une requête par personnage. Le reader lit les tokens de chaque personnage de l'utilisateur ; `WalletReadingStateReaderTest` (Integration) et `ProjectSaleSuggestionProviderTest` (Integration, borne 6, 5 requêtes aujourd'hui) échoueront si le reader ajoute plus d'une requête.
4. Type du `clock` : `Symfony\Component\Clock\ClockInterface` (pas `Psr\Clock`).

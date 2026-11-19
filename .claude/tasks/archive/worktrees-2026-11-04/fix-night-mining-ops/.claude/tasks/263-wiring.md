# Câblage #263 — Instantanés du ledger et quantités minées

Spec : `docs/specs/263-mining-operation-ledger-snapshots.md`. Base : `origin/main` @ `a6aead59` (#262 fusionné). Aucun fichier `Industry*` / `GroupIndustry*` touché. `MiningSyncService` non modifié (décision 5).

## 1. Dépendances de l'implémentation cible et fourniture par le harnais

| Dépendance | Utilisée par | Unitaire (Domain / Application) | Intégration / fonctionnel |
|---|---|---|---|
| Entités #262 + `MiningLedgerSnapshot`, `MiningLedgerSnapshotLine`, `MiningQuantityCorrection` ; champs `startedAt`, `endedAt`, `solarSystemId/Name`, `departedAt` | tout | instanciées directement ; `id` UUID posé par réflexion (trait `tests/Unit/Service/MiningOperation/BuildsSpodumainOperation.php`, à étendre : `startedOperation()`, `snapshotOf(character, kind, status, lines)`) | `PersistsMiningOperations` (`tests/Integration/MiningOperation/`) étendu pour persister instantanés et lignes |
| Enums `MiningLedgerSnapshotKind`, `MiningLedgerSnapshotStatus`, `MinedQuantityState`, `MinedQuantityUnknownReason` | tout | valeurs réelles | valeurs réelles |
| **Horloge** `Psr\Clock\ClockInterface` | `MiningOperationLifecycle`, `MiningLedgerSnapshotTaker`, `MiningLedgerSnapshotDispatcher`, `RetryMiningLedgerSnapshotProcessor`, provider du ledger (`canRetry`) | `new MockClock('2026-11-05 20:00:00 UTC')` puis `sleep()` / `modify()` (motif `IndustryJobSyncServiceTest`) | `ClockSensitiveTrait::mockTime()` de `symfony/clock` : le service `clock` (`Symfony\Component\Clock\Clock` sans horloge interne) délègue à `Clock::get()`. À vérifier au rouge : si l'alias `Psr\Clock\ClockInterface` est inliné, ajouter un alias public sous `when@test` dans `config/services.yaml` et le remplacer par `getContainer()->set()` |
| **ESI** `EsiClient::getPaginatedWithExpiry()` | `MiningLedgerReader` | (a) `MiningLedgerReaderTest` : **vrai** `EsiClient` sur `MockHttpClient` (motif `tests/Unit/State/Processor/Pve/CorpProjectsEsiSimulation.php`) pour `X-Pages: 2`, `Expires`, 403, 502, liste vide ; (b) handler / taker : `createStub(EsiClient::class)` dont `getPaginatedWithExpiry` renvoie un `PaginatedEsiResponse(items, expiresAt)` (motif `IndustryJobSyncServiceTest:168`) ou lève `EsiApiException::fromResponse(502, …)` | `getContainer()->set(EsiClient::class, $stub)` (motif `tests/Functional/Api/Me/WalletUnknownBalanceSerializationTest.php:39-40`) |
| **Token** `TokenManager::getValidAccessToken()` | `MiningLedgerReader` | `createStub(TokenManager::class)` : renvoie `'access-token'`, ou lève `new EveAuthRequiredException((string) $eveCharacterId)` (H6) | `getContainer()->set(TokenManager::class, $stub)` (même motif) |
| Messenger : bus + `DelayStamp` | `MiningLedgerSnapshotDispatcher` (après `flush`), handler (remise en file) | `createMock(MessageBusInterface::class)` : `dispatch` capturé, `Envelope::last(DelayStamp::class)->getDelay()` vérifié (H2 `436000`, H3 `137000`, L1 sans stamp) | vrai bus, transport `async` = `in-memory://` : `$container->get('messenger.transport.async')->getSent()` (motif `tests/Integration/MessageHandler/ScheduledCharacterSyncFanOutTest.php:124`) ; le handler est invoqué directement depuis le conteneur pour simuler la livraison différée, horloge avancée entre les deux |
| `TakeMiningLedgerSnapshot` routé `async` | handler | — | `config/packages/messenger.yaml` : vérifier le routage via `getSent()` |
| `MiningLedgerSnapshotRepository` (`find`), `MiningOperationCharacterRepository` (`find` pour `{id}`) | handler, processors R15/R19 | stub en mémoire | réel |
| `MapSolarSystemRepository::find()` (R2) | `StartMiningOperationProcessor` / `MiningOperationLifecycle` | stub renvoyant une `MapSolarSystem` pour S1, `null` pour `1` | une ligne `MapSolarSystem` persistée dans le test (motif `StructureSearchProviderTest` pour l'entité) |
| `InvTypeRepository` / `TypeNameResolver` (R19 `Unknown type`, `typeName` du ledger) | `CorrectMinedQuantityProcessor`, `MiningOperationLedgerProvider` | stub `findName()` / `find()` | `InvType` Spodumain et Bistot persistés (motif `tests/Functional/Api/Industry/BuildsCarrierProjectWithFuelBlockSteps.php`) |
| `MercurePublisherService` (`publishSyncProgress`, `syncStarted/Progress/Completed/Error` avec `data`, `publishMiningOperationEvent`) | handler, processors | **vrai** `MercurePublisherService` sur `HubInterface` mock + `NullLogger` (motif #262 `JoinMiningOperationProcessorTest`) ; topics et `data` vérifiés (T2-T4) | `HubInterface` remplacé par un spy (classe `final readonly`, non mockable) |
| `MiningOperationAudience::userIdsOf()` | destinataires Mercure | réel | réel |
| `Security`, `EntityManagerInterface`, `UuidOrNotFound`, Validator | processors, DTO | comme #262 (`createStub(Security)`, `createMock(EntityManagerInterface)` avec `flush` attendu / interdit) | `loginUser($user, 'api')`, base `eve_app_test` |
| `LoggerInterface` | handler (R13 `warning`) | `BufferingLogger` (motif `SyncCharacterIndustryJobsHandlerTest:79`) | `NullLogger` |
| Migration unique | schéma | — | `tests/Integration/Migrations/MigrationsReplayTest.php` vert |
| Front : `services/api`, routeur, i18n, `stores/sync.ts` | store, vue détail | `vi.mock('@/services/api')` ; EventSource factice (motif `frontend/tests/stores/syncMiningOperationEvents.spec.ts`) ; `expectOnlyLocaleTexts` ; `vi.useFakeTimers()` + `vi.setSystemTime()` pour le compte à rebours (F5) | — |

## 2. Tests existants appelés à casser

| Test | Raison | Changement attendu |
|---|---|---|
| `tests/Unit/Service/Mercure/MercurePublisherServiceTest.php` (liste exacte des topics, `assertCount(16, …)` ligne 587) | R25 ajoute `/user/{id}/sync/mining-operation-snapshot` | ajouter le topic à la liste attendue ; 16 → 17 |
| `tests/Unit/State/Processor/MiningOperation/JoinMiningOperationProcessorTest.php` (`new JoinMiningOperationProcessor(` ligne 160) | R4 : nouvelle dépendance `MiningOperationLifecycle` (ou `MiningLedgerSnapshotDispatcher`) pour l'instantané de l'inscrit en retard | passer la nouvelle dépendance ; les cas `planned` n'envoient aucun message |
| `tests/Unit/State/Processor/MiningOperation/UpdateMiningOperationMemberProcessorTest.php` (`new UpdateMiningOperationMemberProcessor(` ligne 63) | R4 (acceptation en cours) et R5 (refus en clôture) : nouvelle dépendance | idem ; les cas existants (`planned`) inchangés |
| `CLAUDE.md`, section Mercure (pas un test) | liste des types sync | ajouter `mining-operation-snapshot` au vert |

Ne doivent **pas** casser (à relancer en contrôle) :
- `tests/Unit/ApiResource/MiningOperationSubResourceUriVariablesTest.php` : scanne `src/ApiResource/MiningOperation/` ; les deux nouvelles ressources déclarent leurs `Link` (`operationId`, `id`), il les couvre sans modification.
- `tests/Unit/State/Processor/MiningOperation/LeaveMiningOperationProcessorTest.php`, `MiningOperationApiTest` Q3 : `leave` inchangé (R8, L18).
- `tests/Unit/State/Provider/MiningOperation/*Test.php`, `CreateMiningOperationProcessorTest`, `RenewMiningOperationJoinCodeProcessorTest` : `new MiningOperationResourceMapper()` reste sans argument (`solarSystemName` est stocké, pas résolu) ; les nouveaux champs de R22/R23 sont `null` hors cycle de vie.
- `tests/Unit/Service/Sync/MiningSyncService*Test.php`, `SyncMiningProcessorCharacterizationTest`, `MiningSyncPriceHistoryTest` : `MiningSyncService` non touché.
- Les appelants existants de `syncStarted()` / `syncError()` : paramètre `$data` facultatif.
- `tests/Integration/Service/Account/AccountDeletionServiceTest.php::testNoForeignKeyColumnReferencesTheDeletedAccount…` : nouvelles FK en cascade, `corrected_by_id` en `SET NULL` (donc `null` après suppression) ; à relancer.
- `frontend/tests/views/MiningOperation/MiningOperationDetailRealtime.spec.ts` : fixtures `planned`, aucun appel à `fetchLedger` attendu tant que la vue n'appelle le ledger que hors `planned` — **à vérifier** : si la vue charge le ledger dès `planned` (G5), le mock du store doit exposer `fetchLedger`, et le comptage d'appels T8 de #262 reste sur `fetchDetail` / `fetchMembers`.
- `frontend/tests/stores/syncMiningOperationEvents.spec.ts` : un message `mining-operation-snapshot` porte `syncType`, il est rangé dans `syncStatus`, pas dans `miningOperationEvents`.

## 3. Découpage du lot

### Lot 1 — Domain (unitaire, sans base, sans horloge système)
- `tests/Unit/Service/MiningOperation/MinedQuantityCalculatorTest.php` : Q1-Q9.
- `tests/Unit/Service/MiningOperation/MinedQuantityTest.php` : états U1-U5, corrections U6-U8, agrégation participant (R18).
- `tests/Unit/Entity/MiningOperationLifecycleEntityTest.php` (ou extension de `MiningOperationTest`) : `start(at)`, `end(at)`, `MiningOperationMember::depart(at)`, `MiningLedgerSnapshot::schedule/take/fail`, `isSettled()` ; heure passée en paramètre.

### Lot 2 — Application (unitaire, dépôts en mémoire, `MockClock`, ESI simulé)
- `tests/Unit/Service/MiningOperation/MiningLedgerReaderTest.php` : vrai `EsiClient` + `MockHttpClient` : pagination, `Expires`, liste vide, 403, 502, `EveAuthRequiredException`.
- `tests/Unit/Service/MiningOperation/MiningLedgerSnapshotTakerTest.php` : H1-H12 (H9 : `flush` qui lève).
- `tests/Unit/MessageHandler/TakeMiningLedgerSnapshotHandlerTest.php` : instantané inconnu (supprimé par cascade) journalisé et ignoré, délégation au taker.
- `tests/Unit/Service/MiningOperation/MiningOperationLifecycleTest.php` : L1-L17, R-1 à R-6.
- `tests/Unit/State/Processor/MiningOperation/{Start,End,Depart}MiningOperationProcessorTest.php`, `RetryMiningLedgerSnapshotProcessorTest.php`, `CorrectMinedQuantityProcessorTest.php` : codes et messages, publications T2-T3, `flush` interdit sur refus.
- `tests/Unit/State/Provider/MiningOperation/MiningOperationLedgerProviderTest.php` : G1-G5, ordre, `canRetry`.
- `tests/Unit/ApiResource/Input/MiningOperation/{StartMiningOperationInput,CorrectMinedQuantityInput}Test.php` : C-5, `solarSystemId` ≤ 0.
- `MercurePublisherServiceTest` : T1, `syncStarted` / `syncError` avec `data`.
- Mise à jour des deux tests de processors de la section 2.

### Lot 3 — API (intégration, `make test-db`)
- Migration unique ; `MigrationsReplayTest`.
- `tests/Functional/Api/MiningOperation/MiningOperationLedgerApiTest.php` : un test par ligne L1-L18, R-1 à R-6, C-1 à C-7, G1-G5 par HTTP ; ESI et `TokenManager` remplacés dans le conteneur ; messages lus dans `messenger.transport.async`, handler invoqué après `mockTime()` avancé (parcours complet 20:00 → 21:30 → 21:37:17 → ledger Q1 = 35 500).
- Suppression de compte : opération `closing` avec instantanés et correction d'Aria ; compte de Brann supprimé → ses lignes disparaissent, correction gardée avec `correctedBy` `null` si l'auteur est supprimé.

### Lot 4 — Front (vitest)
- `tests/stores/miningOperationLedger.spec.ts` : `start`, `end`, `depart`, `fetchLedger`, `retrySnapshot`, `correctQuantity` (URL, corps, `merge-patch` non concerné : tout en `POST`).
- `tests/views/MiningOperation/MiningOperationDetailLifecycle.spec.ts` : F1-F7 ; `MiningOperationDetailLedgerRealtime.spec.ts` : T5 et R28.
- Textes fr / en : F8.

## 4. Questions de spec ouvertes

1. Heure de lecture via une première lecture `Expires` (décision 1) ou attente fixe de 600 s : un appel ESI de plus par personnage, accepté ?
2. Système choisi au démarrage plutôt qu'à la création (décision 8) ?
3. Relance de l'instantané de début en cours d'opération, au prix du minage d'avant la relance (décision 13) ?
4. Retrait d'un membre en cours d'opération : ses instantanés disparaissent avec ses personnages inscrits (#262 R14). Faut-il interdire le retrait d'un participant `accepted` hors `planned` ?
5. Borne exacte des 30 jours du ledger ESI (R14) : à confirmer en jeu.
6. Premier `DelayStamp` sur RabbitMQ : le transport AMQP crée une file de délai par valeur de délai (expirée ensuite). Volume attendu faible ; à surveiller dans l'admin RabbitMQ après le déploiement.
7. Actions prod au déploiement : migration seule, aucune commande ni seed ; worker à redémarrer (nouveau message routé).

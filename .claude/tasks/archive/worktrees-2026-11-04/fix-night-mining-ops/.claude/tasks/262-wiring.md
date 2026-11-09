# Câblage #262 — Opération de minage : créer, rejoindre

Spec : `docs/specs/262-mining-operation-create-join.md`. Base : `main` @ `f5416084`. Aucun fichier `Industry*` / `GroupIndustry*` touché.

## 1. Dépendances de l'implémentation cible et fourniture par le harnais

| Dépendance | Utilisée par | Unitaire (Domain / Application) | Intégration / fonctionnel |
|---|---|---|---|
| `MiningOperation`, `MiningOperationMember`, `MiningOperationCharacter` (entités) | tout | instanciées directement ; l'`id` UUID posé par réflexion (motif `GroupProjectAccessProfiles`) | persistées par `EntityManager` sur `eve_app_test` |
| Enums `MiningOperationStatus`, `MiningOperationVisibility`, `MiningSplitMode`, `MiningOperationMemberRole`, `MiningOperationMemberStatus`, `MiningCharacterEligibility` | tout | valeurs réelles | valeurs réelles |
| `User`, `Character`, `EveToken` (existants) | éligibilité R6/R8, corporation R3/R9 | entités réelles : `Character::setUser()`, `markAuthInvalid()`, `EveToken::setScopes([...])` ; pas de personnage principal pour « Dorn » | `persistPilot()` à la manière de `BuildsIshtarGroupProject`, avec un `EveToken` portant `esi-industry.read_character_mining.v1` (chiffrement du refresh token inutile : jamais déchiffré) |
| `random_bytes` (code R5) | `MiningOperation::__construct()`, `renewJoinCode()` | réel ; tests sur le format `^[0-9a-f]{10}$` et l'inégalité avant / après | réel ; le code d'une fixture est forcé par un setter si le test cite `7f3a9c2e1b` |
| `MiningOperationRepository` (`findOneByJoinCode`, `findAvailableForCorporation`, `findForParticipant`) | providers, processors | stub PHPUnit dont `findOneByJoinCode()` / `find()` lisent un tableau en mémoire | requêtes réelles : `tests/Integration/Repository/MiningOperationRepositoryTest.php` (L1, L2, L3) |
| `MiningOperationMemberRepository` (`findOneBy`) | `MiningOperationAccess`, processors | stub en mémoire qui compare chaque critère (motif `GroupProjectProviderTest::setUp()`) | réel |
| `MiningOperationCharacterRepository::findOpenEnrolmentsOf(list<Character>)` → opération qui retient chaque personnage | `MiningOperationEnrolment` (R6.3, R7, R8) | stub qui renvoie une table personnage → opération ; le filtre de statut est testé en intégration | réel : `MiningOperationCharacterRepositoryTest` (A3, J18, J19, opération `completed` ignorée, membre `removed` sans ligne) |
| `Security` | providers, processors | `createStub(Security::class)` + `getUser()` | `$client->loginUser($user, 'api')` |
| `EntityManagerInterface` | processors | `createMock` : `persist` / `remove` / `flush` attendus ou interdits (refus atomiques : `flush` jamais appelé) | réel |
| `MercurePublisherService::publishMiningOperationEvent()` (nouvelle méthode) | processors R26 | **vrai** `MercurePublisherService` sur un `HubInterface` mock + `NullLogger` (motif `JoinGroupProjectProcessorTest:89`) ; T2-T4 vérifient les topics et le payload publiés ; T5 : `publish` jamais appelé | `HubInterface` remplacé dans le conteneur de test par un spy (la classe est `final readonly`, on ne la mocke pas) ; `LoggerInterface` capturant pour J21 |
| `MercurePublisherService::getTopicsForUser()` | `TokenProvider` (inchangé) | appel statique | — |
| `UuidOrNotFound` (existant) | providers `{id}` | réel | réel (X10 avec `abc`) |
| Validator Symfony (`CreateMiningOperationInput`, `JoinMiningOperationInput`, `UpdateMiningOperationMemberInput`) | R2, `422` | `Validation::createValidatorBuilder()->enableAttributeMapping()` sur le DTO (E4-E8, J8) | réel via HTTP |
| Sérialisation de `plannedStartAt` en UTC (E3) | `CreateMiningOperationProcessor` / mapper | `DateTimeImmutable` avec `+01:00` en entrée du service | `POST` JSON réel, lecture de la réponse |
| Migration unique | schéma | — | `MigrationsReplayTest` doit rester vert (mapping = migrations) |
| ESI, SDE, cache Redis, RabbitMQ, horloge | — | **aucune** : pas d'appel ESI, pas de message, pas de règle temporelle | — |
| Front : `services/api` | store `miningOperation.ts` | `vi.mock('@/services/api')` | — |
| Front : routeur | vues | `createRouter({ history: createMemoryHistory(), routes })` avec les vraies routes (F2) | — |
| Front : i18n | vues | vraies locales + `tests/localeTexts.ts` (`expectOnlyLocaleTexts`) | — |
| Front : `stores/sync.ts` (branche `operationId`) | T6-T8 | motif `tests/stores/syncGroupProjectEvents.spec.ts` (EventSource factice) | — |

## 2. Tests existants appelés à casser

| Test | Raison | Changement attendu |
|---|---|---|
| `tests/Unit/Service/Mercure/MercurePublisherServiceTest.php::testGetTopicsForUserReturnsAllExpectedTopics` | liste exacte des topics, R25 en ajoute un | ajouter `/user/user-uuid-123/mining-operations` en fin de liste |
| `tests/Unit/Service/Mercure/MercurePublisherServiceTest.php::testGetTopicsForUserReturnsCorrectCount` | 15 → 16 | `assertCount(16, …)` |
| `frontend/tests/composables/useNavigationEntries.spec.ts` : listes de libellés en et fr, `toHaveLength(24)` (admin) et `toHaveLength(22)` (après masquage de `planetary`), liste des routes quand `industry` et `market` sont masqués | deux entrées ajoutées (`/mining-operations`, `/mining-operations?tab=available`) après celles de Production | insérer les deux entrées ; 24 → 26, 22 → 24 |
| `frontend/tests/layouts/MainLayoutNavigation.spec.ts`, `MainLayoutResponsive.spec.ts`, `frontend/tests/components/common/CommandPalette*.spec.ts` | second groupe repliable (`mining`) dans la barre latérale et la palette | à relancer ; pas de casse attendue (ils ciblent « Production » par son nom), à corriger seulement s'ils comptent les groupes |

Ne doivent **pas** casser (à relancer en contrôle) :
- `tests/Integration/Service/Account/AccountDeletionServiceTest.php::testNoForeignKeyColumnReferencesTheDeletedAccountAndTheOtherAccountKeepsAllItsRows` : il découvre les FK vers `users` / `characters` dynamiquement ; les trois nouvelles FK sont en cascade, donc à 0 après suppression. Ajouter une opération dans `seedPersonalData()` est un test nouveau (S-account), pas une correction.
- `tests/Integration/Migrations/MigrationsReplayTest.php` : vert si la migration correspond au mapping (noms d'index générés par `doctrine:migrations:diff`).
- `frontend/tests/stores/syncGroupProjectEvents.spec.ts` : la branche `operationId` ne capte pas un message qui porte `projectId` (T7).
- `tests/Unit/ApiResource/IndustrySubResourceUriVariablesTest.php` : ne scanne que `Industry` et `GroupIndustry` ; il n'est pas étendu (fichier `Industry*`). Un test dédié `MiningOperationSubResourceUriVariablesTest` réutilise `SubResourceUriVariablesDetector`.

## 3. Découpage du lot

### Lot 1 — Domain (unitaire, sans base)
`tests/Unit/Entity/MiningOperationTest.php`, `MiningOperationMemberTest.php`, `tests/Unit/Enum/MiningOperationStatusTest.php` : D1-D6.

### Lot 2 — Application (unitaire, dépôts en mémoire)
- `tests/Unit/Service/MiningOperation/MiningOperationEnrolmentTest.php` : éligibilité A1-A5, A8 ; création E1-E3, E9-E12 ; adhésion J1-J7, J9-J11, J14-J18, J20 ; atomicité (`flush` jamais appelé sur refus).
- `tests/Unit/Service/MiningOperation/MiningOperationAccessTest.php` : matrice X1-X9 (lecture, participation), administration (M2, C2, S2), gestion d'un admin (M4, M8).
- `tests/Unit/State/Processor/MiningOperation/*Test.php` : M1-M11, Q1-Q5, C1-C3, S1-S3 ; publications T2-T5 et J21 via le vrai publisher sur hub mock.
- `tests/Unit/Service/Mercure/MercurePublisherServiceTest.php` : T1 et `publishMiningOperationEvent()` (topics sans doublon, payload, `private: true`, échec journalisé).
- `tests/Unit/ApiResource/Input/MiningOperation/*Test.php` : E4-E8, J8, `UpdateMiningOperationMemberInput`.
- `tests/Unit/ApiResource/MiningOperationSubResourceUriVariablesTest.php` : chaque opération à `{operationId}` / `{id}` / `{code}` déclare ses `Link`.

### Lot 3 — API (intégration, `make test-db`)
- `tests/Integration/Repository/MiningOperationRepositoryTest.php` : L1-L3 ; `MiningOperationCharacterRepositoryTest.php` : ouvertures A3, J18, J19.
- `tests/Functional/Api/MiningOperation/` avec un trait `BuildsSpodumainOperation` (Aria, Brann, Cyra, Dorn, O, « Ice belt — dimanche ») : un test par ligne des tableaux Création, Aperçu, Rejoindre (chemin HTTP, codes et messages `detail`), Accès X1-X10, gestion M1/M7, départ Q1, code C1 + J13, suppression S1 (cascade vérifiée en SQL), E3 (UTC).
- Suppression de compte : propriétaire et membre d'une opération, tables `mining_operation_*` vides pour ce compte, autre compte intact.

### Lot 4 — Front (vitest)
- `tests/views/miningOperationJoinUrl.spec.ts` (F1), routeur (F2, F3).
- `tests/stores/miningOperation*.spec.ts` : appels API de chaque action, propagation des messages `detail`.
- `tests/stores/syncMiningOperationEvents.spec.ts` : T6-T7 ; `tests/views/MiningOperationDetailRealtime.spec.ts` : T8.
- `tests/views/MiningOperationJoin.spec.ts` : F4-F9 ; `MiningOperationList.spec.ts` / création : F10, F11 ; textes fr / en F12.
- Mise à jour de `useNavigationEntries.spec.ts` (F13, section 2).

## 4. Questions de spec ouvertes

1. Lien visible de tous les participants (décision 13) ou réservé au propriétaire et aux admins ?
2. Un membre `accepted` peut-il quitter une opération en cours avant #263 (décision 17) ?
3. Suppression de compte du propriétaire : cascade de ses opérations planifiées acceptable jusqu'à #264 (décision 12) ?
4. `CLAUDE.md`, section Mercure : ajouter `/user/{userId}/mining-operations` aux « autres topics utilisateur » au moment du vert.
5. Actions prod au déploiement : migration seule, aucune commande ni seed.

# #86 — Phase rouge : statut d'autorisation porté par le personnage

Worktree `evetools-wt-DR`, branche `fix/character-auth-status`, base a70aafd. Rien n'est commité.

## Squelette ajouté dans `src/` (sans logique)

- `src/Entity/Character.php` : champ `authStatus` (`AuthStatus`, colonne `string(20)`, `enumType`, défaut PHP `AuthStatus::Valid`), `getAuthStatus()`, `isAuthValid()`, `markAuthInvalid()`, `markAuthValid()` (accesseurs simples sur le champ).
- `migrations/Version20261019010000.php` : `ADD auth_status VARCHAR(20) DEFAULT 'valid' NOT NULL` puis `DROP DEFAULT` ; `down()` supprime la colonne. `users.auth_status` n'est pas touchée (phase verte).
- Base isolée `eve_app_test_dr` migrée ; `doctrine:schema:validate` OK ; `MigrationsReplayTest` vert.
- Aucune logique modifiée (TokenManager, AuthenticationService, repositories, AssetsSyncService, AdminService, User).

Choix : les accesseurs écrivent vraiment le champ. Avec des méthodes vides, les rouges des services échoueraient à cause du squelette et non de la logique manquante. Conséquence : les tests de statut de `CharacterTest` sont verts dès cette phase (support, pas un exemple E).

## Tests par exemple

| # | Test | Fichier | Résultat rouge |
|---|---|---|---|
| E1 | `testRefreshWithRevokedRefreshTokenPersistsOnlyTheRevokedCharacterAsInvalid` (réécrit depuis `...PersistsTheUserAuthStatusAsInvalid`) | `tests/Unit/Service/TokenManagerTest.php` | ROUGE : statut du personnage révoqué `Valid` au lieu de `Invalid` (l'utilisateur est marqué, pas le personnage) |
| E1 | `testRevokedEveGrantDuringSyncPersistsTheCharacterAuthStatusAsInvalidAndKeepsTheMainValid` (réécrit) | `tests/Unit/MessageHandler/SyncCharacterAssetsHandlerTest.php` | ROUGE : `Valid` au lieu de `Invalid` |
| E1 / R6 | `testUserWithOneRevokedAltHasAnInvalidAuthStatus` | `tests/Unit/Entity/UserTest.php` | ROUGE : `/me` (`User::getAuthStatus()`) vaut `Valid`, statut non dérivé des personnages |
| E2 | `testFindActiveWithValidTokensSkipsTheRevokedAltAndKeepsTheMainOfTheSameUser` (réécrit) | `tests/Integration/Repository/CharacterRepositoryTest.php` | ROUGE : `['Main Pilot', 'Revoked Alt Pilot']` au lieu de `['Main Pilot']` |
| E3 | `testFindWithValidTokensSkipsTheRevokedAltAndKeepsTheMainOfTheSameUser` (réécrit) | idem | ROUGE : même écart |
| E4 | `testRevokedCharacterGetsEveAuthRequiredWithoutAnySsoRequestWhileItsAccessTokenIsStillValid` | `TokenManagerTest` | ROUGE : `stored-access-token` rendu au lieu de `EveAuthRequiredException` |
| E5 | `testRevokedCharacterGetsEveAuthRequiredWithoutAnySsoRequestOnceItsAccessTokenHasExpired` | `TokenManagerTest` | ROUGE : une requête `POST https://login.eveonline.com/v2/oauth/token` au lieu de `[]` |
| E5 (chaîne) | `testSyncOfACharacterAlreadyKnownAsRevokedRequestsNeitherSsoNorEsiAndIsNotRetried` | `SyncCharacterAssetsHandlerTest` | ROUGE : URL SSO demandée au lieu de `[]` |
| R3 | `testForceRefreshOfARevokedCharacterThrowsEveAuthRequiredWithoutAnySsoRequest` | `TokenManagerTest` | ROUGE : aucune exception levée |
| E6 | `testLoginWithTheMainKeepsTheRevokedAltInvalid` | `tests/Unit/Service/ESI/AuthenticationServiceTest.php` (nouveau) | ROUGE : `/me` `Valid` au lieu de `Invalid` (`$user->markAuthValid()` au login) |
| R5 | `testLoginWithARevokedMainRevalidatesItAndLeavesTheAltValid` | idem | ROUGE : le main reste `Invalid` |
| E7 | `testAddingTheRevokedAltBackToItsUserRevalidatesIt` | idem | ROUGE : l'alt reste `Invalid` |
| E8 | `testFindActiveWithCharactersKeepsAUserWhoseAltIsRevokedWithAllItsCharacters` | `tests/Integration/Repository/UserRepositoryTest.php` (nouveau) | VERT (garde-fou) : sans `User::markAuthInvalid()` (supprimé en vert) le test ne peut plus reproduire « U invalide » ; il protège contre un filtre `c.authStatus` dans le `WHERE` qui hydraterait une collection partielle |
| E9 | `testFindActiveWithCharactersSkipsAUserWhoseCharactersAreAllRevoked` | idem | ROUGE : l'utilisateur figure dans le résultat |
| E10 | `testReturnsNullWhenOnlyTheRevokedAltCarriesTheStructureMarketScope` | `tests/Integration/Repository/CharacterRepositoryStructureMarketRequesterTest.php` | ROUGE : `'Revoked Alt With Scope'` au lieu de `null` |
| E10 | `testPicksTheValidMainWhenTheRevokedAltAlsoCarriesTheStructureMarketScope` | idem | ROUGE : `'Revoked Alt With Scope'` au lieu de `'Main With Scope'` (dépend de l'ordre SQL aujourd'hui, rouge observé) |
| E10 | `testIgnoresACharacterWhoseAuthorizationIsRevoked` (réécrit depuis `testIgnoresUsersWhoseAuthenticationIsInvalid`) | idem | ROUGE : `'Revoked Pilot'` au lieu de `null` |
| E11 | `testCanSyncRefusesTheRevokedAltOfAUserWhoseMainIsValid` (remplace `testCanSyncReturnsFalseWhenUserAuthInvalid`) | `tests/Unit/Service/Sync/AssetsSyncServiceTest.php` | ROUGE : `true` au lieu de `false` |
| E11 | `testCanSyncAcceptsTheValidMainOfAUserWhoseAltIsRevoked` | idem | VERT (garde-fou) |
| E12 | `testAUserWithARevokedAltCountsAsInvalidWhileItsValidMainStillCountsWithValidTokens` | `tests/Integration/Service/Admin/AdminServiceUserAndCharacterStatsTest.php` (nouveau) | ROUGE : écarts `users.valid +1 / invalid +0 / withValidTokens +2 / activeSyncScope +2` au lieu de `0 / 1 / 1 / 1` |
| R4 | `testFindActiveWithValidTokensSkipsEveryCharacterOfAUserWhoseCharactersAreAllRevoked` | `CharacterRepositoryTest` | ROUGE : les deux personnages révoqués sont rendus |
| R4 | `testFindWithCorpAssetsAccessReturnsNullWhenTheOnlyScopedCharacterIsRevoked` | idem | ROUGE : `'Revoked Director Pilot'` au lieu de `null` |
| R4 | `testFindWithCorpAssetsAccessSkipsTheRevokedDirectorAndPicksTheValidCorpMember` | idem | ROUGE : `'Revoked Director Pilot'` au lieu de `'Member Pilot'` (dépend de l'ordre SQL aujourd'hui, rouge observé) |

E12 passe par un test d'intégration : les requêtes sont du SQL natif, un `Connection` stub ne prouve rien. Le test compare des écarts avant/après (la base de test peut contenir d'autres lignes) et construit `AdminService` avec un `SyncTracker` stub pour ne pas dépendre de Redis.

## Garde-fous (verts)

- `CharacterTest` : `testNewCharacterHasAValidAuthStatus`, `testMarkAuthInvalidMakesTheCharacterAuthInvalid`, `testMarkAuthValidRestoresAnInvalidCharacterAuth`, `testRevokingTheAuthOfAnAltLeavesTheMainCharacterValid`.
- `UserTest` : `testUserWithoutCharacterHasAValidAuthStatus`, `testUserWhoseCharactersAreAllValidHasAValidAuthStatus`, `testUserBecomesValidAgainOnceItsRevokedCharacterIsReconnected` (remplacent `testNewUserHasValidAuthStatus`, `testMarkAuthInvalid`, `testMarkAuthValid`, qui appelaient des méthodes de `User` à supprimer).
- `TokenManagerTest` : asserts 503, réseau, refresh réussi et `assertRefreshFailureLeavesTheCharacterValid` basculés de `$user` vers le personnage ; trois tests renommés `...KeepsTheCharacterValid`.
- `AuthenticationServiceTest::testAddingTheRevokedAltBackStoresItsNewRefreshToken`.
- `AssetsSyncServiceTest` : `testCanSyncReturnsTrueWhenTheCharacterHasATokenAndAValidAuth`, `testCanSyncReturnsFalseWhenNoTokenEvenWithAValidCharacterAuth` (plus de stub `User::isAuthValid`).
- `AdminServiceUserAndCharacterStatsTest::testAUserWhoseCharactersAreAllValidCountsAsValid`.
- `UserRepositoryTest` : E8 et `testFindActiveWithCharactersSkipsAUserWhoDidNotLogInForEightDays`.
- `MigrationsReplayTest` : vert.

`testGetCorpAssetsCharacterPrefersDirectorFromVisibilityConfig` non modifié : la spec (décision A4) garde `getCorpAssetsCharacter()` tel quel.
Docblock de classe #13 de `CharacterRepositoryTest` supprimé (il décrivait l'ancien statut utilisateur, et aucun commentaire n'est ajouté).

## Suites complètes

- Unit (`--testsuite Unit`) : 2679 tests, 11 échecs = les 11 rouges unitaires ci-dessus ; 1 incomplet préexistant (`InstallCostTest::testCopyingInstallCostWithSccSurcharge`, `markTestIncomplete` D11a, fichier non modifié).
- Integration (`--testsuite Integration`, base `eve_app_test_dr`) : 184 tests, 10 échecs = les 10 rouges d'intégration ci-dessus.

## Points pour la phase verte

- Deux rouges dépendent de l'ordre SQL actuel (E10 « M aussi porteur du scope », repli corp) ; après le filtre `c.authStatus` ils deviennent déterministes.
- Supprimer `users.auth_status` dans une nouvelle migration ou en complétant `Version20261019010000` (sans oublier `down()`), puis remigrer `eve_app_test_dr`.

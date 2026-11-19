# #86 — Statut d'autorisation porté par le personnage : note de câblage

Base : `main` @ 770711e (worktree `evetools-wt-DR`).

Cible rappelée : `Character::$authStatus` (`AuthStatus`, défaut `Valid`). `invalid_grant` invalide ce seul personnage ; login / ajout / ré-ajout d'un personnage le revalide lui seul ; les sélections de sync filtrent sur `c.authStatus` ; `users.auth_status` supprimée ; `/me.authStatus` dérivé (invalid si au moins un personnage invalide) ; migration : tous les personnages `valid`.

---

## 1. Boucles par utilisateur : comportement actuel et point d'étranglement

### Constat commun

Aujourd'hui, un personnage révoqué passe par `TokenManager::getValidAccessToken()` → `requestNewAccessToken()` → SSO répond 400 `invalid_grant` → `invalidateAuthOf()` marque **l'utilisateur** invalide, flush, lève `EveAuthRequiredException`. Ensuite l'utilisateur sort des sélections planifiées (repos filtrés sur `u.authStatus`), donc ses alts valides aussi. Dans une sync déjà en cours, les autres personnages continuent (catch par personnage), mais chaque appel suivant pour ce même personnage refait un aller-retour SSO inutile.

| Boucle | Catch par personnage ? | Personnage dont le refresh échoue | Personnage marqué invalide (cible) |
|---|---|---|---|
| `MiningSyncService::canSync` (l.67) | — | `true` dès qu'un token existe | à garder tel quel (le filtrage passe par la sélection, voir §1 reco) |
| `MiningSyncService::syncAll` (l.106) | oui, `catch (\Throwable)` l.133 | compté `failedCharacters`, les autres continuent ; si tous échouent → `failSync`, `lastMiningSyncAt` non mis à jour | avec l'étranglement TokenManager : idem, sans appel SSO |
| `PveSyncService::canSync` (l.86) | — | idem Mining | idem |
| `PveSyncService::syncAll` (l.125) | — (simple comptage `tokenizedCharacters`) | — | inchangé |
| `PveSyncService::syncWalletJournal` (l.242), `syncLootSalesFromTransactions` (321), `syncLootFromContracts` (404), `syncExpensesFromTransactions` (534) | oui, `recordCharacterFailure()` | le personnage est compté une fois dans `failedCharacterIds`, les autres continuent ; 4 tentatives de refresh SSO par sync (une par étape) | avec l'étranglement : 4 `EveAuthRequiredException` immédiates, zéro appel SSO, même comptage |
| `IndustryJobSyncService` (l.115) | n/a | **pas un appel ESI** : table `installerLookup` (attribution d'un job à un personnage de l'utilisateur) | **ne rien changer** : un job installé par un alt révoqué doit rester attribué à cet alt |
| `AssetsSyncService::getCorpAssetsCharacter` (l.262) | n/a | choisit le personnage du directeur qui a le scope, **sans regarder l'auth** ; si l'utilisateur directeur est invalide, `SyncCorporationAssetsHandler` → `canSync()` false → aucune sync corp, pas de repli | ajouter `$character->isAuthValid()` dans la condition pour retomber sur `findWithCorpAssetsAccess()` (voir ambiguïté A4) |
| `WarmupStructureOwnersHandler::__invoke` (l.59) | n/a | lecture des `CachedAsset` du personnage, pas d'ESI | ne rien changer |
| `WarmupStructureOwnersHandler::collectCorporationTokens` (l.196 et boucle corp ~l.205) | oui, `try` par structure autour de `esiClient->get()` (~l.119) | un token révoqué entre dans le pool tournant ; chaque structure qui lui échoit échoue (refresh SSO, invalidation), les autres passent | filtrer `isAuthValid()` dans les deux boucles (propres personnages et membres de corp) : sinon le pool de 5 tokens peut contenir des tokens morts |
| `MatchProjectJobsCommand` (l.55) | oui, `catch (\Throwable)` | warning console, les autres continuent | avec l'étranglement : idem, sans SSO. Pas de filtre nécessaire |
| (hors liste) `MatchJobsProcessor` (~l.56) | oui | idem, logué | idem |
| (hors liste) `WalletProvider` (l.45) | oui, `catch (\Throwable)` → solde inconnu | personnage rapporté avec solde inconnu | idem, sans SSO |

### Point d'étranglement recommandé : `TokenManager`

Garde unique au début de `TokenManager::getValidAccessToken()` **et** de `forceRefresh()` (ou dans `underRefreshLock()`, qui sert aux deux) :

> si `$token->getCharacter()?->isAuthValid() === false` → `throw new EveAuthRequiredException((string) eveCharacterId)` sans appel SSO ni flush.

Pourquoi là et pas dans les boucles :
- **Un seul fichier** couvre toutes les boucles (Mining, 4 étapes PVE, MatchProjectJobs, MatchJobs, WalletProvider, SkillQueueProvider, Scan*Processors, SyncCharacterSkillsProcessor, FixTokenScopesCommand) et tous les appels ESI authentifiés (`EsiClient::buildHeaders()` l.509 appelle `getValidAccessToken()`).
- **Sémantique inchangée des boucles** : un personnage invalide reste compté « en échec » (`failedCharacters`), exactement comme un personnage révoqué aujourd'hui (tests `testSyncAllCountsRevokedCharacterAsFailedAndSyncsTheOthers` de Mining et PVE).
- **Tests non cassés** : Mining/PVE/IndustryJob/Wallet/Assets doublent `TokenManager` et utilisent massivement `createStub(Character::class)`. Un filtre `if (!$character->isAuthValid()) continue;` dans les boucles ferait renvoyer `false` par défaut à tous ces stubs et casserait des dizaines de tests (Mining 10 stubs, PVE 7, Assets 15, IndustryJob 3, Wallet 4, etc.).
- `EveAuthRequiredException` est déjà `UnrecoverableExceptionInterface` : Messenger ne rejoue pas.

Effet de bord à noter : la garde s'applique aussi quand l'access token n'est pas encore expiré. C'est voulu (on n'appelle pas l'ESI avec un personnage connu révoqué) ; après migration tous les personnages sont valides, donc aucun impact initial.

Fraîcheur : la garde lit le `Character` du gestionnaire d'entités. `entityManager->refresh($token)` sous verrou ne recharge pas le personnage. Le worker vide l'EM entre deux messages (`DoctrineClearEntityManagerWorkerSubscriber`, actif par défaut), un état revalidé par un login est donc vu au message suivant. Suffisant.

### Compléments indispensables (sélection, pas boucles)

Les boucles n'ont pas à filtrer, mais les **sélections** doivent :
- `CharacterRepository` : `findWithValidTokens`, `findActiveWithValidTokens`, `findStructureMarketRequester`, `findWithCorpScope` → remplacer `u.authStatus = :validStatus` par `c.authStatus = :validStatus` (garder la jointure `c.user u` pour `lastLoginAt` / `preferredMarketStructureId`).
- `UserRepository::findActiveWithCharacters` : **ne pas** mettre `c.authStatus` dans le `WHERE` (fetch-join `addSelect('c')` : la collection `User::$characters` serait hydratée partiellement, `orphanRemoval: true` sur cette relation, et l'utilisateur reste partiel dans l'identity map pour la suite du message). Remplacer par un `EXISTS` (« au moins un personnage valide ») en sous-requête DQL. Les personnages invalides restants sont ensuite écartés par la garde TokenManager.
- `canSync(Character)` : `AssetsSyncService::canSync` (l.240), `AnsiblexSyncService::hasRequiredScopes` (l.626), `SyncWalletTransactionsCommand` (l.43) → `$character->isAuthValid()` au lieu de `$user->isAuthValid()`.
- Revalidation : `AuthenticationService::updateCharacterToken()` (appelé par login `findOrCreateCharacter` **et** par ré-ajout d'un personnage existant dans `addCharacterToUser`) → `$character->markAuthValid()`. Supprimer `$user->markAuthValid()` l.189. Un personnage nouvellement créé est valide par défaut.

### Fichiers src à modifier (13)

1. `src/Entity/Character.php` — colonne `auth_status` (`string(20)`, `enumType: AuthStatus`, défaut `Valid`), `getAuthStatus()`, `isAuthValid()`, `markAuthInvalid()`, `markAuthValid()`.
2. `src/Entity/User.php` — supprimer colonne et `setAuthStatus` / `isAuthValid` / `markAuthInvalid` / `markAuthValid` ; `getAuthStatus()` devient dérivé des personnages (voir A1).
3. `migrations/Version2026xxxx.php` — `ALTER TABLE characters ADD auth_status VARCHAR(20) DEFAULT 'valid' NOT NULL` puis `ALTER ... DROP DEFAULT` (le mapping n'a pas de défaut SQL : `MigrationsReplayTest` compare au mapping) ; `ALTER TABLE users DROP auth_status` ; `down()` symétrique (recrée `users.auth_status` à `valid`).
4. `src/Service/ESI/TokenManager.php` — garde + `invalidateAuthOf()` appelle `$character->markAuthInvalid()`.
5. `src/Service/ESI/AuthenticationService.php` — revalidation dans `updateCharacterToken()`.
6. `src/Repository/CharacterRepository.php` — 4 filtres.
7. `src/Repository/UserRepository.php` — `EXISTS`.
8. `src/Service/Sync/AssetsSyncService.php` — `canSync`, `getCorpAssetsCharacter`.
9. `src/Service/Sync/AnsiblexSyncService.php` — `hasRequiredScopes`.
10. `src/Command/SyncWalletTransactionsCommand.php`.
11. `src/MessageHandler/WarmupStructureOwnersHandler.php` — `collectCorporationTokens`.
12. `src/Service/Admin/AdminService.php` — 3 requêtes SQL natives (voir A3).
13. `src/State/Provider/Me/UserProvider.php` — inchangé si `User::getAuthStatus()` reste (dérivé) ; sinon dérivation ici.

Docs : `docs/glossary.md` (« Autorisation révoquée » : le personnage, pas l'utilisateur ; symboles `Character::markAuthInvalid()`), `.claude/skills/esi-api/SKILL.md` l.206 et l.210.

Frontend : `authStatus` n'est lu **nulle part** hors `frontend/src/types/index.ts:34` (aucun `.vue`, store ni test). Contrat `/me` conservé (`string`) : aucun changement front requis. Admin : `stores/admin.ts` + `AdminKpiGrid.vue:57` affichent `users.invalid`, `Admin.vue:253` / `AdminKpiGrid.vue:164` affichent `characters.activeSyncScope`.

---

## 2. Dépendances et comment les tests les fournissent

| Dépendance | Harnais | Fixtures existantes à réutiliser |
|---|---|---|
| `Character` (statut, marquage) | unitaire pur, `new Character()` | `tests/Unit/Entity/CharacterTest.php` (y ajouter les tests de statut ; retirer ceux de `UserTest`) |
| `User::getAuthStatus()` dérivé | unitaire, `new User()` + `addCharacter(new Character())` | `tests/Unit/Entity/UserTest.php` |
| `TokenManager` : garde + invalidation du seul personnage | unitaire, `MockHttpClient` + `MockResponse(INVALID_GRANT_BODY, 400)`, EM mock (callback sur `flush`), `LockFactory(new InMemoryStore())` | `tests/Unit/Service/TokenManagerTest.php` : `tokenManagerAnsweringWith()`, `tokenManagerWith()`, `storedTokenOf(User)` (l.768, à étendre pour créer deux personnages : le révoqué et un alt). Garde : personnage `markAuthInvalid()` + `MockHttpClient` qui enregistre les requêtes → assert aucune requête SSO |
| Chaîne réelle handler → EsiClient → TokenManager | unitaire avec vrais `TokenManager` / `EsiClient`, HTTP mocké | `tests/Unit/MessageHandler/SyncCharacterAssetsHandlerTest.php` (`handle()`, `characterWithExpiredAccessToken()`, `$requestedUrls`) : ajouter « personnage déjà invalide → aucune URL demandée, non rejoué » |
| Boucles Mining / PVE (personnage invalide compté en échec, alts synchronisés) | unitaire, `TokenManager` doublé qui lève `EveAuthRequiredException` | `MiningSyncServiceTest::testSyncAllCountsRevokedCharacterAsFailedAndSyncsTheOthers` (l.695), `PveSyncServiceTest::testSyncAllCountsRevokedCharacterAsFailedAndSyncsTheOthers` (l.520) : déjà verts, la sémantique ne change pas → pas de nouveau test requis dans ces services |
| `AuthenticationService` (revalidation ciblée) | unitaire : `MockHttpClient` (POST `EVE_TOKEN_URL` → JSON token, GET `EVE_VERIFY_URL` → `{"CharacterID": …}`), vrai `TokenManager` (clé via helper `encryptionKey()` des tests existants), stub `CharacterService::getCharacterInfo()` (classe non finale), stub `CharacterRepository::findByEveCharacterId()`, EM stub | **aucun test existant** pour ce service : nouveau fichier `tests/Unit/Service/ESI/AuthenticationServiceTest.php`. Cas : login de A ne revalide pas B ; ré-ajout de B (même utilisateur) revalide B |
| `CharacterRepository` (4 sélections) | intégration, base `eve_app_test`, rollback DAMA | `tests/Integration/Repository/CharacterRepositoryTest.php` (`createCharacterWithToken()`, `namesOf()`), `tests/Integration/Repository/CharacterRepositoryStructureMarketRequesterTest.php` (`createRequester()`, `createPilot()`), base `tests/Integration/IntegrationTestCase.php` (`createUser`, `createCharacter`, `flushAndClear`). Cas clé : même utilisateur, alt valide + alt invalide → seul le valide sort |
| `UserRepository::findActiveWithCharacters` (EXISTS, collection complète) | intégration | aucun test existant ; nouveau `tests/Integration/Repository/UserRepositoryTest.php` sur `IntegrationTestCase` : utilisateur avec 1 valide + 1 invalide → retourné **avec ses 2 personnages** ; utilisateur tout invalide → absent |
| `findWithCorpScope` (via `findWithCorpAssetsAccess`) | intégration | `CharacterRepositoryTest` (même helpers, scope via `EveToken::setScopes`) |
| `AssetsSyncService::canSync`, `getCorpAssetsCharacter` | unitaire, stubs | `tests/Unit/Service/Sync/AssetsSyncServiceTest.php` l.195-215, 259-320, 638 : stub `Character::isAuthValid` |
| `AnsiblexSyncService::canSync` | unitaire | aucun test direct (`TriggerAnsiblexSyncHandlerTest` stubbe le service) ; test minimal à créer si on veut couvrir |
| `SyncWalletTransactionsCommand`, `WarmupStructureOwnersHandler` | — | aucun test existant ; changement d'une ligne chacun, couvert par revue (voir A6) |
| `AdminService` SQL | unitaire, `Connection` stub (renvoie 0) | `tests/Unit/Service/Admin/AdminServiceTest.php` : ne vérifie que les clés, ne casse pas, ne prouve rien sur le SQL |
| Migration | intégration | `tests/Integration/Migrations/MigrationsReplayTest.php` : rejoue toutes les migrations et compare au mapping → échoue tant que la migration manque ou diverge |
| Base de test | — | `eve_app_test` est construite depuis le mapping : relancer `make test-db` après la modification d'entité, sinon les tests d'intégration échouent sur colonne manquante |

---

## 3. Tests existants qui casseront

Hypothèse : `User::getAuthStatus()` conservé et dérivé (A1) ; `User::isAuthValid/markAuthInvalid/markAuthValid/setAuthStatus` supprimés.

**Erreur fatale (méthode supprimée)**
- `tests/Unit/Entity/UserTest.php` : `testNewUserHasValidAuthStatus` (`isAuthValid`), `testMarkAuthInvalid`, `testMarkAuthValid` → à déplacer dans `CharacterTest` ; garder un test du statut dérivé côté `User`.
- `tests/Integration/Repository/CharacterRepositoryTest.php` : `testFindWithValidTokensSkipsCharactersOfUsersWhoseAuthIsInvalid` (l.39), `testFindActiveWithValidTokensSkipsRecentlyActiveUsersWhoseAuthIsInvalid` (l.58) → `$revoked->markAuthInvalid()` sur le personnage ; ajouter le cas « alt valide du même utilisateur conservé ». Docblock de classe (#13) à mettre à jour.
- `tests/Integration/Repository/CharacterRepositoryStructureMarketRequesterTest.php::testIgnoresUsersWhoseAuthenticationIsInvalid` (l.83, `User::setAuthStatus`) → invalider le personnage ; ajouter « alt invalide avec scope ignoré, alt valide avec scope retenu ».
- `tests/Unit/Service/Sync/AssetsSyncServiceTest.php` : `testCanSyncReturnsFalseWhenUserAuthInvalid` (l.196), `testCanSyncReturnsTrueWhenTokenAndAuthValid` (l.209), `testCanSyncReturnsFalseWhenNoTokenEvenWithValidUserAuth` (l.637) → `$user->method('isAuthValid')` sur une méthode qui n'existe plus : PHPUnit lève une erreur de configuration du stub. À réécrire sur `Character::isAuthValid`.

**Cassé par le filtre ajouté dans `getCorpAssetsCharacter`**
- `AssetsSyncServiceTest::testGetCorpAssetsCharacterPrefersDirectorFromVisibilityConfig` (l.259) : le stub `Character` renvoie `false` pour `isAuthValid` → le repli est retourné au lieu du directeur. Stubber `isAuthValid → true`. (`testGetCorpAssetsCharacterFallsBackWhenDirectorLacksScope` reste vert.)

**Toujours verts mais devenus faux sur l'intention (à réécrire en phase rouge)**
- `tests/Unit/Service/TokenManagerTest.php::testRefreshWithRevokedRefreshTokenPersistsTheUserAuthStatusAsInvalid` (l.133) : passe encore avec un `getAuthStatus()` dérivé (l'unique personnage est invalide), mais doit asserter le statut du **personnage** flushé et qu'un alt du même utilisateur reste valide. Les asserts `AuthStatus::Valid` sur `$user` l.173, 192, 223, 658 restent verts ; les basculer sur le personnage.
- `tests/Unit/MessageHandler/SyncCharacterAssetsHandlerTest.php::testRevokedEveGrantDuringSyncPersistsTheUserAuthStatusAsInvalid` (l.70) et la capture `$authStatusesAtFlush` (l.85) : même situation.

**Non impactés (vérifié)** : `MiningSyncServiceTest`, `PveSyncServiceTest`, `PveSyncServiceCharacterizationTest::testRevokedTokenStopsEveryStepBeforeAnyEsiCall` (TokenManager doublé) ; `EsiAuthFailureListenerTest`, `EveAuthRequiredRetryPolicyTest`, `CorporationServiceTest`, `UpdateCorpVisibilityProcessorTest`, `WalletProviderTest` (n'utilisent que l'exception) ; `AdminServiceTest` (clés seulement) ; `TriggerAnsiblexSyncHandlerTest`, `TriggerStructureMarketSyncHandlerTest`, `SyncIndustryJobsHandlerTest`, `ScheduledCharacterSyncFanOutTest` (repos/services doublés). Aucun test frontend ni fonctionnel ne lit `authStatus`.

---

## 4. Cas ambigus (option conservatrice recommandée)

- **A1 — Où dériver `/me.authStatus`.** `User::getAuthStatus()` dérivé (invalid si un personnage invalide) vs calcul dans `UserProvider`. *Reco : dérivé dans `User`* (domaine, garde les asserts existants compilables) ; supprimer `User::isAuthValid()` pour qu'aucune sélection de sync ne réutilise un statut agrégé.
- **A2 — Personnage sans token.** Un personnage sans `EveToken` est-il « valid » ? *Reco : oui, statut indépendant du token* ; les sélections exigent déjà la jointure `c.eveToken`. Il ne rend pas `/me` invalide.
- **A3 — Stats admin `users.valid / users.invalid`.** Plus de colonne. *Reco : redéfinir `invalid` = utilisateurs ayant au moins un personnage invalide* (cohérent avec `/me`, la tuile rouge `AdminKpiGrid.vue:57` garde son sens « utilisateurs à reconnecter ») ; `characters.withValidTokens` et `activeSyncScope` filtrent sur `c.auth_status`. À confirmer par Sylvain : alternative « compter des personnages invalides » changerait le libellé de la tuile.
- **A4 — Directeur invalide pour les assets corp.** Aujourd'hui pas de repli (le handler refuse). *Reco : filtrer `isAuthValid()` dans `getCorpAssetsCharacter`* pour permettre le repli `findWithCorpAssetsAccess()` (déjà filtré). C'est un changement de comportement visible (un autre membre de la corp sert de jeton) ; si Sylvain le refuse, garder le directeur et laisser `canSync()` refuser.
- **A5 — Garde TokenManager même avec un access token non expiré.** *Reco : oui, toujours lever* : un personnage connu révoqué n'appelle plus l'ESI. Impact HTTP : `EsiAuthFailureListener` renvoie 401 `EVE_AUTH_REQUIRED` et `frontend/src/services/api.ts` (`handleUnauthorized`) **déconnecte l'utilisateur sur tout 401**. C'est déjà le cas aujourd'hui (chaque appel retente le SSO et reçoit `invalid_grant`), donc pas de régression, mais un alt révoqué touché par un endpoint interactif non protégé par un catch (ex. `SyncCharacterSkillsProcessor`, `ScanContractsProcessor`) déconnecte toute la session. Hors périmètre #86 ; à ouvrir en issue séparée (distinguer `EVE_AUTH_REQUIRED` d'un JWT expiré côté front).
- **A6 — `WarmupStructureOwnersHandler` et `SyncWalletTransactionsCommand` sans tests.** *Reco : modifier la ligne de filtre seulement, sans créer de harnais dédié* (commande manuelle et warmup best-effort) ; mentionner dans la PR.
- **A7 — Utilisateur dont tous les personnages sont invalides.** Exclu par l'`EXISTS` de `findActiveWithCharacters` et par les filtres `CharacterRepository` → plus aucune sync planifiée, comme aujourd'hui. Une sync manuelle (`SyncMiningProcessor`, `SyncPveProcessor`) aboutit à « Sync failed for all N characters » sans appel SSO. *Reco : garder ce comportement.*
- **A8 — `TriggerAnsiblexSyncHandler` / `RefreshAnsiblexProcessor` sur le personnage principal.** Si le main est invalide mais un alt valide, la sync Ansiblex est sautée (pas de bascule sur un alt). *Reco : conserver*, la bascule serait une nouvelle règle métier.
- **A9 — Migration.** Tous les personnages `valid`, y compris ceux des utilisateurs actuellement `invalid` : au prochain run planifié, chaque personnage révoqué refait un appel SSO et se ré-invalide seul (rafale ponctuelle de 400 SSO, sans coût sur le budget d'erreurs ESI). *Reco : accepter* (spec) ; rappeler `make db-migrate` en prod au déploiement.
- **A10 — Exposer le statut par personnage dans `/me.characters[]`.** Utile pour indiquer quel alt reconnecter, mais non demandé. *Reco : hors périmètre*, le contrat front reste inchangé.

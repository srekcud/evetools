# #86 — Phase verte : statut d'autorisation porté par le personnage

Worktree `evetools-wt-DR`, branche `fix/character-auth-status`, base 0be4c9b. Rien n'est commité. `tests/` non modifié.

## Modifications src

| Fichier | Changement |
|---|---|
| `src/Entity/User.php` | colonne `authStatus` supprimée ; `setAuthStatus/isAuthValid/markAuthInvalid/markAuthValid` supprimés ; `getAuthStatus()` dérivé : `Invalid` dès qu'un personnage est invalide (R6) |
| `migrations/Version20261019010000.php` | `up()` : + `ALTER TABLE users DROP auth_status` ; `down()` : recrée `users.auth_status VARCHAR(20) DEFAULT 'valid' NOT NULL`, puis `DROP DEFAULT` (schéma d'origine sans défaut, cf. `Version20260125133709`), puis supprime `characters.auth_status` ; description mise à jour |
| `src/Service/ESI/TokenManager.php` | `refuseRevokedCharacter()` appelé en tête de `getValidAccessToken()` et `forceRefresh()` : personnage invalide → `EveAuthRequiredException` sans SSO ni verrou (R3, A5) ; `invalidateAuthOf()` → `$character->markAuthInvalid()` (R2) |
| `src/Service/ESI/AuthenticationService.php` | `$user->markAuthValid()` supprimé du login ; `updateCharacterToken()` (login et ré-ajout) → `$character->markAuthValid()` (R5) |
| `src/Repository/CharacterRepository.php` | 4 filtres `u.authStatus` → `c.authStatus` (jointure `c.user u` conservée) |
| `src/Repository/UserRepository.php` | `findActiveWithCharacters()` : `EXISTS` (sous-requête DQL sur `Character`) au lieu d'un filtre dans le `WHERE` du fetch-join : collection complète |
| `src/Service/Sync/AssetsSyncService.php` | `canSync()` → `$character->isAuthValid()` ; commentaire devenu faux supprimé ; `getCorpAssetsCharacter()` non modifié (A4) |
| `src/Service/Sync/AnsiblexSyncService.php` | `hasRequiredScopes()` → `$character->isAuthValid()` |
| `src/Command/SyncWalletTransactionsCommand.php` | idem |
| `src/MessageHandler/WarmupStructureOwnersHandler.php` | `collectCorporationTokens()` : tokens des personnages invalides exclus (propres personnages et membres de corp) |
| `src/Service/Admin/AdminService.php` | `users.invalid` = `COUNT(DISTINCT user_id)` des personnages invalides, `users.valid` = total − invalid (A3) ; `withValidTokens` et `activeSyncScope` filtrent `c.auth_status` (jointure `users` retirée de `withValidTokens`, devenue inutile) |
| `src/State/Provider/Me/UserProvider.php` | inchangé (`User::getAuthStatus()` dérivé) |

Doc : `.claude/skills/esi-api/SKILL.md` §3 « Refresh failures » réécrit (personnage invalidé seul, garde sans SSO, sélections, revalidation, `/me` dérivé) ; nom de méthode corrigé au passage (`refreshAccessToken()` n'existe pas → `requestNewAccessToken()`).

## Base isolée

`eve_app_test_dr` : le `prev` échouait (l'ancienne version de la migration n'avait pas supprimé `users.auth_status`), base recréée (drop/create `--env=test`, nom vérifié via `current_database()`) et migrée. `down()` puis `up()` rejoués : OK, `doctrine:schema:validate` en phase.

## Gates

- Unit : 2679 tests OK, 1 incomplet préexistant (`InstallCostTest`), 1 notice préexistante (GMP/BCMath, web-push).
- Integration (`eve_app_test_dr`) : 184 tests OK, dont `MigrationsReplayTest`.
- Functional : suite vide (« No tests executed »).
- PHPStan : 63 erreurs (seuil 63), aucune dans les fichiers touchés.
- Deptrac : 35 violations (seuil 35).
- Infection `--filter=src/Service/ESI/TokenManager.php,src/Entity/Character.php` (mode integration) : 168 mutants, MSI 95 %, couverture 97 %, covered MSI 98 %. Tous les mutants de la garde et des accesseurs de statut sont tués.

### Survivants (tous sur du code préexistant, non touché)

Échappés :
1. `Character.php:224` `isMain()` : `$this->user?->` → `$this->user->` (NullSafeMethodCall).
2. `TokenManager.php:106` : suppression de l'en-tête `Content-Type` du POST SSO (MockHttpClient le déduit du body tableau).
3. `TokenManager.php:180` `extractScopesFromJwt()` : `return []` supprimé quand le payload n'est pas un tableau (la suite tombe sur `[]` de toute façon : mutant équivalent).

Non couverts : `Character.php:161` (`setEveToken(null)` côté propriétaire), `Character.php:198` `getCreatedAt` et `:208` `setLastSyncAt` (visibilité), `TokenManager.php:86` (`return` du chemin token sans personnage dans `underRefreshLock`).
Skipped (délai) : 7 mutants `PublicVisibility` de `Character.php` (l.73-145).

## Rappel déploiement

`make db-migrate` en prod (migration `Version20261019010000`) ; tous les personnages démarrent valides (A9).

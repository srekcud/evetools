# Spec #86 — Statut d'autorisation porté par le personnage

## Contexte

Le statut d'autorisation est porté par l'utilisateur (`users.auth_status`). Quand EVE SSO refuse le refresh token d'un personnage (`invalid_grant`), `TokenManager::invalidateAuthOf()` passe l'**utilisateur** en invalide : tous ses personnages sortent des syncs planifiées, alts compris. À l'inverse, un login avec n'importe quel personnage (`AuthenticationService`) remet l'utilisateur valide, alors qu'un alt révoqué le reste : le refresh de cet alt retente EVE SSO à chaque sync.

Note de câblage : `.claude/tasks/86-wiring.md` (worktree de l'issue).

## Règles métier

- **R1 — Le statut appartient au personnage.** `Character` porte un statut d'autorisation (`AuthStatus`, valide par défaut). La colonne `users.auth_status` disparaît.
- **R2 — Une autorisation révoquée ne touche que son personnage.** `invalid_grant` au refresh du personnage A passe A en invalide ; les autres personnages du même utilisateur restent valides.
- **R3 — Un personnage invalide n'appelle plus EVE SSO.** `TokenManager` lève `EveAuthRequiredException` pour un personnage invalide sans requête SSO, que son access token soit expiré ou non. Les boucles de sync par utilisateur (mining, PVE, jobs…) l'attrapent déjà par personnage : le personnage compte en échec, les autres sont synchronisés.
- **R4 — Les sélections de sync filtrent sur le personnage.** `CharacterRepository::findWithValidTokens()`, `findActiveWithValidTokens()`, `findStructureMarketRequester()`, `findWithCorpScope()`, `AssetsSyncService::canSync()`, `AnsiblexSyncService` (scopes requis), `SyncWalletTransactionsCommand`, `WarmupStructureOwnersHandler` utilisent le statut du personnage. `UserRepository::findActiveWithCharacters()` garde un utilisateur qui a au moins un personnage valide, avec **tous** ses personnages chargés.
- **R5 — Se reconnecter revalide le seul personnage concerné.** Un login ou l'ajout d'un personnage déjà rattaché à l'utilisateur repasse **ce** personnage en valide. Un login avec le main ne revalide pas un alt révoqué.
- **R6 — `/me` inchangé côté contrat.** `authStatus` vaut `invalid` si au moins un personnage de l'utilisateur est invalide, `valid` sinon.
- **R7 — Statistiques admin.** `users.invalid` compte les utilisateurs ayant au moins un personnage invalide ; `users.valid` les autres. `characters.withValidTokens` et `characters.activeSyncScope` comptent les personnages valides avec token.

## Exemples chiffrés

Utilisateur U avec le main M et l'alt A, tous deux avec token et scopes ; U connecté il y a 2 jours.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Refresh de A → `invalid_grant` | U invalide | A invalide, M valide, U `/me` `invalid` |
| E2 | Après E1, `findActiveWithValidTokens()` | `[]` | `[M]` |
| E3 | Après E1, `findWithValidTokens()` | `[]` | `[M]` |
| E4 | Après E1, demande d'un access token pour A (access token encore valide) | token rendu | `EveAuthRequiredException`, aucune requête SSO |
| E5 | Après E1, demande d'un access token pour A (expiré) | requête SSO, `invalid_grant` | `EveAuthRequiredException`, aucune requête SSO |
| E6 | Après E1, login avec M | U valide, A resynchronisé et rejeté à chaque refresh | M valide, A reste invalide, `/me` `invalid` |
| E7 | Après E1, ré-ajout de A au même utilisateur (nouveau token) | U inchangé | A valide, `/me` `valid` |
| E8 | Après E1, `findActiveWithCharacters()` | U absent | U présent avec ses 2 personnages |
| E9 | M et A invalides, `findActiveWithCharacters()` | U absent | U absent |
| E10 | Après E1, `findStructureMarketRequester()` : A seul porte le scope marché structure | M ou A selon l'ordre | `null` si seul A a le scope ; M s'il a aussi le scope |
| E11 | Après E1, `AssetsSyncService::canSync(A)` / `canSync(M)` | `false` / `false` | `false` / `true` |
| E12 | Stats admin après E1 (1 seul utilisateur) | `users.invalid` 1, `withValidTokens` 0 | `users.invalid` 1, `withValidTokens` 1 |

## Cas limites

- Personnage sans token : statut valide (le statut ne dépend pas du token) ; les sélections exigent déjà un token.
- Utilisateur dont tous les personnages sont invalides : plus aucune sync planifiée (comme aujourd'hui) ; une sync manuelle échoue pour chaque personnage sans appel SSO.
- Migration : tous les personnages démarrent valides, y compris ceux des utilisateurs aujourd'hui invalides. Un personnage réellement révoqué se ré-invalide seul à son prochain refresh (un appel SSO refusé par personnage).

## Hors périmètre

- Exposer le statut par personnage dans `/me.characters[]` (indiquer quel alt reconnecter).
- Le frontend déconnecte la session sur tout 401, y compris `EVE_AUTH_REQUIRED` d'un alt : issue séparée.
- Bascule des assets corp ou d'Ansiblex vers un autre personnage quand le directeur ou le main est invalide.
- Tests dédiés à `SyncWalletTransactionsCommand` et `WarmupStructureOwnersHandler` (filtre d'une ligne, couvert par revue).

## Décision prise en autonomie

Mode nuit, spec validée sans Sylvain. Options les plus conservatrices retenues sur la note de câblage :
- A3 : la tuile admin « invalid » garde son sens « utilisateurs à reconnecter » (au moins un personnage invalide).
- A4 : pas de repli vers un autre membre de la corporation quand le directeur est invalide : `getCorpAssetsCharacter()` n'est pas modifié, `canSync()` refuse comme aujourd'hui.
- A5 : un personnage invalide n'appelle jamais EVE SSO, même avec un access token encore valide.
- A8 : pas de bascule Ansiblex vers un alt.
- A9 : migration « tous valides », auto-correction au prochain refresh plutôt que conserver l'exclusion de tous les personnages d'un utilisateur invalide.

## Glossaire

« Autorisation révoquée » modifiée dans [`docs/glossary.md`](../glossary.md) : c'est le personnage qui passe en auth invalide, seul ce personnage sort des syncs, et il redevient valide quand le joueur le reconnecte par EVE SSO. Symboles : `AuthStatus::Invalid`, `Character::markAuthInvalid()`, `EveAuthRequiredException`.

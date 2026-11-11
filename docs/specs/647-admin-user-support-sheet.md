# Spec #647 : fiche support utilisateur (admin)

Circuit l, mode nuit, 0 sous-agent. Empilée sur #648 (carte « Characters to repair »).

## Objectif
Un admin cherche un utilisateur par nom de personnage ou id EVE, voit sa fiche (personnages, état des tokens, scopes manquants, dernière donnée reçue par type, dernière connexion) et peut relancer ses synchros utilisateur.

## Règles
1. Trois opérations API Platform, toutes admin seulement (`AdminChecker::isAdmin` dans le provider/processor, comme `/admin/stats`) : non authentifié = 401, authentifié non admin = 403, aucun accès aux données avant le contrôle.
   - `GET /admin/users?search=<texte>` : collection de résultats.
   - `GET /admin/users/{userId}` : fiche (`uriVariables` `Link`). Utilisateur inconnu ou `userId` invalide = 404.
   - `POST /admin/users/{userId}/sync` body `{type}` : relance une synchro utilisateur, renvoie `ActionResultResource`.
2. Recherche : texte trimé de 2 caractères minimum (sinon liste vide). Composé uniquement de chiffres : correspondance exacte sur `eveCharacterId` OU sous-chaîne du nom. Sinon : sous-chaîne insensible à la casse sur le nom du personnage. Un utilisateur par résultat même si plusieurs de ses personnages correspondent. 20 résultats au plus, triés par nom du personnage principal. Résultat : `userId`, `mainCharacterName` (null si pas de principal), `mainEveCharacterId`, `characterCount`, `lastLoginAt`.
3. Fiche : `userId`, `createdAt`, `lastLoginAt` (null = jamais), `characters[]` (personnage principal d'abord, puis par nom), `syncs[]`.
4. Personnage : `eveCharacterId`, `name`, `isMain`, `tokenState`, `accessTokenExpiresAt` (null sans token), `missingScopes` (`REQUIRED_SCOPES` moins scopes du token ; liste vide sans token), `lastSyncAt` (`Character::getLastSyncAt`, null si jamais).
5. `tokenState` : `no_token` (aucun `EveToken`), `invalid` (`AuthStatus::Invalid`), `expiring` (auth valide et `EveToken::isExpiringSoon()`), `valid` sinon. Priorité : no_token, invalid, expiring, valid. Un access token expiré est normal (rafraîchi à la demande) : `expiring` est informatif, il ne compte pas comme réparation (la liste #648 reste la source).
6. Synchros : une ligne par type `industry`, `blueprints`, `character-orders`, `pve`, `mining`, `assets`, `wallet`, `planetary` : `type`, `lastDataAt` (ISO ou null), `triggerable` (bool).
   - `lastDataAt` = dernière donnée écrite pour l'utilisateur, MAX de : `CachedIndustryJob.cachedAt`, `OwnedBlueprint.syncedAt`, `CachedCharacterOrder.syncedAt`, `UserPveSettings.lastSyncAt` (pve), `UserLedgerSettings.lastMiningSyncAt` (mining), `CachedAsset.cachedAt`, `CachedWalletTransaction.cachedAt`, `PlanetaryColony.cachedAt` (lignes rattachées aux personnages de l'utilisateur). Aucune donnée : null (inconnu), jamais une date par défaut.
   - `triggerable` vrai pour `industry` (`SyncUserIndustryJobs`), `blueprints` (`SyncUserBlueprints`), `character-orders` (`SyncUserCharacterOrders`), `pve` (`SyncUserPveData`), `mining` (`SyncUserMiningData`). Faux pour les autres (pas de message utilisateur existant).
7. `POST …/sync` : type non triggerable = `success: false`, message `Unknown sync type`, rien dispatché. Sinon dispatch du message avec le `userId` de l'URL sur le bus (file `async` déjà routée), `success: true`, message `Sync triggered: <type>`. Utilisateur inconnu = 404, rien dispatché.
8. Mercure : les handlers `SyncUser*` publient déjà leurs événements sur les topics de l'utilisateur ciblé (`industry-jobs`, `blueprints`, …, déclarés dans `getTopicsForUser()`). Pas de nouveau topic ; le front admin propose un bouton « Rafraîchir la fiche ».
9. RGPD : la fiche n'expose que noms de personnages, ids EVE, dates de connexion et états de tokens ; jamais montants de wallet, assets, token ou refresh token. Chaque consultation et chaque relance journalise (niveau info) `adminId` et `userId` cibles, sans nom.
10. Front : carte `UserSupportCard` dans `Admin.vue` (champ de recherche avec délai, liste de résultats, fiche : tableau des personnages avec pastille d'état, tableau des synchros avec bouton « Relancer » si `triggerable`, bouton « Rafraîchir »). Les lignes de `CharactersToRepairCard` (userId non nul) émettent `open-user` ; `Admin.vue` ouvre la fiche et fait défiler jusqu'à la carte. i18n en et fr. Pas d'impersonation, pas d'édition.

## Exemples
| Entrée | Résultat |
|---|---|
| `search=a` | `[]` |
| `search=2113001` avec un personnage de cet id et un autre dont le nom contient `2113001` | les deux utilisateurs |
| `search=pilot` | utilisateurs dont un personnage contient `pilot`, max 20 |
| personnage sans token | `tokenState=no_token`, `missingScopes=[]`, `accessTokenExpiresAt` absent |
| token valide expirant dans 2 min | `expiring` |
| token valide, 1 scope retiré | `valid`, `missingScopes=[<scope>]` |
| `authStatus=Invalid` et token expiré | `invalid` |
| utilisateur sans aucune donnée | chaque `lastDataAt` null |
| `POST sync {type:"assets"}` | `success:false`, aucun dispatch |
| non admin, les trois routes | 403, `POST` ne dispatche rien |

## Cas limites
- Utilisateur sans personnage principal : fiche et résultat valides, tri par nom.
- Les clés null sont omises par API Platform : le front les lit comme absentes.
- Paramètre `search` absent = liste vide.

## Hors périmètre
Impersonation, édition, wallet, assets, synchros par personnage (assets, wallet, planetary non relançables ici), suppression de compte, journal de connexion par jour (#650/#651).

## Décisions
- `lastDataAt` et non « dernière synchro » : aucun horodatage de synchro par utilisateur et par type n'existe, pas de migration la nuit ; c'est la date de la dernière donnée écrite. Une synchro sans changement ne la fait pas avancer pour les types qui ne réécrivent pas leurs lignes.
- `expiring` suit l'issue (valid, expiring, invalid) mais ne déclenche aucune alerte.
- Pas de complétion Mercure côté admin : le message cible les handlers existants, qui notifient l'utilisateur, pas l'admin.

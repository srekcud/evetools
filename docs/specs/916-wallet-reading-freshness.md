# Spec #916 : fraîcheur de la lecture du wallet dans les achats et les ventes d'un projet

Statut : validée en autonomie (mode nuit). Décision de Sylvain sur #916 : périmètre complet en une PR.

## Contexte

ESI met `/characters/{id}/wallet/transactions` en cache 3600 s (`x-server-cache-ttl: 3600`). La sync horaire (`SyncScheduler`) est hors phase avec l'`Expires` d'ESI : une transaction arrive en base jusqu'à 2 h après. La fenêtre Achats d'une ligne de BOM et l'onglet Ventes ne disent rien de cette attente. `syncCharacterTransactions()` sort sans signal quand le personnage n'a pas de token ou pas le scope wallet.

## Règles métier

1. **Lecture du wallet** : par personnage, `readAt` (fin de la dernière lecture réussie) et `nextReadAt` (`Expires` de la réponse ESI). Table `character_wallet_reading` (un enregistrement par personnage, supprimé avec lui). Migration à ajouter.
2. `EsiClient::getWithExpiry(endpoint, token)` : un GET non paginé qui renvoie `PaginatedEsiResponse` (`items`, `expiresAt`), `expiresAt` lu par `expiresAt()` existant ; la pagination du wallet passe par `from_id`, pas `page`, donc `getPaginatedWithExpiry` n'est pas appelé. `get()` ne change pas.
3. `WalletTransactionSyncService::syncCharacterTransactions()` lit chaque page via `getWithExpiry()` et, en fin de boucle réussie (y compris quand ESI renvoie 0 transaction), enregistre `readAt = maintenant` et `nextReadAt = min des Expires des pages lues`. Un `Expires` absent donne `nextReadAt = readAt + 3600 s` (constante `WALLET_CACHE_TTL_SECONDS`, TTL documenté d'ESI).
4. Échec de lecture (exception ESI) : `readAt` inchangé, `nextReadAt = maintenant + 900 s` (constante `WALLET_RETRY_DELAY_SECONDS`) pour ne pas relancer à chaque tick ; `syncError` est publié comme aujourd'hui. Un personnage jamais lu qui échoue reçoit un enregistrement avec `readAt = null`.
5. **Planification** : `SyncWalletTransactions` passe d'1 heure à 5 minutes. Son handler ne met en file `SyncCharacterWalletTransactions` que pour un personnage actif au scope wallet qui est **dû** : aucun enregistrement, ou `nextReadAt <= maintenant`. Le retard maximal après l'`Expires` est donc d'un tick (5 min). `SyncTracker('wallet')` garde son rôle ; il compte les personnages mis en file sur les personnages éligibles.
6. **État rendu au front** : `WalletReadingResource` par personnage de l'utilisateur courant (`$user->getCharacters()`), principal d'abord : `characterName`, `status`, `readAt` (ISO 8601 ou `null`), `nextReadAt` (idem). `status` :
   - `no_token` : le personnage n'a pas de token ;
   - `missing_scope` : token sans `esi-wallet.read_character_wallet.v1` ;
   - `never_read` : scope présent, aucune lecture réussie ;
   - `read` : au moins une lecture réussie.
   Le calcul vit dans un service unique `WalletReadingStateReader::forUser(User)`, appelé par les deux providers.
7. `GET /industry/projects/{projectId}/purchase-suggestions` ajoute `walletReadings: WalletReadingResource[]` à `PurchaseSuggestionListResource`.
8. `GET /industry/projects/{projectId}/sale-suggestions` ne renvoie plus un tableau mais `ProjectSaleSuggestionListResource` (`id` = projectId, `suggestions: ProjectSaleSuggestionResource[]`, `walletReadings`). Le front de ventes est adapté dans la même PR.
9. **Front, fenêtre Achats** (`LinePurchases.vue`) et **onglet Ventes** : une puce par personnage « Wallet lu à 21:04 · prochaine lecture vers 22:05 » (heure locale), « jamais lu » pour `never_read`, « scope wallet manquant » (avec le nom du personnage, mise en avant) pour `missing_scope`, « pas de token » pour `no_token`. État vide (aucune suggestion) : phrase qui donne l'heure la plus tardive des `nextReadAt` des personnages `read` comme borne d'apparition d'une transaction faite en jeu, et renvoie à la saisie manuelle.
10. **Temps réel** : tant que la fenêtre Achats ou l'onglet Ventes est ouvert, le front écoute le topic Mercure `wallet-transactions` (store `sync.ts`) : `syncStarted` affiche « Lecture du wallet… » ; `syncCompleted` recharge les suggestions et l'état. Un achat absent avant le rechargement et présent après (`alreadyLinked` via `AutomaticPurchaseLinker`, ou nouvelle suggestion) est mis en évidence « nouveau » jusqu'à la fermeture de la fenêtre.
11. Textes i18n en et fr ; pas de commentaire dans le code.

## Exemples chiffrés (futurs tests rouges)

| Cas | Entrée | Sortie |
|---|---|---|
| Lecture réussie | ESI renvoie 2 transactions, `Expires` = 21:04:00, lecture à 20:04:10 | `readAt` 20:04:10, `nextReadAt` 21:04:00 |
| Zéro transaction | ESI renvoie `[]`, `Expires` 21:04:00 | enregistrement créé, mêmes dates |
| Deux pages `from_id` | Expires 21:04:00 puis 21:03:30 | `nextReadAt` 21:03:30 |
| `Expires` absent | lecture à 20:04:10 | `nextReadAt` 21:04:10 |
| Échec ESI | existant `readAt` 19:00, échec à 20:04 | `readAt` 19:00, `nextReadAt` 20:19 |
| Handler, personnage dû | `nextReadAt` 21:04:00, maintenant 21:04:00 | mis en file |
| Handler, pas dû | `nextReadAt` 21:04:00, maintenant 21:03:59 | pas mis en file |
| Handler, jamais lu | aucun enregistrement | mis en file |
| Handler, sans scope | token sans scope wallet | pas mis en file |
| État | A (principal, lu), B (scope absent), C (token `null`), D (scope, jamais lu) | A `read`, B `missing_scope`, C `no_token`, D `never_read`, dans cet ordre de personnage (principal d'abord) |
| Suggestions d'achat | aucune transaction, 2 personnages | `suggestions = []`, `walletReadings` de longueur 2 |
| Ventes | aucune suggestion | `suggestions = []`, `walletReadings` présent |

## Cas limites

- Horloge : `nextReadAt` déjà passé sans lecture (worker arrêté) : le personnage est dû, lu au prochain tick ; le front affiche l'heure passée telle quelle, sans « prochaine lecture dans le futur » inventée.
- Utilisateur sans personnage : `walletReadings = []`.
- Plusieurs lectures concurrentes d'un même personnage (manuelle + planifiée) : upsert sur `character` unique, la dernière écriture gagne.
- Un personnage retiré du compte : son enregistrement est supprimé en cascade ; aucun état d'un personnage d'un autre utilisateur n'est jamais renvoyé.
- Transaction ESI avec le même `Expires` que la lecture précédente (cache non expiré côté ESI) : pas d'erreur, état mis à jour tel quel.

## Accès et fraîcheur

- L'état ne porte que sur `$user->getCharacters()` de l'appelant, pas sur ceux des autres participants, et ne contient ni id de transaction ni montant.
- Achats : propriétaire seul (`assertOwner`, inchangé) ; ventes : admin ou propriétaire (`assertAdminOrOwner`, inchangé). Aucun nouvel identifiant reçu d'un autre périmètre, aucune donnée de corporation.
- Source : `character_wallet_reading` (écrit par la sync) et `EveToken` (scopes lus à la requête, donc frais).

## Tests existants contredits ou modifiés

- `tests/Unit/Service/Sync/WalletTransactionSyncServiceTest.php` : les doubles d'`EsiClient::get` passent à `getWithExpiry`.
- `tests/Unit/Scheduler/SyncSchedulerIntervalsTest.php` (et `SyncSchedulerMissedRunsTest.php` si l'intervalle y est figé) : `SyncWalletTransactions` à 5 minutes.
- `tests/Unit/MessageHandler/CharacterSyncHandlersTest.php` et le test du handler de fan-out : filtre « dû ».
- `tests/Unit/State/Provider/Industry/Project/ProjectSaleSuggestionProviderTest.php`, `tests/Integration/State/Provider/Industry/Project/ProjectSaleSuggestionProviderTest.php`, `tests/Integration/State/Processor/Industry/Project/SaleFromWalletTransactionTest.php`, `tests/Functional/Api/Industry/ProjectJobAndPurchaseTrackingApiTest.php` : forme de la réponse des ventes (liste enveloppée).
- `tests/Unit/State/Provider/Industry/PurchaseSuggestionProviderTest.php` : nouveau champ, constructeur.

## Hors périmètre

- Un wallet de corporation, les journaux wallet (`WalletJournalSyncService`, même TTL mais autre sync).
- Une transaction toujours absente 2 h après : autre bug (filtre de date de création du projet, autre compte).
- Forcer une lecture à la demande depuis la fenêtre (ESI renverrait le même cache).
- Token présent mais invalide ou expiré : non distingué (reste filtré par `findActiveWithValidTokens`).
- Notification push d'un nouvel achat lié.

## Décisions (tranchées sur recommandation)

- D1 : nouvelle méthode `getWithExpiry` plutôt que `getPaginatedWithExpiry`, car `from_id` remplace `page` sur cet endpoint ; `expiresAt()` et `PaginatedEsiResponse` sont réutilisés.
- D2 : tick de 5 min filtré par « dû » plutôt qu'un message différé calé sur l'`Expires` : pas de dépendance au transport différé de Messenger, rattrape un worker arrêté.
- D3 : état renvoyé par les deux providers via un service commun ; la réponse des ventes devient une liste enveloppée (rupture de forme absorbée dans la PR, un seul consommateur front).
- D4 : retry de 15 min après échec pour ne pas consommer le budget d'erreurs ESI à chaque tick.

## Glossaire

Ajout : **Lecture du wallet** (Wallet reading). Mise à jour : **Transaction wallet** (sync calée sur l'`Expires`).
- D5 (revue) : la lecture s'enregistre par un upsert natif atomique (`CharacterWalletReadingRepository::record`), le service ne lit plus l'enregistrement ; un enregistrement qui échoue ne masque pas l'erreur ESI et publie `syncError` ; l'achat est lié avant la publication de `syncCompleted`.

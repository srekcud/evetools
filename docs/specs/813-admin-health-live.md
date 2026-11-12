# Spec — Admin : messages failed et budget d'erreurs ESI en direct (#813)

## Contexte

Les panneaux « Failed messages » et « ESI error budget » de la page Admin ne se rafraîchissent qu'au chargement ou sur un événement `admin-sync` envoyé à l'admin qui a lancé la sync. Un 429 ou un message en échec reste invisible jusqu'au rechargement, et « dernière observation il y a 11 min » ne bouge pas.

Décision de conception (issue : « snapshot ou ping, à décider en spec ») : **ping léger puis refetch**. Le publisher ne porte que la portée du changement ; le front relit `GET /admin/stats` ou `/admin/queues` + la liste des messages. Raison : une seule source de vérité pour les chiffres (`EsiErrorBudget::snapshot()`), pas de second assembleur de payload, et la liste des messages en échec se relit de toute façon.

## Règles

- **R1. Topic partagé.** Topic `/admin/health` (constante `MercurePublisherService::ADMIN_HEALTH_TOPIC`, liste via `getAdminTopics()`). Publication privée. Message JSON `{"adminHealth": "<portée>", "timestamp": "<ATOM>"}`. Portées (enum `App\Enum\AdminHealthScope`) : `esi-error-budget`, `failed-messages`.
- **R2. Autorisation.** `TokenProvider` ajoute `/admin/health` à `topics` et au grant `exact` du cookie seulement si `AdminChecker::isAdmin($user)`. Un non-admin ne reçoit ni le topic dans la liste ni le droit dans le JWT. Un seul topic ajouté (limite du cookie).
- **R3. Budget ESI, réponse limitée.** `EsiErrorBudget::recordLimitedResponse()` signale la portée `esi-error-budget` via `EsiErrorBudgetBroadcaster::limitedResponseRecorded()`, **au plus une publication par fenêtre** `THROTTLE_SECONDS = 10`, mesurée dans le cache partagé (clé `esi_error_budget_broadcast_at`, donc commune aux workers) avec l'horloge injectée. 131 réponses 429 dans la même fenêtre = 1 publication.
- **R4. Budget ESI, changement de niveau.** `EsiErrorBudget::observe()` appelle `EsiErrorBudgetBroadcaster::levelObserved(levelOf($remain))` : publication immédiate (hors fenêtre) quand le niveau diffère du dernier niveau publié (clé cache `esi_error_budget_broadcast_level`), rien sinon. Première observation sans niveau publié : publication. Elle remet aussi la fenêtre de R3 à zéro.
- **R5. Aucune casse.** Une panne du cache ou du hub dans le broadcaster est journalisée (`warning`) et jamais levée : `EsiClient` et le worker continuent. Le broadcaster est un paramètre optionnel (dernier) de `EsiErrorBudget` ; sans lui, comportement actuel inchangé.
- **R6. Messages failed, échec.** Un écouteur `WorkerMessageFailedEvent` (priorité -200, après `SendFailedMessageToFailureTransportListener` à -100) publie la portée `failed-messages` quand `willRetry()` est faux ; rien quand le message sera rejoué. Pas de fenêtre serveur (un échec = un ping) ; le front groupe.
- **R7. Messages failed, actions admin.** `AbstractFailedMessageProcessor` (retry, remove, retry classe, remove classe), `RetryFailedProcessor` (consume du transport failed) et `PurgeFailedProcessor` publient `failed-messages` après l'action qui a abouti ; une action qui lève (404, 422, 503 mappés) ne publie pas. Tous les admins sont notifiés, pas seulement l'acteur.
- **R8. Front, store.** `useSyncStore` traite un message portant `adminHealth` (avant le repli « sync progress ») : il enregistre le dernier signal par portée (`adminHealthSignals[portée] = timestamp`), sans toucher à `syncStatus`.
- **R9. Front, Admin.** `Admin.vue` observe `adminHealthSignals` : `esi-error-budget` → `adminStore.fetchStats()` puis `flashStats` (même flash qu'aujourd'hui) ; `failed-messages` → `adminStore.fetchQueues()` et rechargement de `FailedMessagesCard`. Les signaux sont groupés par un debounce de `ADMIN_HEALTH_DEBOUNCE_MS = 1000` (plusieurs signaux de la même portée dans la fenêtre = un refetch).
- **R10. Front, temps relatif.** `EsiErrorBudgetCard` recalcule « dernière observation » toutes les `RELATIVE_TIME_TICK_MS = 30000` ms côté client (horloge locale, intervalle arrêté au démontage) et affiche le badge `unknown` dès que l'heure locale dépasse `windowEndsAt`, sans attendre un refetch.
- **R11. Front, carte des messages.** `FailedMessagesCard` accepte la prop `refreshSignal: string | null` ; tout changement non nul relance son `load()`.

## Exemples

| # | Cas | Attendu |
|---|---|---|
| E1 | 131 réponses 429 en 5 s | 1 publication `esi-error-budget` |
| E2 | 429, puis 429 à +11 s | 2 publications |
| E3 | `observe(30)` puis `observe(15)` | publication au 2e (ok → low), pas au 1er si déjà publié `ok` |
| E4 | `observe(30)` puis `observe(25)` | aucune publication (même niveau `ok`) |
| E5 | handler en échec avec `willRetry() = true` | aucune publication |
| E6 | handler en échec, `willRetry() = false` | 1 publication `failed-messages` |
| E7 | admin A retire un message, admin B sur la page | B reçoit `failed-messages`, relit sa carte |
| E8 | Token d'un non-admin | ni `/admin/health` dans `topics`, ni dans le grant |
| E9 | Hub en panne pendant un 429 | `EsiClient` rend sa réponse, un `warning` journalisé |
| E10 | 3 signaux `failed-messages` en 300 ms | 1 seul `fetchQueues` |
| E11 | carte budget, +30 s horloge locale | « il y a 1 min » sans nouvel appel réseau |

## Cas limites

- Cookie Mercure : un seul topic exact ajouté ; `MercureCookieSizeMonitor` n'est pas modifié (admin sous-estimé d'environ 15 octets, hors périmètre).
- Un message qui échoue sur le transport `failed` lui-même n'est pas distingué : il publie un ping inoffensif.
- Fenêtre de R3 sans publication de rattrapage : un compteur modifié dans la fenêtre apparaît au signal suivant ou au rechargement ; le niveau, lui, est toujours publié (R4).
- Pas de migration, pas de commande console, pas de nouveau réglage ni de variable d'environnement.

## Hors périmètre

- Publier le snapshot complet dans le message.
- Nouveaux KPI, historique des échecs, notifications push.
- Retirer ou renommer le topic `admin-sync` ou son écouteur existant.

## Décisions ouvertes

Aucune. Constantes (`THROTTLE_SECONDS = 10`, debounce 1 s, tick 30 s) à ajuster à la relecture.

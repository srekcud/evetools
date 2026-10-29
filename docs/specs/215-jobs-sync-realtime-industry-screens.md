# Spec #215 — Sync des jobs en temps réel : écrans industrie abonnés à la fin de sync

**Statut : validée en autonomie en mode nuit le 2026-10-29 (périmètre A seulement), à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage). Périmètre A : frontend seulement, aucun changement backend. Tests de composants front (Vitest, `en.json`/`fr.json`), pas de test PHP, pas de base. Le périmètre B (un seul `syncCompleted` par utilisateur, événement pour un personnage sans scope) demande une décision structurelle : il est décrit dans « Blocage : options » et n'est pas à implémenter.

## Contexte

**Publication côté backend.**

- La sync planifiée (`SyncScheduler.php:66`, toutes les 30 min) passe par `SyncIndustryJobsHandler`. Il lit les personnages actifs à token valide (`SyncIndustryJobsHandler.php:31`), saute ceux dont le token n'a pas le scope `esi-industry.read_character_jobs.v1` (`:37`) et envoie un message `SyncCharacterIndustryJobs` par personnage (`:41`). Ces messages partent sur le transport `async` (`config/packages/messenger.yaml:24`, `max_retries: 3` en `:9`). En dev, un seul worker consomme `async` (`docker-compose.override.yml:63`) : les personnages sont traités l'un après l'autre. [TO CONFIRM: nombre de workers en production]
- Chaque message appelle `IndustryJobSyncService::syncCharacterJobs()` (`SyncCharacterIndustryJobsHandler.php:34`). « Matcher les jobs » l'appelle aussi, personnage par personnage, dans la requête HTTP (`MatchJobsProcessor.php:53-64`), comme `MatchProjectJobsCommand.php:56`.
- `syncCharacterJobs()` publie un cycle complet sur le topic `/user/{userId}/sync/industry-jobs`, par personnage. Un personnage sans token sort sans rien publier (`IndustryJobSyncService.php:49-52`). Le cycle publie `syncStarted` (`:57-59`), puis `syncProgress` 30 (`:78-80`) et 60 (`:127-129`). Il se termine par `syncCompleted` (`:224-230`, `data` = `total`, `active`, `ready`) ou, en cas d'exception, par `syncError` avec `$e->getMessage()` (`:240-244`). Après `syncCompleted`, la même sync publie les notifications `industry-job-completed` et `industry-project` au statut `notification` (`:232-238`, `:288-295`, `:395-405`). Les numéros de ligne de l'issue (`:55`, `:73`, `:107`, `:222`) datent d'avant spec #211.
- Payload (`MercurePublisherService.php:39-46`) : `syncType`, `status`, `progress`, `message`, `data`, `timestamp`. **Aucun identifiant de personnage, ni de cycle, ni de nombre attendu.** Le `message` est en anglais et ne nomme pas le personnage (`'Fetching industry jobs...'`, `'%d active jobs, %d ready to deliver'`). Une publication en échec est seulement journalisée (`:62-67`) : l'événement est perdu.
- La régénération des étapes publie sur `industry-project` : `syncStarted` (`IndustryProjectFactory.php:87`), `syncCompleted` avec `projectId` (`:105-110`), `syncError` sans `projectId` (`:111-115`). Elle est appelée par `UpdateProjectProcessor.php:115` (requête HTTP) et `RegenerateProjectStepsCommand.php:51` (console).

**Réception côté front.**

- `stores/sync.ts` range chaque message dans `syncStatus[syncType]` : un nouvel objet remplace le précédent (`sync.ts:164-169`). `industryJobsProgress` (`:62`) n'est lu par aucun composant. `isLoading('industry-jobs')` et `isAnyLoading` (`:53-57`, `:233-236`) ne sont appelés nulle part : **aucun indicateur de progression des jobs n'existe à l'écran**.
- Le seul consommateur de `industry-jobs` est `useNotificationFeed.ts:58-112`, sur le Dashboard. Il ajoute une entrée à chaque changement de statut, et ignore un message de même statut que le précédent (`:63`). Avec des cycles entrelacés (started A, started B, completed A, completed B), seul le premier `completed` crée une entrée ; avec un seul worker (cycles successifs), chaque personnage en crée une. C'est « l'indicateur » de l'issue.
- `StepsTab.vue` n'écoute que `industry-project` (`:39-46`) : sur `completed`, `fetchProject()` et `regeneratingSteps.value = false` ; le statut `error` est ignoré. `regeneratingSteps` (`:29`) n'est jamais mis à `true`, ni lu dans le template ni exposé (`defineExpose`, `:132-138`) : **code mort**. « Matcher les jobs » (`:52-64`) recharge déjà le projet et les jobs disponibles, et affiche les échecs par personnage (spec #197, `:143-149`).
- `IndustrySlotsSection.vue` charge `/industry/slots` au montage seulement (`:185-187`) ; bouton de rafraîchissement manuel (`:243-252`). `IndustryDashboard.vue` charge les mêmes slots au montage (`:31-36`) et en tire les jobs prêts (`readyJobsCount`, `:153-156`).
- `StepsTab` est monté avec `v-show` dans `ProjectDetail.vue:537-541` : il reste monté quand un autre onglet du projet est affiché. Dashboard, Slots et détail de projet sont exclusifs dans `Industry.vue:374-406` (`v-if` / `v-else-if`).

**Conséquence pour le découpage.** Le payload ne permet pas au front de savoir à quel personnage ni à quel cycle un événement appartient, ni combien de cycles sont attendus. Un comptage started/terminés côté front ne marche pas non plus. Avec un seul worker, les cycles sont successifs : le compteur retombe à zéro entre deux personnages. Un événement perdu (`MercurePublisherService.php:62-67`, reconnexion SSE, page ouverte en cours de cycle) le fausse définitivement. **Le front ne peut donc pas détecter la fin du dernier personnage sans changer le backend.** Le périmètre A se limite au rechargement à chaque `completed` reçu et à l'affichage des erreurs ; l'agrégation va dans B.

## Règles métier (périmètre A)

- **R1 — Fin de sync des jobs.** Un message `industry-jobs` au statut `completed` est une **fin de sync des jobs**. Chaque fin reçue déclenche le rechargement de R2, sans agrégation : trois personnages, trois rechargements. `started` et `in_progress` ne déclenchent rien. `industry-job-completed` et `industry-project` au statut `notification` ne déclenchent rien : ils suivent le `completed` de la même sync (`IndustryJobSyncService.php:224-238`).
- **R2 — Écrans rechargés.**

  | Écran | Rechargement sur fin de sync des jobs |
  |---|---|
  | `IndustrySlotsSection` | `slotsStore.fetchSlots()` |
  | `IndustryDashboard` | `slotsStore.fetchSlots()` |
  | `StepsTab` | `store.fetchProject(projectId)` ; plus `stepsStore.fetchAvailableJobs(projectId)` si le panneau des jobs ESI est ouvert (`showAvailableJobs`) |

- **R3 — Événements reçus pendant que l'écran est monté.** Seuls les messages arrivés après le montage comptent. L'état déjà présent dans `syncStatus` au montage est ignoré (le montage charge déjà les données). Plus rien après le démontage.
- **R4 — Erreur de sync des jobs visible.** Un message `industry-jobs` au statut `error` ne recharge rien. Il ajoute son `message` à la liste des erreurs de l'écran, affichée par `StepsTab` et `IndustrySlotsSection` dans un bandeau ambre refermable (`data-testid="jobs-sync-error"`).
  - Titre `industry.jobsSyncError.title`, au pluriel selon le nombre d'erreurs reçues.
  - Une ligne par erreur, dans l'ordre d'arrivée, avec le `message` tel quel. Deux erreurs de même texte donnent deux lignes.
  - Le bandeau reste affiché jusqu'à sa fermeture ou au démontage. Ni un `completed` ni un `started` reçu ensuite ne l'efface : rien ne dit qu'il vient du même personnage.
  - La fermeture vide la liste ; une erreur reçue ensuite rouvre le bandeau avec cette seule erreur.
  - `IndustryDashboard` n'affiche pas de bandeau (D4).
- **R5 — Erreur de régénération visible.** Dans `StepsTab`, un message `industry-project` au statut `error` affiche son `message` dans un bandeau ambre refermable (`data-testid="steps-regeneration-error"`, titre `industry.stepsTab.regenerationErrorTitle`), même règle de liste et de fermeture que R4. `completed` garde son effet : `fetchProject(projectId)`.
- **R6 — Pendant « Matcher les jobs ».** Tant que `matchJobsLoading` vaut `true`, `StepsTab` ignore les messages `industry-jobs` (`completed` et `error`). L'appel recharge lui-même le projet et les jobs disponibles (`StepsTab.vue:57-60`) et nomme les personnages en échec (spec #197).
- **R7 — `regeneratingSteps` supprimé.** La ref et son affectation disparaissent de `StepsTab.vue` (`:29`, `:44`) : jamais `true`, jamais lue. Le comportement visible ne change pas.
- **R8 — Pas d'indicateur de progression ajouté, fil de notifications inchangé.** Le périmètre A n'ajoute aucun indicateur « sync en cours » et ne modifie pas `useNotificationFeed`. Un indicateur qui s'arrête après le dernier personnage dépend de B.

## Exemples chiffrés

Données fictives. Utilisateur U1, personnages P1 « Pilot One », P2 « Pilot Two », P3 « Pilot Three ». Projet `project-215` (une étape). Un « message » est un nouvel objet posé dans `syncStatus['industry-jobs']` (ou `['industry-project']`), suivi de `flushPromises()`. Les textes d'erreur sont fictifs.

| # | Écran et situation | Messages reçus après montage | Attendu |
|---|---|---|---|
| E1 | `IndustrySlotsSection` | `completed` (P1), `completed` (P2), `completed` (P3) | `GET /industry/slots` appelé 4 fois (montage + 3) |
| E2 | `IndustrySlotsSection` | `started`, `in_progress` 30, `in_progress` 60 | 1 seul appel (montage) ; pas de bandeau |
| E3 | `IndustrySlotsSection` | `error` « ESI unavailable (fictif) » | 1 appel ; bandeau `jobs-sync-error`, titre « Industry jobs sync failed (1 error), the jobs shown may be out of date: », ligne « ESI unavailable (fictif) » |
| E4 | `IndustrySlotsSection` | `error` « boom A », puis `completed` | 2 appels ; bandeau toujours là, une ligne « boom A » |
| E5 | `IndustrySlotsSection` | `error` « boom A », `error` « boom A » | titre « … (2 errors) … » ; deux lignes « boom A » |
| E6 | `IndustrySlotsSection` | `error` « boom A », fermeture, `error` « boom B » | après fermeture : pas de bandeau ; puis bandeau avec la seule ligne « boom B » ; le bouton fermer a le nom accessible `common.actions.close` |
| E7 | `IndustrySlotsSection`, FR | `error` « boom A » | titre « Échec de la synchronisation des jobs (1 erreur), les jobs affichés peuvent être périmés : » |
| E8 | `IndustrySlotsSection` démonté | `completed` | aucun appel après le démontage |
| E9 | `IndustrySlotsSection`, `syncStatus['industry-jobs']` déjà `completed` avant montage | aucun | 1 seul appel (montage) |
| E10 | `StepsTab`, panneau jobs ESI fermé | `completed` | `fetchProject('project-215')` 1 fois ; `fetchAvailableJobs` jamais |
| E11 | `StepsTab`, panneau jobs ESI ouvert | `completed` | `fetchProject` 1 fois ; `fetchAvailableJobs('project-215')` 1 fois de plus qu'à l'ouverture |
| E12 | `StepsTab`, `matchJobs` en attente (promesse non résolue) | `completed`, `error` « boom A » | `fetchProject` jamais appelé par ces messages ; pas de bandeau `jobs-sync-error` |
| E13 | `StepsTab` | `error` « boom A » sur `industry-jobs` | bandeau `jobs-sync-error`, une ligne ; étapes toujours affichées |
| E14 | `StepsTab` | `industry-project` `error` « Blueprint missing (fictif) » | bandeau `steps-regeneration-error`, titre « Steps regeneration failed: », ligne « Blueprint missing (fictif) » ; pas de `fetchProject` |
| E15 | `StepsTab` | `industry-project` `completed` (`projectId` = `project-215`) | `fetchProject('project-215')` 1 fois (inchangé) |
| E16 | `StepsTab` | `industry-job-completed` `notification`, `industry-project` `notification` | aucun rechargement |
| E17 | `IndustryDashboard` | `completed`, puis `error` « boom A » | `GET /industry/slots` 2 fois (montage + 1) ; pas de bandeau |

## Clés i18n

| Clé | FR | EN |
|---|---|---|
| `industry.jobsSyncError.title` | `Échec de la synchronisation des jobs ({count} erreur), les jobs affichés peuvent être périmés : \| Échec de la synchronisation des jobs ({count} erreurs), les jobs affichés peuvent être périmés :` | `Industry jobs sync failed ({count} error), the jobs shown may be out of date: \| Industry jobs sync failed ({count} errors), the jobs shown may be out of date:` |
| `industry.stepsTab.regenerationErrorTitle` | `Échec de la régénération des étapes :` | `Steps regeneration failed:` |

Le bouton fermer réutilise `common.actions.close` (`en.json:10`).

## Cas limites

- **Rechargements en rafale.** Un utilisateur à N personnages reçoit N fins par cycle planifié, soit N rechargements de l'écran ouvert toutes les 30 min. Coût jugé négligeable (lecture en base, pas d'ESI). Disparaît avec B.
- **Réponses dans le désordre.** Deux `fetchSlots()` proches peuvent répondre dans le désordre ; la plus ancienne peut alors écraser la plus récente jusqu'au prochain rechargement. Rare (lectures en base courtes), non traité (D5).
- **Événement perdu.** Publication Mercure en échec (`MercurePublisherService.php:62-67`), coupure SSE ou reconnexion (`sync.ts:176-223`) : pas de rechargement ; le bouton « Refresh » des slots et la réouverture du projet restent possibles.
- **Erreur sans personnage.** Le `message` de `syncError` est le texte brut de l'exception, en anglais, sans nom de personnage (`IndustryJobSyncService.php:242`). Le bandeau ne peut pas dire quel personnage a échoué (B). Une erreur suivie d'un succès d'un autre personnage reste affichée (R4, E4).
- **Messenger relance.** Un personnage en échec est relancé jusqu'à 3 fois (`messenger.yaml:9`) : chaque tentative en échec publie un `error`, donc jusqu'à 4 lignes pour un même personnage, puis éventuellement un `completed`.
- **Erreur de régénération venue d'un autre projet.** Le `syncError` de `industry-project` ne porte pas de `projectId` : un `StepsTab` ouvert l'affiche quel que soit le projet régénéré (console `app:industry:regenerate-steps` sur un autre projet, autre onglet). Accepté : le texte dit « régénération des étapes », sans nommer de projet.
- **Régénération en échec par l'API.** `UpdateProjectProcessor` relance l'exception (HTTP 500) : le store pose déjà son erreur, affichée par `ErrorBanner` (`Industry.vue:371`). Le bandeau R5 peut s'y ajouter. Doublon accepté, chaque bandeau se ferme seul.
- **Messages de « Matcher les jobs » arrivés après la réponse HTTP.** Les publications Mercure précèdent la réponse côté serveur, mais l'ordre d'arrivée au navigateur n'est pas garanti. Un `completed` tardif provoque un rechargement de plus (sans effet visible) ; un `error` tardif ouvre le bandeau R4 en plus du bandeau #197. Accepté.
- **`StepsTab` caché (`v-show`).** Il recharge le projet même si l'onglet affiché est Shopping ou un autre : `currentProject` est partagé, ces onglets en profitent.
- **Utilisateur sans personnage ou sans token.** Rien n'est publié, rien n'est rechargé.

## Hors périmètre

- **Périmètre B** (voir « Blocage : options ») : un seul `syncCompleted` après le dernier personnage, un événement pour un personnage sauté faute de scope ou de token, indicateur de progression agrégé, erreurs nommant le personnage.
- Liste des projets (`store.projects`, chargée par `Industry.vue`) : `ProjectTable` (coût des jobs) et l'avancement des projets du Dashboard (`IndustryDashboard.vue:272-281`, `esiJobStatus`) ne sont pas rechargés. Recharger toute la liste à chaque fin de sync est plus coûteux ; à ouvrir en issue si le besoin est confirmé.
- `GroupIndustryDetail` / `JobsTable` : suivent les événements Group Industry (`publishGroupProjectEvent`), pas la sync des jobs.
- `useNotificationFeed` : inchangé (R8).
- Ordre des réponses concurrentes de `fetchSlots()` / `fetchProject()` (D5).

## Blocage : options (périmètre B, à trancher par Sylvain)

**Besoin.** (1) Publier une seule fin de sync des jobs par utilisateur, après son dernier personnage (critère de l'issue : « un seul `syncCompleted` est publié, après le dernier personnage »). (2) Publier quelque chose pour un personnage sauté : sans scope `esi-industry.read_character_jobs.v1` (`SyncIndustryJobsHandler.php:37`) ou sans token (`IndustryJobSyncService.php:49-52`). Les deux touchent au fan-out Messenger, à l'état partagé entre workers ou au contrat Mercure : décision structurelle.

**Option 1 — Payload enrichi, agrégation par le front.**
- Le fan-out calcule, par utilisateur, un `cycleId` et le nombre de personnages attendus. Il les passe dans `SyncCharacterIndustryJobs` (nouveaux champs).
- Chaque événement du cycle porte `cycleId`, `characterName`, `expected`. Un personnage sauté publie un statut nouveau (`skipped`, avec sa raison, codes de spec #197).
- Le front agrège par `cycleId` : fini quand `completed + error + skipped = expected`.
- Coût : message Messenger modifié (les messages en file au déploiement n'ont pas les champs : valeurs par défaut à prévoir), `syncCharacterJobs()` reçoit le contexte de cycle, nouveau statut dans `SyncStatus`, agrégation et minuterie côté front. « Matcher les jobs » doit aussi produire un `cycleId`.
- Risques : un événement perdu (`MercurePublisherService.php:62-67`) ou un worker tué bloque l'agrégat, d'où un délai d'abandon arbitraire côté front. Les relances Messenger publient plusieurs `error` pour un même personnage : il faut dédupliquer par personnage. La logique de fin vit dans chaque client.

**Option 2 — Compteur par utilisateur dans Redis.**
- Le fan-out écrit l'ensemble des personnages attendus par utilisateur (`industry_jobs_pending_{userId}`). Chaque sync retire son personnage à la fin (succès ou échec définitif). Celle qui vide l'ensemble publie le `syncCompleted` agrégé ; les événements par personnage passent en `in_progress`. Un personnage sauté est inscrit en échec dans l'agrégat sans être attendu.
- Coût : opérations atomiques (`SREM` / `SCARD`). Les pools `cache.adapter.redis` de `config/packages/cache.yaml` ne les offrent pas : il faut une connexion Redis brute ou `symfony/lock`. C'est une nouvelle dépendance d'infrastructure dans le service de sync.
- Risques : TTL obligatoire (worker tué, message perdu) ; à son expiration, rien n'est publié et l'indicateur reste bloqué. Il faut distinguer échec définitif et échec relancé (`max_retries: 3`), sinon double retrait. L'état devient partagé entre workers. « Matcher les jobs » (synchrone) doit rester hors compteur.

**Option 3 — Un message par utilisateur.**
- `SyncIndustryJobsHandler` envoie un message `SyncUserIndustryJobs(userId)` par utilisateur. Son handler boucle sur les personnages, comme `MatchJobsProcessor.php:53-64` : un `syncStarted`, une progression par personnage, puis un seul `syncCompleted`. La fin porte `failedCharacters` (`CharacterSyncFailure` de spec #197, y compris `missing_token` et `missing_scope`).
- La publication Mercure sort de `syncCharacterJobs()` vers ses appelants (handler, `MatchJobsProcessor`, `MatchProjectJobsCommand`).
- Coût : nouveau message et handler, retrait du message par personnage (ou maintien pour d'autres usages), déplacement de la publication. Tests à revoir : `IndustryJobSyncServiceTest` (attentes Mercure), `SyncIndustryJobsHandlerTest`, `SyncCharacterIndustryJobsHandlerTest`, `tests/Integration/MessageHandler/ScheduledCharacterSyncFanOutTest.php`.
- Risques : la granularité des relances change. Un échec d'un personnage est capturé dans la boucle et n'est plus relancé par Messenger (prochaine tentative au cycle suivant, 30 min). Relancer tout le message resynchroniserait aussi les personnages réussis. Le parallélisme entre personnages d'un même utilisateur est perdu (sans effet avec un seul worker). Le message dure plus longtemps pour un utilisateur à beaucoup de personnages (une sync = relecture d'affiliation #211, jobs perso, jobs corpo en cache #213).

**Recommandation : option 3.** Le cycle publié devient le cycle vu par l'utilisateur, sans état partagé, sans TTL, sans délai d'abandon côté front. La forme reprend « Matcher les jobs » (#197) : les personnages sans scope ou sans token sont nommés dans la même structure, et le bandeau R4 peut alors nommer chaque personnage. Le coût principal est la perte des relances Messenger par personnage, compensée par le cycle de 30 min. À confirmer : le nombre de workers en production et la durée maximale d'un message pour l'utilisateur le plus chargé. L'option 1 reste la plus légère côté backend si Sylvain veut garder le fan-out par personnage, au prix d'une logique de fin dans le front.

Une fois B tranché, R1 (un rechargement par fin) devient un rechargement par cycle sans changer les écrans : seul le nombre de `completed` reçus baisse.

## Décisions retenues en autonomie

- **D1 — Déclencheur : `industry-jobs` `completed` seulement.** `industry-job-completed` et `industry-project` `notification` viennent après le `completed` de la même sync et seulement s'il y a des jobs finis : les écouter rechargerait deux fois.
- **D2 — Un composable partagé `useIndustryJobsSync`.** Il prend le rappel de rechargement et, en option, un prédicat de pause (R6). Il rend la liste des messages d'erreur et une fonction de fermeture. Les trois écrans l'utilisent ; c'est une seule règle (R1, R3, R4), écrite une fois.
- **D3 — Un composant `SyncErrorAlert.vue`** (props `title`, `messages: string[]`, émet `dismiss`), bandeau ambre repris de `CharacterSyncFailuresAlert.vue`, avec un bouton fermer nommé `common.actions.close`. Les deux bandeaux restent séparés : les lignes et le lien de reconnexion de #197 n'ont pas de sens ici. Pas de maquette : le bandeau ambre existe déjà (même choix que #197 D7).
- **D4 — Pas de bandeau d'erreur sur le Dashboard.** Il résume plusieurs domaines ; le fil de notifications du Dashboard (`useNotificationFeed`) affiche déjà les erreurs de sync. Le Dashboard recharge seulement ses slots.
- **D5 — Pas de protection contre les réponses dans le désordre.** Ce serait un numéro de requête dans `slots.ts` et `projects.ts`, pour un cas rare et corrigé au rechargement suivant. À rouvrir si observé.
- **D6 — Message d'erreur brut affiché.** C'est la seule information du payload. Le fil de notifications l'affiche déjà ainsi (`useNotificationFeed.ts:73-79`). Le titre traduit dit l'essentiel ; nommer le personnage dépend de B.
- **D7 — Bandeau d'erreur jamais effacé automatiquement (R4).** Sans personnage dans le payload, un succès ultérieur ne prouve pas que l'échec est résolu ; l'effacer tromperait l'utilisateur.
- **D8 — `regeneratingSteps` supprimé (R7)** plutôt que mis à `true` au `started` : aucun élément d'écran ne le lit, et la régénération passe par une requête HTTP dont `UpdateProjectProcessor` attend la fin.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `frontend/src/composables/useIndustryJobsSync.ts` (nouveau) | `watch` sur `syncStore.getSyncProgress('industry-jobs')` (sans `immediate`, R3) : `completed` → rappel ; `error` → ajout de `message` à `errors` ; ignoré si le prédicat de pause rend `true` (R6). Rend `{ errors, dismissErrors }` |
| `frontend/src/components/industry/SyncErrorAlert.vue` (nouveau) | bandeau (D3) |
| `frontend/src/components/industry/IndustrySlotsSection.vue` | `useIndustryJobsSync(() => slotsStore.fetchSlots())` ; bandeau `jobs-sync-error` en tête du contenu (R4) |
| `frontend/src/components/industry/IndustryDashboard.vue` | `useIndustryJobsSync(() => slotsStore.fetchSlots())`, sans bandeau (D4) |
| `frontend/src/components/industry/StepsTab.vue` | `useIndustryJobsSync` avec rechargement R2 et pause `matchJobsLoading` (R6) ; bandeau `jobs-sync-error` ; watch `industry-project` : `error` → liste `regenerationErrors` + bandeau `steps-regeneration-error` (R5) ; retrait de `regeneratingSteps` (R7) |
| `frontend/src/i18n/locales/fr.json`, `en.json` | `industry.jobsSyncError.title`, `industry.stepsTab.regenerationErrorTitle` |

Aucun fichier backend, aucune migration, aucun message Messenger, aucune commande à lancer en production.

## Câblage des tests (Vitest)

**Tests existants appelés à casser** : aucun attendu.

- `regeneratingSteps` n'est lu par aucun test (`grep -rn regeneratingSteps frontend/tests` vide) ni exposé.
- Les tests qui montent ces écrans (`stepsTab*.spec.ts`, `industrySlotsSectionUnknownSkillsAndStaleJobs.spec.ts`, `industryDashboard*.spec.ts`) créent un Pinia neuf. `useSyncStore` s'y instancie sans se connecter (`authStore.isAuthenticated` faux, `sync.ts:250-261`). `StepsTab` l'utilise déjà.
- `tests/views/Industry.spec.ts` et `tests/a11y/iconButtonsHaveName.spec.ts` remplacent ces composants par des stubs.

**Tests rouges attendus** (nom de `describe` avec `issue 215`) :

- Réception d'un message : affecter un nouvel objet `useSyncStore().syncStatus = { ...syncStore.syncStatus, 'industry-jobs': { syncType: 'industry-jobs', status, progress, message, data: null, timestamp } }`, puis `await flushPromises()`. Un nouvel objet à chaque message, un `flushPromises()` entre deux messages (un `watch` ne voit que la dernière valeur d'un même tick). Le passage par un `FakeEventSource` (`tests/composables/useTodoItems.spec.ts:49-54`) n'est pas nécessaire : la règle porte sur `syncStatus`, pas sur le parsing.
- `frontend/tests/components/industry/industrySlotsSectionJobsSync.spec.ts` : E1 à E9. Harnais de `industrySlotsSectionUnknownSkillsAndStaleJobs.spec.ts` (`apiRequest` mocké, compte des appels `'/industry/slots'`) ; `en.json`, `fr.json` pour E7 ; E8 par `wrapper.unmount()` ; E9 en posant `syncStatus` avant `mount`.
- `frontend/tests/components/industry/stepsTabJobsSync.spec.ts` : E10 à E16. Montage de `stepsTabSyncFailures.spec.ts` (`useProjectsStore` avec `currentProject`, `fetchProject` et `matchJobs` espionnés, `fetchAvailableJobs` espionné, router mémoire). E11 : clic sur `industry.stepsTab.esiJobs` avant le message. E12 : `matchJobs` rend une promesse jamais résolue, clic sur `syncJobs`, puis messages, puis `fetchProject` compté à partir de là.
- `frontend/tests/components/industry/industryDashboardJobsSync.spec.ts` : E17. Harnais de `industryDashboardProfitTotal.spec.ts`.
- Bouton fermer accessible (E6) : `aria-label` = `common.actions.close`, comme `stepsTabSyncFailures.spec.ts`.

## Glossaire (proposé, à valider)

- Ajout : **Fin de sync des jobs** (Jobs sync end). Message Mercure `industry-jobs` au statut `completed`, publié à la fin de la sync des jobs d'un personnage. Aujourd'hui un par personnage, pas un par utilisateur (spec #215, périmètre B ouvert). Les écrans industrie qui affichent des jobs (slots, Dashboard, étapes d'un projet) se rechargent à chaque fin reçue. Code : `IndustryJobSyncService::syncCharacterJobs()`, `useIndustryJobsSync`.

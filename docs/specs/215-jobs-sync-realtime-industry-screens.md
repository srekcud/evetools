# Spec #215 — Sync des jobs en temps réel : écrans industrie abonnés à la fin de sync

**Statut : validée en autonomie en mode nuit le 2026-10-30 (périmètres A et B), à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage séparée ; le câblage de B est dans sa section). Périmètre A : frontend seulement, aucun changement backend. Tests de composants front (Vitest, `en.json`/`fr.json`), pas de test PHP, pas de base. Livré (commit `61610757`). Périmètre B (option 3 décidée par Sylvain) : un message Messenger par utilisateur, un seul `syncCompleted` par utilisateur portant les personnages en échec, affichage de ces échecs dans les écrans de A. Tests unitaires PHP et Vitest, pas de test d'intégration, pas de base.

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

- **Périmètre B** : traité dans « Périmètre B (option 3, décidée) ». Reste hors périmètre : un indicateur de progression agrégé à l'écran (R8, D17).
- Liste des projets (`store.projects`, chargée par `Industry.vue`) : `ProjectTable` (coût des jobs) et l'avancement des projets du Dashboard (`IndustryDashboard.vue:272-281`, `esiJobStatus`) ne sont pas rechargés. Recharger toute la liste à chaque fin de sync est plus coûteux ; à ouvrir en issue si le besoin est confirmé.
- `GroupIndustryDetail` / `JobsTable` : suivent les événements Group Industry (`publishGroupProjectEvent`), pas la sync des jobs.
- `useNotificationFeed` : inchangé (R8).
- Ordre des réponses concurrentes de `fetchSlots()` / `fetchProject()` (D5).

## Périmètre B (option 3, décidée)

Décision de Sylvain (2026-10-30) : option 3, un message Messenger par utilisateur. Backend (message, handler, service) et extension du front livré en A. Tests unitaires PHP et Vitest ; pas de test d'intégration, pas de base, pas de migration.

### Contexte vérifié (code actuel)

**Fan-out planifié.**
- `SyncIndustryJobsHandler::__invoke()` (`SyncIndustryJobsHandler.php:26-55`) appelle `SyncTracker::start('industry')`. Il lit ensuite `CharacterRepository::findActiveWithValidTokens()` (`:31`) : personnages `authStatus = valid`, avec un token, d'un utilisateur connecté depuis 7 jours (`CharacterRepository.php:94-107`). Un personnage sans id, sans token ou sans scope `esi-industry.read_character_jobs.v1` est sauté sans rien publier (`:37`). Les autres reçoivent chacun un `SyncCharacterIndustryJobs($characterId)` (`:41`). Fin : `complete('industry', '2/3 chars queued')` (`:50`) ; en cas d'exception, `fail` puis relance (`:51-54`).
- Un modèle par utilisateur existe déjà : `TriggerMiningSyncHandler` envoie `SyncUserMiningData($userId)` (`TriggerMiningSyncHandler.php:37-45`, tracker `'x/y users queued'`). `SyncUserMiningDataHandler` relit l'utilisateur (`UserRepository::find`) et journalise un avertissement s'il a disparu (`SyncUserMiningDataHandler.php:25-32`).
- `SyncCharacterIndustryJobsHandler` (`:24-35`) relit le personnage par `Uuid::fromString` ; s'il a disparu, il journalise un avertissement. Sinon il appelle `syncCharacterJobs()` sans rien capturer : une exception remonte au bus.

**Messenger.**
- Routage : `SyncIndustryJobs` et `SyncCharacterIndustryJobs` vont vers `async` (`messenger.yaml:23-24`). `max_retries: 3`, délai 1 s ×2 (`:8-12`), puis transport `failed`.
- Transport AMQP (`docker-compose.yaml:31`, `:72`), sérialiseur PHP par défaut (aucun `serializer` configuré).
- Un seul conteneur `worker` consomme `async` et `scheduler_default` (`docker-compose.yaml:63`). `docker-compose.prod.yml:52-70` ne fixe pas de `replicas` et le `Makefile` n'a pas de `--scale` : un seul worker, d'après les fichiers compose.
- Symfony 7.4 : un message dont la classe n'existe plus ne se décode pas. `AmqpReceiver::getEnvelope()` le rejette (`nack`) puis relance `MessageDecodingFailedException`. `Worker::run()` ne capture pas cette exception : le worker s'arrête (sources Symfony 7.4 `AmqpReceiver.php` et `Worker.php`).

**Service.** `IndustryJobSyncService::syncCharacterJobs(Character): void` :
- sort sans rien publier si le token est nul (`:49-52`) ;
- publie `syncStarted` (`:57-59`), relit l'affiliation (#211, `:62`), puis lit les jobs perso si le scope est là (`:67-75`). Il publie `syncProgress` 30 (`:78-80`). Il lit les jobs corpo (cache #213, 403 toléré, `:82-96`) et ne garde que les jobs installés par un personnage de l'utilisateur (`:104-125`). Il publie `syncProgress` 60 (`:127-129`). Il écrit, marque les jobs disparus (`:179-200`), recopie l'état sur les correspondances (`:202-204`) et fait le `flush` (`:212`) ;
- publie `syncCompleted`, avec `total`, `active` et `ready` comptés sur les jobs de l'utilisateur lus par ce personnage (`:219-230`, #210). Viennent ensuite les notifications `industry-job-completed` et `industry-project` (`:232-238`, réservation #216) ;
- en cas d'exception : `syncError($e->getMessage())`, puis relance (`:240-245`).
- Chaque personnage d'une même corporation relit les jobs corpo, donc aussi ceux de ses alts, et les compte. Additionner les `completed` de plusieurs personnages compterait deux fois un même job.

**Appelants manuels.**
- `MatchJobsProcessor` (`:54-68`) et `MatchProjectJobsCommand` (`:54-62`) bouclent sur `$user->getCharacters()`, dans la requête HTTP ou dans la console.
- Le processeur construit les échecs de #197 :
  - `CharacterSyncFailure::missingToken()`, sans appeler la sync ;
  - `fromThrowable()` sur exception, avec un avertissement `'Failed to sync jobs for character'` et `logContext()` ;
  - il ne teste pas le scope.
- `SyncCharacterSkillsProcessor` (`:57-73`) ajoute `missingScope()`.
- Format `toArray()` : `characterName`, `reason` (`CharacterSyncFailureReason`), `statusCode` (`CharacterSyncFailure.php:52-59`).
- Les tests de #197 remplacent `syncCharacterJobs` par un rappel `: void` (`MatchJobsProcessorTest.php:354-360`).

**Mercure.** Payload : `syncType`, `status`, `progress`, `message`, `data`, `timestamp` (`MercurePublisherService.php:39-46`). `syncCompleted` fixe `progress` à 100 (`:91-94`). Une publication en échec est seulement journalisée (`:62-67`). Ni ce service ni `getTopicsForUser()` ne changent.

**Personnage révoqué.** `TokenManager::getValidAccessToken()` refuse un personnage dont l'`authStatus` n'est pas `valid` : `EveAuthRequiredException`, sans appel réseau (`TokenManager.php:200-221`). `CharacterSyncFailure::fromThrowable()` classe ce cas en `authentication_required`.

**Front (A livré, commit `61610757`).**
- `useIndustryJobsSync` (`useIndustryJobsSync.ts:10-17`) : sur `completed`, recharge ; sur `error`, ajoute le `message` ; rien si le prédicat de pause est vrai.
- `StepsTab` affiche déjà `CharacterSyncFailuresAlert` pour « Matcher les jobs » (`StepsTab.vue:155-161`) : `data-testid="jobs-sync-failures"`, titre `industry.syncFailures.jobsTitle` (« …matching used the last known jobs »).
- `IndustrySlotsSection` affiche le bandeau R4 en tête du contenu (`:235-241`).
- `SyncProgress.data` est de type `Record<string, unknown> | null` (`sync.ts:8-15`). Le type `CharacterSyncFailure` existe (`stores/industry/types.ts:318-324`).

**SyncTracker.** Seul le texte de `complete('industry', …)` change.

### Règles métier (périmètre B)

- **R9 — Fan-out par utilisateur.**
  - `SyncIndustryJobsHandler` lit toujours `findActiveWithValidTokens()`. Il envoie un `SyncUserIndustryJobs($userId)` par utilisateur distinct, dans l'ordre où l'utilisateur apparaît pour la première fois.
  - Un personnage sans utilisateur, ou dont l'utilisateur n'a pas d'id, n'envoie rien.
  - Le scope n'est plus testé à cette étape (il l'est en R11). Plus aucun `SyncCharacterIndustryJobs` n'est envoyé.
  - Tracker : `start('industry')`, puis `complete('industry', '<u> users queued for <c> chars')`, avec `u` = utilisateurs distincts et `c` = personnages lus. En cas d'exception : `fail('industry', message)` puis relance (inchangé).
- **R10 — Handler.** `SyncUserIndustryJobsHandler` relit l'utilisateur par `UserRepository::find(Uuid::fromString($message->userId))`.
  - Utilisateur absent : un avertissement ; rien n'est publié ni relancé.
  - Utilisateur présent : `IndustryJobSyncService::syncUserJobs($user)`, sans capture ; une exception remonte au bus (R14).
- **R11 — Sync des jobs d'un utilisateur.** `syncUserJobs(User): void` publie sur `/user/{userId}/sync/industry-jobs` :
  1. un `syncStarted` (`'Fetching industry jobs...'`) ;
  2. pour chaque personnage de `$user->getCharacters()`, dans l'ordre de la collection :
     - token nul : `missingToken` ;
     - token sans `esi-industry.read_character_jobs.v1` : `missingScope` ;
     - sinon : sync du personnage, même traitement que `syncCharacterJobs()` mais sans événement de cycle ;
     - une exception est capturée : `fromThrowable()`, plus un avertissement `'Failed to sync jobs for character'` avec `logContext()`, et la boucle continue ;
     - `missing_token` et `missing_scope` ne déclenchent ni appel ESI ni avertissement (comme #197) ;
  3. après chaque personnage (réussi, sauté ou en échec), un `syncProgress` : `progress = intdiv(100 × i, n)`, message `'<i>/<n> characters processed'` ;
  4. après le dernier personnage, un seul `syncCompleted` (R12).

  Les notifications `industry-job-completed` et `industry-project` d'un personnage partent pendant sa sync, donc avant le `completed` de l'utilisateur. Un `User` sans id lève une `\LogicException` avant toute publication : cas impossible pour un utilisateur relu en base, mais exigé par le typage.
- **R12 — Contenu du `completed`.** `data` contient `total`, `active`, `ready` et `failedCharacters`.
  - `total`, `active`, `ready` : jobs des personnages de l'utilisateur, lus par les personnages réussis. Ils sont dédoublonnés par `job_id` sur toute la sync : un job corpo lu par deux alts compte une fois. Le comptage suit les règles de #210 (statuts `active` et `ready`). Un personnage en échec n'entre pas dans le comptage, même si ses jobs ont été écrits avant l'échec (cas d'une notification en échec, E28).
  - `failedCharacters` : liste des `CharacterSyncFailure::toArray()`, dans l'ordre des personnages ; `[]` si aucun échec.
  - `message` : `'<active> active jobs, <ready> ready to deliver'`, suivi de `', <k> characters not synced'` quand `k = count(failedCharacters) > 0`.
  - Si tous les personnages échouent, le `completed` part quand même (comptes à 0), sans `error`.
- **R13 — Sync par personnage des appelants manuels.** `syncCharacterJobs(Character): void` garde sa signature et publie toujours une sync par personnage. Appelants : `MatchJobsProcessor`, `MatchProjectJobsCommand` et l'ancien handler (R15).
  - Événements : `syncStarted`, puis soit `syncCompleted` (même `message` et même `data` `total`/`active`/`ready` qu'aujourd'hui, **sans** clé `failedCharacters`), soit `syncError`.
  - Les `syncProgress` 30 et 60 disparaissent : le traitement commun ne publie plus de progression.
  - Les notifications partent avant le `completed`. Un échec d'envoi publie donc `error` sans `completed` (avant : `completed` puis `error`).
- **R14 — Échec du message entier.** Une exception survenue hors de la sync d'un personnage (lecture de la collection de personnages, par exemple) publie un `syncError` avec son message. Le `syncStarted` est déjà parti. L'exception remonte : Messenger relance le message entier 3 fois, puis l'envoie dans `failed`. C'est le seul cas où la sync d'un utilisateur publie un `error`.
- **R15 — Anciens messages.** `SyncCharacterIndustryJobs`, son handler et sa ligne de routage restent le temps d'une version (D11). Un message encore en file au déploiement est traité comme aujourd'hui : sync par personnage de R13, relances Messenger par personnage. Leur retrait se fera dans une issue de suivi, après le déploiement de cette version.
- **R16 — Front : échecs de la dernière sync.** Sur un `completed` reçu hors pause, `useIndustryJobsSync` lit `data.failedCharacters` :
  - tableau présent : il remplace la liste des échecs, car la sync d'un utilisateur couvre tous ses personnages ; un tableau vide retire le bandeau ;
  - clé absente (`completed` par personnage de « Matcher les jobs », R13) ou `data` nul : liste inchangée ;
  - fermeture du bandeau : la liste est vidée jusqu'au prochain `completed` qui porte des échecs.

  `IndustrySlotsSection` et `StepsTab` affichent `CharacterSyncFailuresAlert` à côté du bandeau R4 : `data-testid="last-jobs-sync-failures"`, titre `industry.syncFailures.lastJobsSyncTitle` au pluriel. Les lignes, les raisons et le lien « Reconnect characters » viennent de #197. `IndustryDashboard` n'affiche rien (D4).
- **R17 — Pause.** Pendant `matchJobsLoading` (R6), `StepsTab` ignore aussi les échecs portés par un `completed`.

### Effets sur le périmètre A

- **R1** : la sync planifiée publie un `completed` par utilisateur et par sync, d'où un rechargement par sync au lieu de N. « Matcher les jobs » et `app:industry:match-jobs` gardent un `completed` par personnage.
- **R4** : dans la sync planifiée, l'échec d'un personnage ne publie plus d'`error` ; il passe dans `failedCharacters` (R16). Le bandeau R4 n'affiche plus que :
  - l'échec d'un message entier (R14) ;
  - les échecs par personnage de « Matcher les jobs », de la commande et des anciens messages (R15).
- **Cas limites de A** : « Rechargements en rafale » et « Messenger relance » ne concernent plus la sync planifiée. « Erreur sans personnage » : R16 nomme maintenant les personnages.
- **D7** reste valable pour le bandeau R4. Le bandeau R16, lui, est remplacé à chaque sync, puisque la sync d'un utilisateur couvre tous ses personnages.
- Tests de A : inchangés. Leurs messages ont `data: null`, et R16 laisse alors la liste vide.

### Décisions (périmètre B)

- **D9 — La sync de l'utilisateur vit dans le service (`syncUserJobs`), et non chez les appelants ni dans un publisher de cycle injecté.**
  - Le service porte déjà `MercurePublisherService`, le topic et le comptage de #210. Le handler reste mince, comme `SyncUserMiningDataHandler` qui délègue à `MiningSyncService::syncAll()`.
  - Sortir la publication vers les appelants la recopierait dans trois classes (handler, processeur, commande) et changerait les tests de #197.
  - Un objet « cycle » injecté demanderait une classe de plus et sa création dans les trois appelants.
  - Ici, seuls le service, le fan-out et un nouveau handler changent.
- **D10 — `syncCharacterJobs()` garde sa sync par personnage (R13).**
  - Le processeur, la commande, l'ancien handler et leurs tests ne changent pas : les rappels `: void` des doublures restent valides.
  - « Matcher les jobs » publie toujours un `completed` par personnage, que `StepsTab` ignore (R6).
  - Passer ces appelants à la sync par utilisateur unifierait les boucles, mais changerait #197 (contrôle du scope, tests) : hors périmètre.
- **D11 — Ancien message gardé une version.**
  - Retirer sa classe arrêterait le worker à chaque ancien message encore en file (décodage impossible, voir Contexte), et ce message serait perdu.
  - Le garder ne coûte rien : son handler n'est pas modifié.
  - Après cette version, plus rien ne l'envoie : la version suivante peut le retirer sans risque.
- **D12 — Quels personnages.**
  - La boucle porte sur tous les personnages de l'utilisateur, avec les règles de `SyncCharacterSkillsProcessor`.
  - Le fan-out part toujours de `findActiveWithValidTokens()`, pour garder le filtre « utilisateur connecté depuis 7 jours ».
  - Effet voulu : un personnage sans token, sans scope ou révoqué est nommé dans les échecs, avec le lien de reconnexion.
- **D13 — Comptes dédoublonnés par `job_id`** sur toute la sync (voir la note du Contexte sur les jobs corpo).
- **D14 — Suffixe « N characters not synced » dans le message.** Le Dashboard n'a pas de bandeau (D4), et son fil affiche ce message comme un succès. Sans ce suffixe, une sync où tous les personnages échouent s'afficherait « 0 active jobs, 0 ready to deliver » en vert.
- **D15 — Échecs affichés à l'écran en réutilisant `CharacterSyncFailuresAlert`.**
  - Le coût est faible : une ref dans le composable, un bloc par écran, une clé i18n.
  - Sans cet affichage, B ferait perdre une information que A affiche : les échecs par personnage de la sync planifiée ne publient plus d'`error` (Effets sur A, R4).
  - Pas de maquette : le composant existe déjà (même choix que #197 D7 et A D3).
- **D16 — Pas d'`error` pour une sync où tous les personnages échouent.** Chaque échec est nommé dans le `completed`. `error` reste réservé au message qui n'a pas pu aller au bout et que Messenger relance (R14).
- **D17 — Progression publiée, mais toujours pas d'indicateur à l'écran (R8).** `intdiv(100 × i, n)` donne une progression monotone qui finit à 100. Le message est en anglais, comme les autres messages de sync.

### Exemples chiffrés (périmètre B)

Données fictives. Les personnages des tests du service s'appellent `Char{eveCharacterId}` (helper `createCharactersOfSameUserWithScopes`) ; ils appartiennent au même utilisateur et à la corporation 98000001. « perso » = `esi-industry.read_character_jobs.v1`, « corpo » = `esi-industry.read_corporation_jobs.v1`. Les événements listés sont ceux du `syncType` `industry-jobs`, au format (statut, progress, message).

| # | Situation | Attendu |
|---|---|---|
| E18 | `syncUserJobs` : Char21504 (corpo seul) ; Char21505 (perso + corpo), lecture perso en 403 ; Char21506 (perso + corpo), jobs perso 600 `active`, 601 `active`, 602 `ready`, 603 `delivered` ; jobs corpo `[]` | (started, 0, `Fetching industry jobs...`), (in_progress, 33, `1/3 characters processed`), (in_progress, 66, `2/3 …`), (in_progress, 100, `3/3 …`), (completed, 100, `2 active jobs, 1 ready to deliver, 2 characters not synced`). `data` = `{total: 4, active: 2, ready: 1, failedCharacters: [{Char21504, missing_scope, null}, {Char21505, esi_error, 403}]}`. Affiliations lues : `[21505, 21506]`. Un seul avertissement (Char21505, `statusCode` 403). Aucun `error`, aucune exception |
| E19 | Fan-out : `findActiveWithValidTokens()` rend A1 (U1), B1 (U2, scope wallet seul), A2 (U1), A3 (U1) | Messages envoyés : `[SyncUserIndustryJobs(U1), SyncUserIndustryJobs(U2)]`. Tracker : `[['start','industry'], ['complete','industry','2 users queued for 4 chars']]` |
| E20 | Fan-out : C1 sans utilisateur, A1 (U1) | `[SyncUserIndustryJobs(U1)]` ; `'1 users queued for 2 chars'` |
| E21 | Fan-out : `dispatch` lève « async transport unreachable » | Tracker `start` puis `fail('industry', 'async transport unreachable')` ; exception relancée (inchangé) |
| E22 | Handler : message pour un utilisateur introuvable | Un avertissement ; `syncUserJobs` jamais appelé |
| E23 | Handler : utilisateur trouvé ; `syncUserJobs` lève « Connection refused » | `syncUserJobs` appelé une fois avec cet utilisateur ; l'exception remonte |
| E24 | `syncUserJobs` : Char21507 (lecture perso en 503 « Service Unavailable ») ; Char21508 sans token | (started), (in_progress, 50, `1/2 …`), (in_progress, 100, `2/2 …`), (completed, 100, `0 active jobs, 0 ready to deliver, 2 characters not synced`). `failedCharacters` = `[{Char21507, esi_unavailable, 503}, {Char21508, missing_token, null}]`. Aucun `error` ; retour normal |
| E25 | `syncUserJobs` : utilisateur sans personnage | (started), (completed, 100, `0 active jobs, 0 ready to deliver`), `data` = `{0, 0, 0, failedCharacters: []}` ; aucune progression |
| E26 | `syncUserJobs` : `getCharacters()` lève « Character collection unavailable » | (started), (error, null, `Character collection unavailable`) ; la même exception est relancée |
| E27 | `syncUserJobs` : Char21509 et Char21510, tous scopes. Jobs perso : 21509 → `[910 active]`, 21510 → `[]`. Jobs corpo : 910 `active` (21509), 911 `ready` (21510), 912 `active` (corpmate 55501) | completed `1 active jobs, 1 ready to deliver`, `data` = `{total: 2, active: 1, ready: 1, failedCharacters: []}` (la somme par personnage aurait donné 4) ; endpoint corpo lu une fois (#213) |
| E28 | `syncUserJobs` : Char21511, job perso 620 `ready` (Tritanium), dont l'envoi de notification lève « Push endpoint unreachable » ; Char21512, job perso 621 `active` | `failedCharacters` = `[{Char21511, sync_failed, null}]`. Réservations : `claim 620`, `dispatch 620`, `release 620`. completed `1 active jobs, 0 ready to deliver, 1 characters not synced`, `data.total` = 1 ; Char21512 synchronisé |
| E29 | `syncCharacterJobs` sur le scénario #213 « personnage sans rôle servi par le cache chaud » (Char55600) | Événements : (started, 0, …), (completed, 100, `1 active jobs, 0 ready to deliver`), `data` = `{total: 1, active: 1, ready: 0}` (sans `failedCharacters`) ; aucun `in_progress` |
| E30 | Ancien message `SyncCharacterIndustryJobs` | Traité comme aujourd'hui (tests de `SyncCharacterIndustryJobsHandlerTest` inchangés) |

Front : même convention que A (nouvel objet dans `syncStatus['industry-jobs']`, puis `flushPromises()`). Un « completed avec [X] » porte `data: { total: 3, active: 2, ready: 1, failedCharacters: X }`.

| # | Écran et situation | Messages reçus après montage | Attendu |
|---|---|---|---|
| E31 | `IndustrySlotsSection` | completed avec `[{Pilot Four, missing_scope, null}, {Pilot Five, esi_error, 403}]` | `GET /industry/slots` 2 fois. Bandeau `last-jobs-sync-failures`, titre « Jobs not synced for 2 characters at the last sync, the jobs shown may be out of date: ». Lignes « Pilot Four: the token lacks a required ESI permission, reconnect the character » et « Pilot Five: ESI error 403 ». Lien « Reconnect characters » vers `/characters` |
| E32 | `IndustrySlotsSection` | E31, puis completed avec `[]` | Plus de bandeau ; 3 appels |
| E33 | `IndustrySlotsSection` | E31, puis completed avec `data` = `{total: 1, active: 1, ready: 0}` | Bandeau inchangé (2 lignes) ; 3 appels |
| E34 | `IndustrySlotsSection` | E31, fermeture, puis completed avec `[{Pilot Five, esi_unavailable, 503}]` | Après fermeture : pas de bandeau. Ensuite, une seule ligne « Pilot Five: ESI unavailable (maintenance or server error), retry later ». Bouton fermer nommé `common.actions.close` |
| E35 | `IndustrySlotsSection`, FR | completed avec `[{Pilot Four, missing_scope, null}]` | Titre « Jobs non synchronisés pour 1 personnage lors de la dernière synchronisation, les jobs affichés peuvent être périmés : » |
| E36 | `StepsTab` | completed avec `[{Pilot Five, esi_error, 403}]` | Bandeau `last-jobs-sync-failures`, une ligne ; `fetchProject('project-215')` 1 fois ; pas de bandeau `jobs-sync-failures` |
| E37 | `StepsTab`, `matchJobs` en attente | completed avec `[{Pilot Five, esi_error, 403}]` | Pas de bandeau `last-jobs-sync-failures` ; `fetchProject` pas appelé par ce message |
| E38 | `IndustryDashboard` | completed avec `[{Pilot Four, missing_scope, null}]` | `GET /industry/slots` 2 fois ; pas de bandeau `last-jobs-sync-failures` |
| E39 | `IndustrySlotsSection` | `error` « boom A », puis completed avec `[{Pilot Four, missing_scope, null}]` | Deux bandeaux : `jobs-sync-error` (« boom A ») et `last-jobs-sync-failures` (Pilot Four) |

### Clés i18n (périmètre B)

| Clé | FR | EN |
|---|---|---|
| `industry.syncFailures.lastJobsSyncTitle` | `Jobs non synchronisés pour {count} personnage lors de la dernière synchronisation, les jobs affichés peuvent être périmés : \| Jobs non synchronisés pour {count} personnages lors de la dernière synchronisation, les jobs affichés peuvent être périmés :` | `Jobs not synced for {count} character at the last sync, the jobs shown may be out of date: \| Jobs not synced for {count} characters at the last sync, the jobs shown may be out of date:` |

Lignes, raisons et lien : clés existantes `industry.syncFailures.line`, `.reasons.*`, `.reconnectLink` (`en.json:1157-1171`).

### Cas limites (périmètre B)

- **Utilisateur sans personnage** (personnages supprimés entre le fan-out et le traitement) : sync vide publiée (E25).
- **Utilisateur sans personnage `valid` avec token** : pas de message (fan-out sur `findActiveWithValidTokens()`), rien n'est publié. Inchangé.
- **Tous les personnages en échec** : `completed` portant tous les échecs (E24, D16).
- **Message relancé (R14).** Chaque tentative publie `started` puis `error`, soit jusqu'à 4 lignes dans le bandeau R4, puis le message part dans `failed`.
  - Une relance resynchronise tous les personnages. La relecture reste sans effet de bord : écriture par `job_id`, réservation des notifications (#216), cache corpo (#213), donc pas de notification en double.
  - Ce cas reste rare : il faut une panne hors de la sync d'un personnage, par exemple de la base.
- **Échec d'un personnage : plus de relance Messenger.** La prochaine tentative a lieu à la sync suivante (30 min) ou sur « Matcher les jobs ».
- **Personnage révoqué d'un utilisateur actif.** Il était exclu du fan-out ; il est maintenant tenté. Coût : une lecture d'affiliation (ESI publique), puis `authentication_required` (le `TokenManager` refuse sans appel réseau). Il apparaît dans le bandeau R16 avec le lien de reconnexion. Coût accepté : un appel ESI public par personnage révoqué toutes les 30 min.
- **Gestionnaire d'entités partagé entre les personnages d'un même message.** Avant, chaque message par personnage repartait d'un gestionnaire neuf. Désormais :
  - si le `flush` d'un personnage échoue, le gestionnaire est fermé : les personnages suivants échouent en `sync_failed` ;
  - les écritures partielles d'un personnage en échec partent avec le `flush` du suivant (affiliation #211, jobs persistés avant l'échec).

  C'est déjà le cas de « Matcher les jobs ». Accepté ; la sync suivante corrige.
- **Deux syncs simultanées** (sync planifiée et « Matcher les jobs » pour le même utilisateur) : leurs événements s'entrelacent. Le front recharge à chaque `completed`, et seul le `completed` de la sync par utilisateur met à jour le bandeau R16.
- **File en retard** : deux messages pour le même utilisateur donnent deux syncs consécutives. Sans effet.
- **Bandeau récurrent** : un scope manquant de façon durable réaffiche le bandeau toutes les 30 min après fermeture. Voulu : l'utilisateur a une action à faire.
- **Slots vides** (`data.characters` vide) : le bandeau R16 n'est pas affiché, comme R4 (même emplacement).
- **Durée du message** : environ n × (affiliation + jobs perso + jobs corpo hors cache). Avec un seul worker, il retarde d'autant les messages suivants, y compris `scheduler_default`, mais le travail total ne change pas. [TO CONFIRM: nombre maximal de personnages par utilisateur en production]
- **Déploiement** : les anciens messages en file sont traités par l'ancien handler (R15). Aucune migration, aucune commande à lancer en production.

### Fichiers de production à modifier (périmètre B)

| Fichier | Changement |
|---|---|
| `src/Message/SyncUserIndustryJobs.php` (nouveau) | `final readonly`, `public string $userId` (modèle `SyncUserMiningData`) |
| `src/MessageHandler/SyncUserIndustryJobsHandler.php` (nouveau) | R10. `#[AsMessageHandler] final readonly` ; `UserRepository`, `IndustryJobSyncService`, `LoggerInterface` |
| `src/MessageHandler/SyncIndustryJobsHandler.php` | R9 : un message par utilisateur distinct, plus de test de scope, texte du tracker |
| `src/Service/Sync/IndustryJobSyncService.php` | `syncUserJobs(User): void` (R11, R12, R14). Traitement commun privé extrait de `syncCharacterJobs()` : il rend les statuts par `job_id` des jobs de l'utilisateur lus, sans événement de cycle, et envoie les notifications. `syncCharacterJobs()` réduit à la sync par personnage (R13). Retrait des `syncProgress` 30 et 60. Constante du scope perso |
| `config/packages/messenger.yaml` | `App\Message\SyncUserIndustryJobs: async` ; ligne `SyncCharacterIndustryJobs` gardée (R15) |
| `frontend/src/composables/useIndustryJobsSync.ts` | Rend aussi `failures` et `dismissFailures` (R16, R17) |
| `frontend/src/components/industry/IndustrySlotsSection.vue` | `CharacterSyncFailuresAlert` `last-jobs-sync-failures` après le bandeau R4 |
| `frontend/src/components/industry/StepsTab.vue` | Idem, après le bandeau `jobs-sync-error` |
| `frontend/src/i18n/locales/fr.json`, `en.json` | `industry.syncFailures.lastJobsSyncTitle` |
| `docs/modules/industry.md` (`:98`) | Ligne « Mercure, sync des jobs » : fan-out par utilisateur, `SyncUserIndustryJobsHandler`, `syncUserJobs`, ancien message gardé pour compatibilité |

Inchangés : `SyncCharacterIndustryJobs` et son handler, `MatchJobsProcessor`, `MatchProjectJobsCommand`, `CharacterSyncFailure`, `MercurePublisherService`, `SyncTracker`, `IndustryDashboard.vue`. Issue de suivi à ouvrir : retrait de l'ancien message après le déploiement (R15).

### Câblage des tests (périmètre B, unitaires)

**Tests existants appelés à casser :**

- `tests/Unit/MessageHandler/SyncIndustryJobsHandlerTest.php` : les 5 tests. Ils attendent des `SyncCharacterIndustryJobs` et `'x/y chars queued'`. De plus, le helper `character()` ne pose pas d'utilisateur (`getUser()` rend `null` sur le stub) : plus rien ne serait envoyé, et `dispatch` ne lèverait donc plus dans `testSyncTrackerFailsAndErrorPropagatesWhenQueueingFails`. À réécrire sur E19 à E21, avec `character()` qui reçoit un `User` stub doté de `getId()`.
- `tests/Integration/MessageHandler/ScheduledCharacterSyncFanOutTest.php` : le cas `industry jobs` du fournisseur (`:71-79`) attend un `SyncCharacterIndustryJobs` par personnage. Retirer ce cas et le `#[CoversClass(SyncIndustryJobsHandler::class)]` (`:39`) ; wallet et planetary ne changent pas.
- `IndustryJobSyncServiceTest::testProgressPublishedToCharacterWithoutCorporationRolesServedByWarmCacheCountsOnlyJobsOfItsUser` (`:988-1014`, #213) attend les progressions 30 et 60 (R13). À réécrire en E29.

**Tests qui ne doivent pas casser :**
- tests #210 et #213 sur `industryJobsSyncCompletedEvents()` : le `completed` par personnage ne change pas (R13) ;
- `testAffiliationReadFailurePropagatesBeforeAnyJobReadWithoutWriting` (#211) : toujours `[started, error]` ;
- `testFailedSendReleasesItsReservationKeepsEarlierOnesAndPropagates` (#216) : toujours une seule erreur, « Push endpoint unreachable » ;
- `testCharacterOfAnotherUserServedByCache…` : l'ordre des topics reste `[industry-jobs, industry-job-completed]`, car `started` précède la notification ;
- `MatchJobsProcessorTest` et `SyncCharacterIndustryJobsHandlerTest` : la signature de `syncCharacterJobs` ne change pas ;
- tests Vitest de A.

**Tests rouges attendus** (`#[Group('issue-215')]` côté PHP, `describe` avec `issue 215` côté front) :

- `IndustryJobSyncServiceTest` : E18, E24 à E29. Harnais existant :
  - le stub du hub de `setUp` enregistre `publishedMercureUpdates` ; un nouveau helper filtre `syncType === 'industry-jobs'` et rend `[status, progress, message, data]` ;
  - personnages : `createCharactersOfSameUserWithScopes()` ;
  - lecture perso par endpoint : `esiClient->method('get')->willReturnCallback(...)`, qui lève `EsiApiException::forbidden(endpoint: …)` ou `EsiApiException::fromResponse(503, 'Service Unavailable', …)` ;
  - jobs corpo : `givenCorporationJobs()` ; `stubCachedJobs([])`, `stubActiveJobsByCharacter([])`, `findByEsiJobIds` → `[]` ;
  - assertions : `loggedWarnings()`, `characterInfoReads`, `corporationJobsReads` ;
  - E24 : personnage sans token construit à la main (`getEveToken()` nul, `getName()` `Char21508`). Le helper fige la collection de l'utilisateur à sa création : il faut un petit helper « utilisateur avec ces personnages » ;
  - E26 : `User` stub avec `getId()`, et `getCharacters()` qui lève l'exception ;
  - E28 : `recordDispatchedNotifications(failingJobId: 620, failure: …)` et `completionNotificationEvents`.
- `tests/Unit/MessageHandler/SyncUserIndustryJobsHandlerTest.php` (nouveau) : E22, E23. Harnais de `SyncCharacterIndustryJobsHandlerTest` (`repositoryHolding` sur `UserRepository::find`, recherche par `(string) $id`, et `BufferingLogger`) ; `IndustryJobSyncService` doublé sur `syncUserJobs`.
- `tests/Unit/MessageHandler/SyncIndustryJobsHandlerTest.php` réécrit : E19 à E21, `dispatchedMessages` comparé avec `assertEquals` à des `new SyncUserIndustryJobs(...)`.
- Vitest :
  - `frontend/tests/components/industry/industrySlotsSectionJobsSyncFailures.spec.ts` : E31 à E35 et E39. Harnais de `industrySlotsSectionJobsSync.spec.ts` (A) ; `en.json` et `fr.json` ; lien vérifié avec un router mémoire, comme `stepsTabSyncFailures.spec.ts` ;
  - `stepsTabJobsSync.spec.ts` : ajout de E36 et E37 ;
  - `industryDashboardJobsSync.spec.ts` : ajout de E38.
- **Non couvert** (tests unitaires seulement) : le routage `async` de `SyncUserIndustryJobs`. Sans la ligne `messenger.yaml`, Symfony traiterait le message en synchrone, à l'intérieur du message planifié : c'est le défaut de l'issue #30. À vérifier en revue.

### Hors périmètre (B)

- Passer « Matcher les jobs » et `app:industry:match-jobs` à la sync par utilisateur (D10).
- Retrait de `SyncCharacterIndustryJobs` (issue de suivi, R15).
- Indicateur de progression à l'écran (R8, D17).
- Nommer le personnage dans un `error` de la sync par personnage (R4 de A).

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

- Ajout : **Fin de sync des jobs** (Jobs sync end). Message Mercure `industry-jobs` au statut `completed`. La sync planifiée en publie un par utilisateur, après son dernier personnage, avec les personnages en échec (`failedCharacters`). « Matcher les jobs » et `app:industry:match-jobs` en publient un par personnage, sans `failedCharacters`. Les écrans industrie qui affichent des jobs (slots, Dashboard, étapes d'un projet) se rechargent à chaque fin reçue. Code : `IndustryJobSyncService::syncUserJobs()`, `IndustryJobSyncService::syncCharacterJobs()`, `useIndustryJobsSync`.
- Ajout : **Sync des jobs d'un utilisateur** (User jobs sync). Message `SyncUserIndustryJobs` traité en une fois pour tous les personnages d'un utilisateur. Événements : un `started`, une progression par personnage, une seule fin de sync des jobs. L'échec d'un personnage ne l'interrompt pas : le personnage est nommé dans la fin, avec les codes de #197. Code : `SyncUserIndustryJobsHandler`, `IndustryJobSyncService::syncUserJobs()`.

# #838 — Transport et worker dédiés aux syncs à la demande, alerte message non acquitté

Décision de Sylvain (2026-11-13, option A ; 2026-11-14, autorisée la nuit) : les syncs à la demande d'assets quittent le transport `async`, un second service `worker` les consomme, l'état admin signale un message resté en cours trop longtemps. Circuit `m`, pas de note de câblage.

## Contexte vérifié dans le code

- `config/packages/messenger.yaml` : un seul transport `async` (DSN `MESSENGER_TRANSPORT_DSN`, exchange `messages`), tous les messages y sont routés ; `when@test` le remplace par `in-memory://`.
- `worker` (docker-compose.yaml, docker-compose.prod.yml, docker-compose.override.yml) consomme `async` puis `scheduler_default`. Depuis #737 chaque tâche planifiée est redispatchée vers `async`.
- `MessengerQueueService::getDepth()` ne connaît que `async` et `failed`. Le transport AMQP ne sait compter que les messages prêts, pas les non acquittés (le RabbitMQ de prod n'a pas le plugin management).
- `AdminStatusEvaluator::evaluate()` produit des `AdminStatusRule` (clé, gravité, valeur, seuil, section) ; les libellés vivent dans `frontend/src/i18n/locales/{fr,en}.json` sous `rules`.

## Règles

1. **Transport `on_demand`.** Nouveau transport dans `messenger.yaml`, même DSN, avec `options` : exchange `on_demand` et une seule file `on_demand` (la file `messages` de `async` n'est pas touchée). Même `retry_strategy` que `async`. Dans `when@test`, `on_demand` est `in-memory://` comme `async`.
2. **Routage.** `SyncCharacterAssets`, `SyncCorporationAssets`, `TriggerAssetsSync`, `TriggerCorporationAssetsSync` passent de `async` à `on_demand`. Aucun autre message ne bouge, aucun message planifié (ceux de `SyncScheduler`) n'est routé vers `on_demand`.
3. **Compat des messages déjà en file.** Le `worker` existant garde `async` et `scheduler_default` dans sa commande : les messages assets déjà publiés sur `async` avant le déploiement sont consommés normalement.
4. **Service `worker-on-demand`.** Défini dans `docker-compose.yaml` (image `evetools-app`, entrypoint `php`, mêmes variables d'environnement que `worker`, donc Mercure compris, même healthcheck, `depends_on` app et rabbitmq, adresse IP propre dans `evetools_net`) avec la commande `bin/console messenger:consume on_demand --time-limit=3600 --memory-limit=256M`.
   - `docker-compose.prod.yml` : image `ghcr.io/srekcud/evetools-app:${APP_VERSION:?…}`, `-d memory_limit=448M`, limite de conteneur 512M (valeurs identiques à `worker`), mêmes `environment` que `worker` en prod.
   - `docker-compose.override.yml` : volume `.:/app`, `APP_ENV=dev`, `-vv` comme `worker`.
   - Le choix de 512M est une valeur par défaut, la RAM libre du serveur de prod n'a pas été lue (voir « Actions »).
5. **Compteur de profondeur.** `MessengerQueueService::getDepth('on_demand')` est accepté ; `GET /api/admin/queues` renvoie en plus `queues.on_demand` (clé ajoutée, `async` et `failed` inchangées, `null` si le transport est injoignable).
6. **Suivi des messages en cours.** Nouveau service `InFlightMessageRegistry` adossé au cache applicatif (`cache.app`), une entrée par nom de récepteur (`async`, `on_demand`, `scheduler_default`, `failed`) : classe du message et heure de début.
   - `start(receiver, messageClass)` écrase l'entrée du récepteur, `finish(receiver)` la supprime.
   - `oldestRunningSeconds(): ?int` renvoie l'âge en secondes de l'entrée la plus ancienne, `null` si aucune.
   - Un écouteur d'événements `#[AsEventListener]` appelle `start` sur `WorkerMessageReceivedEvent` et `finish` sur `WorkerMessageHandledEvent` et `WorkerMessageFailedEvent` (que le message soit retenté ou non), avec `getReceiverName()`.
   - TTL de l'entrée : 24 h, pour qu'un worker tué en cours de message (OOM) laisse une alerte, pas une entrée éternelle.
7. **Règle d'état admin.** `AdminStatusEvaluator::evaluate()` reçoit `?int $oldestRunningSeconds` en dernier paramètre. Au-dessus de `IN_FLIGHT_MESSAGE_DEGRADED_SECONDS = 3600` (strictement), une règle `message_unacked_too_long` de gravité `degraded`, valeur = secondes, seuil = 3600, section `maintenance`. `null` ou ≤ 3600 : aucune règle. Pas de gravité `down`.
   - `AdminStatusProvider` lit `InFlightMessageRegistry::oldestRunningSeconds()` et le passe à l'évaluateur.
8. **i18n.** Clé `rules.message_unacked_too_long` en français et en anglais, avec `{count}` (secondes) et `{threshold}`.
9. **Documentation.** `docs/deploy.md` : section courte « Worker des syncs à la demande » (rôle des deux workers, commande de démarrage, avertissement que `bin/deploy` ne connaît que `app worker`, vérification de la RAM libre avant le premier démarrage). Pas de commentaire dans le code.

## Exemples chiffrés (règle 7)

| Plus ancien message en cours | Règle émise |
|---|---|
| aucun (`null`) | aucune |
| 0 s, 3599 s, 3600 s | aucune |
| 3601 s | `message_unacked_too_long`, `degraded`, valeur 3601, seuil 3600 |
| 39600 s (11 h, incident du 2026-11-13) | idem, valeur 39600 |

Avec une règle seule, le niveau global est `degraded` ; combinée à `async_queue_unreachable` (`down`), il reste `down`.

## Cas limites

- Deux messages en cours sur deux récepteurs : `oldestRunningSeconds()` prend le plus ancien.
- `finish` d'un récepteur sans entrée : sans effet, sans exception.
- Un message qui échoue puis est retenté : l'entrée disparaît à l'échec, une nouvelle naît à la réception suivante.
- Lecture du cache en échec : l'erreur remonte (pas de `?? 0`, pas de catch silencieux sur l'état).

## Hors périmètre

- `bin/deploy` (arrêt et démarrage de `worker app` seulement), Makefile (`make messenger`, `worker-restart`), CI : non modifiés, voir « Actions ».
- Pas de gravité `down`, pas de tuile front pour `queues.on_demand`, pas de publication Mercure (aucune nouvelle action asynchrone ; l'état admin est relu à l'ouverture de la page et sur `/admin/health` existant).
- Aucun réglage, constante, route ou écran retiré.

## Décisions

- Option A de Sylvain, appliquée telle quelle ; le seuil de 3600 s reprend `SyncTracker::ON_DEMAND_STUCK_RUNNING_SECONDS`.
- Détection par écouteurs Messenger plutôt que par l'API de management RabbitMQ (non installée en prod), avec la limite : un seul message suivi par récepteur.

## Actions (déploiement, hors dépôt)

- Lire `free -m` sur le serveur de prod avant de fixer la limite du second worker (512M par défaut ici).
- Démarrer : `docker compose -f docker-compose.yaml -f docker-compose.prod.yml up -d worker-on-demand` (avec `APP_VERSION` exporté).
- Avant la release suivante : ajouter `worker-on-demand` aux trois commandes de `bin/deploy` (stop, start, up --wait), sinon il garde l'ancienne image.
- Alerte Prometheus côté Flux : hors dépôt.

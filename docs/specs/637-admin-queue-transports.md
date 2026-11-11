# Spec #637 — queue depths and failed purge read the Messenger transports

Mode nuit : spec validée en autonomie (circuit:m).

## Contexte

`AdminService::getQueueStatus()` compte les lignes de `messenger_messages` (Postgres) et `PurgeFailedProcessor` y fait un `DELETE`, alors que les transports `async` et `failed` (`config/packages/messenger.yaml`) sont sur `MESSENGER_TRANSPORT_DSN` (AMQP). Les tuiles « Queue » et « Failed » de `AdminKpiGrid.vue` affichent `0` ou `--` quelle que soit la profondeur réelle, et « Purge failed » annonce un succès sans rien retirer de RabbitMQ.

## Règles

1. Un service `App\Service\Admin\MessengerQueueService` reçoit les transports `async` et `failed` (`#[Autowire(service: 'messenger.transport.async')]` et `'messenger.transport.failed'`).
2. `getDepth(string $transportName): ?int` : renvoie `getMessageCount()` du transport quand il implémente `MessageCountAwareInterface` ; renvoie `null` (état « inconnu ») quand il ne l'implémente pas ou quand la lecture lève une exception. Jamais `0` par défaut.
3. `purgeFailed(): int` : vide le transport `failed` en lisant (`get()`) puis en acquittant (`ack()`) chaque message jusqu'à ce que `get()` ne renvoie plus rien ; renvoie le nombre de messages réellement retirés. Une exception du transport remonte (pas de catch silencieux dans le service).
4. `QueueStatusProvider` lit les profondeurs via `MessengerQueueService` ; la réponse `GET /api/admin/queues` garde la forme `{queues: {async: int|null, failed: int|null}}`. Une profondeur illisible reste `null` et la tuile affiche `--` (déjà en place côté front).
5. `PurgeFailedProcessor` appelle `MessengerQueueService::purgeFailed()` ; `deleted` porte le nombre retiré et le message `Purged N failed messages`. Une exception : `success = false`, message `Error: …`, `deleted` reste `null`.
6. `AdminService::getQueueStatus()` et son test `testGetQueueStatusStructure` sont supprimés : la méthode ne lisait que la mauvaise source et n'a plus d'appelant. Le constructeur d'`AdminService` ne change pas.

## Exemples (tests rouges)

| Cas | Entrée | Sortie |
|---|---|---|
| Profondeur async | transport comptable à 7 | `getDepth('async') === 7` |
| Profondeur failed vide | transport comptable à 0 | `getDepth('failed') === 0` (connu, pas inconnu) |
| Transport non comptable | transport sans `MessageCountAwareInterface` | `getDepth('async') === null` |
| Lecture en échec | `getMessageCount()` lève | `getDepth('failed') === null` |
| Nom inconnu | `getDepth('other')` | `\InvalidArgumentException` |
| Purge | `InMemoryTransport` avec 3 messages | `purgeFailed() === 3`, transport vide ensuite (`getSent()` inchangé, `get()` vide) |
| Purge vide | transport vide | `purgeFailed() === 0` |
| Provider | service renvoie async 5, failed null | resource `queues = {async: 5, failed: null}` |
| Processor | service renvoie 4 | `success`, `deleted === 4`, message `Purged 4 failed messages` |
| Processor en échec | service lève | `success === false`, `deleted === null` |
| Accès (inchangé) | non-admin | 403 via `AdminChecker` (tests existants, pas de nouveau test) |

## Cas limites

- Le transport `failed` en AMQP ne propose pas `ListableReceiverInterface` ni de purge native : le drain `get()` + `ack()` est le seul moyen sans API de management.
- En env test les deux transports sont `in-memory://` (pas de `MessageCountAwareInterface`) : la profondeur y est `null`.

## Hors périmètre

- Le bouton « Retry failed » (`RetryFailedProcessor`) et le consommateur de `failed`.
- Les autres tuiles d'`AdminKpiGrid` et le Mercure de la page admin (la purge reste synchrone, aucune action async nouvelle).
- Tout appel à l'API de management RabbitMQ.

## Accès et fraîcheur

Aucun changement : `AdminChecker::isAdmin` dans le provider et le processor (préexistant). Les profondeurs sont lues à chaque requête, sans cache.

## Tests existants contredits

- `tests/Unit/Service/Admin/AdminServiceTest.php::testGetQueueStatusStructure` : supprimé (règle 6).

## Glossaire

Aucun terme ajouté.

## Après déploiement

Aucune migration ni commande. Dans la table `messenger_messages`, rien n'est lu ni supprimé.

# #644 — Câblage : explorateur des messages en échec

Spec : `docs/specs/644-admin-failed-messages-explorer.md`. Sources lues : `symfony/doctrine-messenger` v7.4.23 (archive GitHub), vendor du checkout principal (messenger 7.4.4, doctrine-bridge 7.4.4, ORM, DBAL 4.4.1).

## Dépendances
1. **Package** `symfony/doctrine-messenger:^7.4` : `composer require` résolu sur le lock actuel → **v7.4.23**, une seule ligne ajoutée (requiert `doctrine/dbal ^3.6|^4`, `symfony/messenger ^7.2|^8.0` : compatibles 4.4.1 / 7.4.4).
   - `DoctrineTransport` implémente `ListableReceiverInterface` (`all(?int $limit)`, `find($id)`), `MessageCountAwareInterface`, `SetupableTransportInterface`, `KeepaliveReceiverInterface`.
   - `findAll()` n'a **pas d'ORDER BY** et filtre `delivered_at`/`available_at` : « 100 plus récents » = tout lire puis trier par id décroissant (id auto-incrémenté).
   - `find()` renvoie `null` sur id non numérique ou hors BIGINT : le 404 vient de là, pas d'une exception SQL.
2. **Schéma** (`Connection::buildSchemaTable`) : table `messenger_messages`, colonnes `id` BIGINT autoincrement PK, `body` TEXT, `headers` TEXT, `queue_name` VARCHAR(190), `created_at`/`available_at` DATETIME_IMMUTABLE NOT NULL, `delivered_at` DATETIME_IMMUTABLE NULL ; un index sur (`queue_name`, `available_at`, `delivered_at`, `id`). Pas de trigger NOTIFY en 7.4.23 (`PostgreSqlConnection` ne surcharge pas `getExtraSetupSqlForTable`).
3. **Migration** : `MessengerTransportDoctrineSchemaListener::postGenerateSchema` (enregistré par DoctrineBundle, `config/messenger.php`) appelle `configureSchema` pour tout `DoctrineTransport`, **indépendamment d'`auto_setup`** → `doctrine:migrations:diff` (env dev) produit la table et son index. Garde : relancer `diff` après migration = « No changes detected », et `doctrine:schema:validate` vert en dev. Nom d'index : celui que génère le diff, ne pas le réécrire.
4. **Config** `messenger.yaml` : `failed: 'doctrine://default?queue_name=failed&auto_setup=false'` ; `when@test` : même DSN (voir harnais). Connexion DBAL `default` = celle de l'ORM.
5. **Sérialiseur** : aucun `serializer` configuré → `PhpSerializer`. Il ne pose **pas d'en-tête `type`** (encode seulement `body`) : la classe d'un message disparu se lit sur `__PHP_Incomplete_Class_Name`, pas dans un en-tête (corrige le cas limite de la spec).
   - **Piège bloquant** : `DoctrineReceiver::createEnvelopeFromData` fait `reject` (DELETE) puis relance l'exception quand le décodage échoue. Lister une classe disparue sans précaution **efface la ligne**. Il faut appeler `acceptPhpIncompleteClass()` / `rejectPhpIncompleteClass()` (service `messenger.transport.native_php_serializer`, méthodes `@internal`) autour de `all()`/`find()`, comme `FailedMessagesRetryCommand`. L'enveloppe porte alors `MessageDecodingFailedStamp` : la relance est refusée (erreur explicite), le retrait est possible.

## Relance par id (règle 6)
`messenger:failed:retry --force <id>` = `find()` puis `Worker` sur `SingleMessageReceiver` : le handler tourne **dans le processus appelant** (ici la requête HTTP) ; `FailedMessageProcessingMiddleware` remplace le `ReceivedStamp` par le transport d'origine ; en cas d'échec, `SendFailedMessageForRetryListener` prend la stratégie et le sender du receiver `failed` (stratégie par défaut 3/1 s/×2) et renvoie dans `failed`.
Proposition, conforme au tableau d'exemples (« message sur async ; absent de failed ») : **réexpédier sans exécuter**.
1. `find(id)` sous `acceptPhpIncompleteClass` ; `null` → `FailedMessageNotFound` (404) ; `MessageDecodingFailedStamp` → `FailedMessageNotRedeliverable` (422).
2. Retirer `ReceivedStamp`, `TransportMessageIdStamp`, `DoctrineReceivedStamp`, `RedeliveryStamp`, `SentToFailureTransportStamp`. Garder ce dernier perdrait le message au prochain échec : `SendFailedMessageToFailureTransportListener` ne renvoie pas un message qui le porte.
3. `send` sur le sender `SentToFailureTransportStamp::getOriginalReceiverName()` (locator `messenger.receiver_locator`, clés = noms de transport), puis `reject` dans `failed`. Ordre envoi puis retrait : un doublon possible plutôt qu'une perte.

Classe : `App\Service\Admin\FailedMessageService` (final, constructeur : transport `failed` typé `TransportInterface&ListableReceiverInterface`, `ContainerInterface` des transports, `PhpSerializer`). Méthodes : `list(?string $messageClass): FailedMessageList`, `retry(string $id): void`, `remove(string $id): void`, `retryClass(string $messageClass): FailedMessageClassOutcome`, `removeClass(...)`. Constantes `FAILED_MESSAGES_PAGE_SIZE = 100`, `EXCEPTION_MESSAGE_MAX_LENGTH = 500`, `CLASS_ACTION_LIMIT = 500`. Lecture : `FailedMessage` (id, messageClass, exceptionClass, exceptionMessage, failedAt, retryCount), `FailedMessageList` (total, classes, messages), `MessageClassCount`. `TransportException` → `FailedMessagesUnavailable` (503). Pas d'interface : la couture est le transport, les tests unitaires passent un faux transport listable.

## Harnais de test
- **Unit** : `tests/Unit/Service/Admin/ListableInMemoryTransport.php`, `InMemoryTransport` + `ListableReceiverInterface` + `MessageCountAwareInterface`, ids séquentiels via `TransportMessageIdStamp`. Le `PhpSerializer` est réel ; le locator est un `ServiceLocator` de transports en mémoire.
- **Functional/Integration** : `when@test` `failed` = `doctrine://default?queue_name=failed&auto_setup=false`. La base de test vient des migrations (`make test-db`), DAMA isole par transaction. Le vrai bridge couvre le piège de décodage et le 404. Le seed passe par `messenger.transport.failed->send()`. Classe disparue : insertion DBAL d'un body sérialisé d'une classe inexistante.
- **Mercure, ESI** : non concernés.

## Tests existants qui cassent
- `tests/Integration/Migrations/MigrationsReplayTest.php` : **casse à coup sûr**. `new EntityManager($replayConnection, …)` sans EventManager (DBAL 4 n'en a plus sur la connexion) → pas de schema listener → `SchemaValidator` propose `DROP TABLE messenger_messages`. Passer l'EventManager du test ne suffit pas : `isSameDatabase` répond faux pour la base de replay. Correctif : exclure `messenger_messages` dans `configurationIgnoringMigrationsTable()`, comme la table des migrations.
- `MessengerQueueServiceTest`, `PurgeFailedProcessorTest`, `QueueStatusProviderTest`, `AdminStatusProviderTest` : **ne cassent pas** si la signature de `MessengerQueueService` (`TransportInterface` ×2) reste telle quelle, ce que demande la règle 9.
- Avec Doctrine en test, `AdminStatusProvider` lit `getDepth('failed')` = 0 au lieu de `null` (InMemory n'est pas MessageCountAware). Aucun test Functional ne l'assert (grep `failed` dans `tests/Functional`, `tests/Integration`).
- Tests lisant `messenger.transport.async` en `InMemoryTransport` (`AdminUserSupportApiTest`, `MoonTaxApiTestCase`, `TriggerMoonTaxSyncHandlerTest`…) : inchangés, `async` reste `in-memory://`.

## Découpage des fichiers
- Back : `src/ApiResource/Admin/FailedMessagesResource.php` (GET `/admin/failed-messages`, sans identifiant, filtre `messageClass` en query) ; `FailedMessageResource.php` (POST `/{id}/retry` `input: EmptyInput::class` ; DELETE `/{id}` avec provider, `uriVariables: ['id' => new Link(fromClass: self::class)]`) ; `FailedMessageClassActionResource.php` (POST `retry-class` / `remove-class`, input `FailedMessageClassInput`, sortie processed/remaining).
- Providers/processors dans `src/State/Provider/Admin/` et `src/State/Processor/Admin/`, contrôle `AdminChecker` dans chacun. Service et DTO de lecture dans `src/Service/Admin/`.
- Deptrac : StateProvider/StateProcessor → Service et Security permis, Service → Vendor permis ; rien de nouveau.
- Front : `frontend/src/components/admin/FailedMessagesCard.vue`, `frontend/src/composables/useFailedMessages.ts` (état local, pas d'ajout au store `admin.ts`). Montage dans `Admin.vue`, section `#admin-section-maintenance`. `ConfirmModal` sur les retraits. Clés `admin.failedMessages.*` dans `frontend/src/i18n/locales/{fr,en}.json`.
- Migration : `migrations/Version<diff>.php`, générée par `doctrine:migrations:diff`.

## Risques
- Worker `async` en cours : son échec arrive dans `failed` par un INSERT sur la connexion DBAL du worker. Si le handler laisse une transaction avortée (pas de middleware `doctrine_transaction`/`doctrine_close_connection` sur le bus par défaut), l'INSERT échoue et le message AMQP est redélivré, pas perdu. À surveiller.
- Doublon au retry : envoi puis `reject`. Si `reject` échoue, le message part sur `async` et reste dans `failed`. Préférable à une perte.
- Id déjà retiré : `find()` → `null` → 404. `reject` (DELETE 0 ligne) n'est pas une erreur pour Doctrine, d'où le `find` préalable obligatoire.
- Action prod : vider ou relancer la file AMQP `failed` avant le déploiement, puis `doctrine:migrations:migrate`.

## Questions de spec ouvertes
1. Règle 6 : « équivalent `--force` » exécute le handler dans la requête HTTP. La note propose une réexpédition vers le transport d'origine, conforme au tableau. Sylvain valide-t-il ?
2. `retryCount` : à l'entrée dans `failed`, le bridge ajoute `RedeliveryStamp(0)` ; `last()` vaut donc toujours 0. Proposition : le max des `RedeliveryStamp`, `null` si aucun. `failedAt` = `redeliveredAt` du dernier `RedeliveryStamp`.
3. Cas limite « en-tête `type` » : à remplacer par `__PHP_Incomplete_Class_Name` (PhpSerializer).

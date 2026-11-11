# #644 — Admin: failed messages explorer (retry and remove by message or class)

Module : infra (page admin, Messenger). Circuit l. Mode nuit : spec validée en autonomie, les décisions ci-dessous sont celles de l'issue (commentaire du 2026-11-11) ou marquées « décision nuit ».

## Contexte
Les messages en échec ne se relancent (`RetryFailedProcessor`, 50 au plus) ou ne se purgent (`PurgeFailedProcessor`) qu'en bloc. `AmqpReceiver` n'implémente pas `ListableReceiverInterface` : on ne peut ni lister ni cibler un message. Décision de Sylvain : le transport `failed` passe en Doctrine (`symfony/doctrine-messenger`, table `messenger_messages` créée par migration), `async` reste en AMQP.

## Règles
1. `config/packages/messenger.yaml` : `failed` = `doctrine://default?queue_name=failed&auto_setup=false`. La table vient d'une migration, jamais de `auto_setup`. `failure_transport: failed` et la stratégie de reprise de `async` (3 reprises, délai 1 s, ×2) ne changent pas.
2. `GET /api/admin/failed-messages` (admin seulement, comme les autres ressources `Admin`) renvoie `total`, `classes[]` (`messageClass`, `count`, triées par `count` décroissant puis nom) et `messages[]`.
3. Un message liste : `id`, `messageClass`, `exceptionClass`, `exceptionMessage`, `failedAt` (ISO 8601), `retryCount`. Source : enveloppe (`ErrorDetailsStamp`, `SentToFailureTransportStamp`, `RedeliveryStamp`). Une valeur absente de l'enveloppe vaut `null`, jamais une valeur par défaut (`retryCount` inclus).
4. Filtre optionnel `?messageClass=<FQCN>`. `messages[]` : les 100 plus récents d'abord (constante `FAILED_MESSAGES_PAGE_SIZE`), `total` et `classes[]` portent sur l'ensemble des messages, pas sur la page.
5. `exceptionMessage` est tronqué à 500 caractères (constante) ; il n'est ni journalisé ni recopié ailleurs (il peut contenir un nom de personnage).
6. Relancer un message (`POST /api/admin/failed-messages/{id}/retry`) : même effet que `messenger:failed:retry --force <id>` — le message retourne sur son transport d'origine (`SentToFailureTransportStamp::getOriginalReceiverName()`), la stratégie de reprise de `messenger.yaml` s'applique, il quitte `failed`. S'il échoue encore, il revient dans `failed` avec son nouveau détail d'erreur.
7. Retirer un message (`DELETE /api/admin/failed-messages/{id}`) le supprime de `failed` sans l'exécuter. Un `provider` renvoie la ressource (règle DELETE du projet).
8. Par classe : `POST /api/admin/failed-messages/retry-class` et `POST /api/admin/failed-messages/remove-class`, corps `{ "messageClass": "<FQCN>" }`, appliquent 6 ou 7 à tous les messages de cette classe, 500 au plus par appel (constante). Réponse : `processed` (nombre traité) et `remaining` (messages de la classe restants).
9. Les actions globales existantes (`/admin/actions/retry-failed`, `/admin/actions/purge-failed`, tuile « Failed » du bandeau, `MessengerQueueService::getDepth()` et `purgeFailed()`) restent valides sur le transport Doctrine ; aucun changement de contrat.
10. Page admin, onglet Santé, section Maintenance : carte « Messages en échec » (FR et EN) avec un bloc par classe (compteur, boutons « Relancer la classe » et « Retirer la classe »), puis le tableau des messages (classe courte, exception, message, date, reprises, boutons « Relancer » et « Retirer »). Tout retrait (un message ou une classe) passe par `ConfirmModal`, comme `purgeFailed`. Un clic de classe met à jour la liste ; un état vide dit « aucun message en échec ».
11. Technique : `messenger_messages` seule porte les messages en échec ; la migration crée la table et ses index tels que le bridge les décrit (`php bin/console doctrine:migrations:diff` doit ne rien proposer de plus ; `doctrine:schema:validate` vert).

## Exemples chiffrés (tests rouges)
| Etat de `failed` | Appel | Résultat |
|---|---|---|
| 3 × `SyncCharacterAssets`, 1 × `SyncBlueprints` | GET | `total` 4 ; `classes` = [`SyncCharacterAssets` 3, `SyncBlueprints` 1] ; 4 messages |
| 120 messages | GET | `total` 120 ; `messages` 100, les plus récents ; `classes` sur 120 |
| idem | GET `?messageClass=SyncBlueprints` | `messages` = 1 ligne ; `total` 120 ; `classes` inchangé |
| enveloppe sans `RedeliveryStamp` | GET | `retryCount` `null` |
| exception de 800 caractères | GET | `exceptionMessage` de 500 caractères |
| message `id` 7 | POST retry | message sur `async` ; absent de `failed` |
| message `id` 7 | DELETE | absent de `failed`, rien sur `async` |
| 3 × classe A, 1 × classe B | POST retry-class A | `processed` 3, `remaining` 0 ; B reste |
| 600 × classe A | POST remove-class A | `processed` 500, `remaining` 100 |
| id inconnu | POST retry / DELETE | 404 |
| non admin | toute route | 403 |

## Cas limites
- Classe de message disparue (renommée après déploiement) : le message est listé avec la classe lue dans l'en-tête `type` du transport ; la liste ne plante pas. Le relancer échoue avec une erreur explicite, il reste dans `failed`.
- Message retiré ou relancé entre la liste et le clic : 404 explicite, pas de succès silencieux, la liste est rechargée.
- Transport `failed` injoignable (base indisponible) : erreur 503 explicite sur la liste et les actions, jamais une liste vide.
- Classe sans message : `processed` 0, `remaining` 0, 200.

## Accès et fraîcheur
Admin seulement (`AdminChecker::isAdmin`), vérifié dans chaque provider et processor comme `RetryFailedProcessor`. Aucun identifiant d'un autre périmètre. Lecture en direct, sans cache. `exceptionMessage` peut contenir un nom de personnage : page admin seulement, rien dans les journaux, issues ou commentaires.

## Tests existants modifiés
`tests/Unit/Service/Admin/MessengerQueueServiceTest.php` : à relire si le test fige le type du transport `failed` ; l'architecte liste les autres (config `when@test` de `failed`, harnais).

## Hors périmètre
Éditer un message avant relance ; déplacer les messages AMQP `failed` existants vers la table (action prod : les vider ou les relancer avant le déploiement) ; changer le transport `async` ; un journal des relances ; les compteurs par classe sur le bandeau d'état (#642).

## Termes du glossaire ajoutés
**Message en échec** (Failed message) : message dont toutes les reprises ont échoué, gardé dans le transport `failed` (table `messenger_messages`) avec sa classe, l'exception, sa date d'échec et son nombre de reprises. **Classe de message** (Message class) : nom complet de la classe PHP du message ; sert à grouper, relancer ou retirer les messages en échec par lot.

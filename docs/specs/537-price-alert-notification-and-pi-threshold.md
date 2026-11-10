# Spec #537 : alerte prix notifiée et réarmable, seuil PI respecté

Circuit `circuit:m`. Technicité attendue : niveau 3 par le seul critère « assertion d'un test verrouillé modifiée » (caractérisation `market-audit-2` inversée) ; sans lui, niveau 2 (migration, fenêtre KPI modifiée). Aucun voter, aucune suppression. Une PR, pas de push sur `main`.

## Contexte

- Une alerte prix franchie passe `triggered` et ne publie qu'un événement Mercure brut (`MarketAlertService::checkAlerts`). Aucune `Notification` : rien dans la cloche, pas de push.
- Une alerte ne peut qu'être supprimée. `triggered` est définitif.
- Le seuil d'expiration d'un extracteur PI vaut 24 h pour `PlanetaryStatsProvider`, `PlanetaryResourceMapper` (`isExpiringSoon(24)`) et `usePlanetaryTimers.ts` (`hours < 24`), 2 h codé en dur dans `PlanetarySyncService::checkExpiringExtractors`. La préférence `planetary.thresholdMinutes` (défaut 120) est éditable (`NotificationSettings.vue`) mais jamais lue.
- `checkExpiringExtractors` tourne à chaque sync (30 min) et notifie sans déduplication.

## Règles métier

### Alerte prix
1. Au déclenchement, `MarketAlertService` appelle `NotificationDispatcher::dispatch` pour le propriétaire : catégorie `price`, niveau `warning`, titre et message en anglais (nom de l'item, direction, seuil, prix courant, source), `route` = `/market?tab=detail&typeId=<typeId>` (route déjà lue par `MarketBrowser.vue`). Le dispatcher applique la préférence `price` (désactivée = aucune notification, push selon `pushEnabled`).
2. L'événement Mercure `alerts/market-price` reste publié à l'identique (il alimente « À faire »).
3. Une alerte déclenchée notifie une seule fois : elle n'est plus `active`, donc plus évaluée.
4. Nouveau PATCH `/market/alerts/{id}` (`application/merge-patch+json`, `uriVariables` avec `Link`, provider d'item existant) modifiant `threshold` et `direction`. Source de prix et item ne sont pas modifiables.
5. Réarmement : enregistrer une alerte `triggered` la repasse `active` et remet `triggeredAt` à `null`, que le seuil ait changé ou non. Enregistrer une alerte `active` ne touche pas son statut. Une alerte `expired` n'est pas modifiable (409).
6. Validation : `threshold > 0` (même règle et même message que la création), `direction` dans `above` / `below`, sinon 400.
7. Front, onglet Alertes (`AlertsPanel.vue`) : bouton Modifier par ligne ouvrant `AlertFormModal.vue` en mode édition (seuil et direction, item et source en lecture seule) ; sur une alerte `triggered`, le bouton d'enregistrement dit qu'il réarme.

### Seuil PI
8. Le seuil PI d'un utilisateur est `thresholdMinutes` de sa préférence `planetary`. Défaut : **1440 min** (constante nommée, remplace 120 dans `DEFAULT_THRESHOLDS`). Préférence absente ou `thresholdMinutes` nul : le défaut (valeur documentée, pas une donnée ESI).
9. Ce seuil s'applique à : la notification et l'alerte Mercure de `checkExpiringExtractors` (propriétaire du personnage), `expiringExtractors`/`nearestExpiry` de `PlanetaryStatsProvider`, `status` et `isExpiringSoon` de `PlanetaryResourceMapper`, les minuteurs et le libellé « dans les N h » du front. Un extracteur déjà expiré reste `expired`, hors seuil.
10. Validation à l'enregistrement de la préférence `planetary` : entier entre 15 et 10080 (7 jours), sinon 400 ; aujourd'hui aucune borne.
11. Niveau d'une notification d'expiration inchangé : `critical` sous 30 min, `warning` au-delà du seuil de 30 min, `error` à l'expiration.
12. Déduplication : une notification par extracteur et par niveau. La pin mémorise le dernier niveau notifié pour son `expiryTime` ; une notification n'est envoyée que si le niveau est strictement supérieur au mémorisé (`warning` < `critical` < `expired`). Un `expiryTime` modifié par la sync (nouveau cycle d'extraction) remet la mémoire à zéro. La mémoire est posée avant l'envoi, comme `completionNotifiedAt` (spec #216) : un échec d'envoi ne se rejoue pas.
13. Migration : colonne(s) de mémoire sur `planetary_pins`, et `UPDATE user_notification_preferences SET threshold_minutes = 1440 WHERE category = 'planetary' AND threshold_minutes = 120` (le 120 existant vient du défaut, la valeur n'a jamais été lue).

## Exemples chiffrés (tests rouges)

| # | Entrée | Sortie attendue |
|---|---|---|
| 1 | Alerte `below` 9,125 sur Pyerite, prix jita_buy 8,6875 | 1 notification `price`, route `/market?tab=detail&typeId=<id>`, propriétaire seul |
| 2 | Idem, préférence `price` désactivée | alerte `triggered`, 0 notification, Mercure publié |
| 3 | Idem, `pushEnabled` | push tenté via le dispatcher (erreur de push : loguée, sans effet sur l'alerte) |
| 4 | PATCH seuil 8 sur alerte `triggered` | `status = active`, `triggeredAt = null`, `threshold = 8` |
| 5 | PATCH `{}` sur alerte `triggered` | réarmée, seuil inchangé |
| 6 | PATCH seuil 12 sur alerte `active` | reste `active`, seuil 12 |
| 7 | PATCH seuil 0 / direction `sideways` | 400 |
| 8 | PATCH d'une alerte d'un autre utilisateur | 403 (contrôle de `MarketAlertDeleteProvider`) |
| 9 | Seuil PI 360 min ; extracteur expire dans 300 min | signalé (stats, mapper, notification `warning`) |
| 10 | Seuil PI 360 min ; extracteur expire dans 420 min | non signalé, compté « actif » |
| 11 | Seuil PI absent ; extracteur dans 23 h / 25 h | signalé / non signalé |
| 12 | Même extracteur à 3 syncs successives (dans 5 h, 4 h, 3 h) | 1 notification `warning` |
| 13 | Le même passe à 20 min, puis à 10 min | 1 `critical` en tout, puis 1 `error` à l'expiration |
| 14 | Sync qui change `expiryTime` d'un extracteur déjà notifié | mémoire remise à zéro, notification possible au nouveau cycle |
| 15 | Préférence `planetary` à 5 ou 20000 | 400 ; 15 et 10080 acceptés |
| 16 | Migration sur lignes `planetary` à 120, 360 et `category = industry` à 120 | 1440, 360, 120 |

## Cas limites
- Alerte dont l'utilisateur n'a pas d'identifiant (`$userId === ''`) : pas de notification, comme pas de Mercure aujourd'hui.
- Une erreur du dispatcher sur une alerte n'empêche ni son passage `triggered` ni l'évaluation des suivantes.
- PATCH d'une alerte `triggered` dont le prix courant franchit déjà le nouveau seuil : réarmée `active`, déclenchée au prochain contrôle (30 min), pas de déclenchement synchrone.
- Une alerte réarmée dont la source est `structure_*` sans structure préférée : comportement actuel (reste `active`, audit #11), non modifié.
- Seuil PI : un utilisateur sans préférence `planetary` en base n'est pas créé par la lecture (le défaut s'applique en mémoire).
- Extracteur sans `expiryTime` : ignoré comme aujourd'hui, jamais de valeur par défaut.

## Accès et fraîcheur
- PATCH alerte : propriétaire (`MarketPriceAlert::isOwnedBy`), 403 sinon, 404 si absente ; aucun identifiant d'un autre périmètre n'est reçu.
- Préférence : scopée à l'utilisateur courant (`PreferencesProcessor`). Le seuil PI lu pour une notification est celui du propriétaire du personnage (`Character::getUser()`).
- Aucune donnée de corporation, aucune source ESI nouvelle.

## Tests existants contredits ou modifiés
- `tests/Unit/Service/MarketAlertServiceCharacterizationTest.php::testTriggeredAlertCurrentlyOnlyPublishesARawMercureAlertAndNoNotificationMarketAudit2` : fige le défaut (`persist` jamais appelé). Renommé et inversé (notification attendue, Mercure conservé), retrait de `#[Group('market-audit-2')]` à justifier dans le commit. Assertion d'un test verrouillé : niveau 3 à l'étape de technicité, avec accord de cette spec.
- `tests/Unit/Service/MarketAlertServiceTest.php` et `MarketAlertServiceCharacterizationTest.php` : le constructeur reçoit le `NotificationDispatcher`.
- `tests/Unit/Service/Sync/PlanetarySyncServiceTest.php` : le seuil devient celui de la préférence (2 h → défaut 24 h) et la déduplication s'ajoute ; les cas « expire bientôt » avec type absent du SDE restent.
- Tests des providers PI (stats, mapper) : le seuil `isExpiringSoon(24)` devient le seuil utilisateur.

## Hors périmètre
- Catégories `escalation` et `esi` : décision de Sylvain, aucun retrait de filtre ni de préférence, aucun producteur dans #537. Issues séparées à ouvrir : escalation expirant (`Escalation::expiresAt`, seuil 60 min déjà prévu), notifications ESI in-game (roadmap).
- Déduplication de l'événement Mercure `planetary-expiry` (toujours publié à chaque sync).
- Modifier la source de prix ou l'item d'une alerte ; historique des déclenchements ; alertes récurrentes.
- Navigation, sidebar et routes (thread #531).

## Termes du glossaire
- **Notification d'alerte prix** (Price alert notification) : notification `price` envoyée une fois au déclenchement d'une `MarketPriceAlert`, qui ouvre le détail de l'item au marché (spec #537).
- **Réarmement** (Re-arm) : retour d'une alerte `triggered` à `active` quand l'utilisateur l'enregistre (seuil modifié ou non) (spec #537).
- **Seuil d'expiration PI** (PI expiry threshold) : préférence `planetary.thresholdMinutes`, défaut 1440 min, qui fixe quand un extracteur est « bientôt expiré » dans les KPI, le bandeau et les notifications (spec #537).

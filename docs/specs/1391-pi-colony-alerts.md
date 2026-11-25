# Spec #1391 — Alertes PI : launchpad d'usine vide, stockage plein, donnée de colonie trop vieille

Mode nuit : validée en autonomie. Empilée sur #1382 (PR #1413, `PlanetaryInputNeedsCalculator`). Aucune migration, aucune sync nouvelle, aucun appel ESI nouveau : les alertes sont évaluées juste après la sync par personnage déjà planifiée (30 min, `SyncCharacterPlanetaryColoniesHandler`).

## But

Le joueur ne voit un launchpad d'usine vide, un stockage plein ou une colonie qu'il n'ouvre plus en jeu qu'en ouvrant l'application. Trois alertes (dans l'application et push) le préviennent, avec la même fenêtre X que l'alerte d'extracteur.

## Règles

1. **Service** `App\Service\Planetary\PlanetaryAlertEvaluator::evaluate(Character $character): void`, appelé par `SyncCharacterPlanetaryColoniesHandler` après `syncCharacterColonies()`. Il lit les colonies du personnage (`PlanetaryColonyRepository::findByCharacter`), l'utilisateur du personnage et la fenêtre `X = PlanetaryExpiryThresholdResolver::minutesFor($user)` (réglage « planetary » existant de Réglages · Notifications, défaut 1 440 min). Horloge `ClockInterface`.
2. **Launchpad d'usine vide** : pour chaque launchpad de `PlanetaryInputNeedsCalculator::calculate($colonies)['launchpads']` avec au moins un `feed` dont `dailyRate > 0` : `emptyAt` du launchpad = le plus proche `emptyAt` non null de ses feeds. Alerte quand `emptyAt <= maintenant + X min` (launchpad déjà vide compris). Niveau `critical` si `emptyAt <= maintenant`, sinon `warning`. Sujet `launchpad:{colonyId}:{pinId}`, genre `launchpad_empty`.
3. **Stockage plein** : pour chaque pin dont le groupe SDE est « Storage Facilities » (constante nommée `STORAGE_FACILITIES_GROUP_ID = 1029`, vérifiée dans `sde_inv_groups`), `rempli = Σ amount × volume SDE` des `contents` ; alerte quand `rempli >= capacité SDE × 0,95` (constante nommée `STORAGE_FULL_RATIO`), niveau `warning`, sujet `storage:{colonyId}:{pinId}`, genre `storage_full`. Un `contents` null vaut vide ; un volume ou une capacité SDE inconnue : aucune alerte pour ce pin (jamais 0). Les launchpads ne comptent pas (un launchpad plein est le but d'un remplissage).
4. **Donnée trop vieille** : pour chaque colonie, alerte quand `dataAgeSeconds > X × 60` (âge depuis `PlanetaryColony::getLastUpdate()`, #1382), niveau `info`, sujet `stale:{colonyId}`, genre `data_stale`.
5. **Une alerte par épisode** : l'épisode d'un sujet se termine quand la colonie est revue en jeu (`lastUpdate` change). Une alerte du même genre et du même sujet n'est pas renvoyée si une notification de catégorie `planetary` de l'utilisateur, de même `data.kind` et `data.subject`, a été créée à l'instant `lastUpdate` de la colonie ou après. Nouvelle méthode `NotificationRepository::findByUserAndCategorySince(User, string, \DateTimeImmutable): array`. Aucun état en base.
6. **Envoi** : `NotificationDispatcher::dispatch($user, Notification::CATEGORY_PLANETARY, $level, $title, $message, $data, '/planetary')` (Mercure et push déjà gérés par le dispatcher, préférences de catégorie respectées). `data` = `kind`, `subject`, `colonyId`, `planetName`, `characterName`, plus `emptyAt` ISO pour un launchpad. Titres : « Launchpad running empty » / « Storage almost full » / « Colony data is stale » ; messages en anglais avec planète, personnage et la fenêtre ou le pourcentage.
7. **Réglages** : aucun réglage ni catégorie nouveau ; la fenêtre X et l'activation sont celles de la catégorie « planetary » de Réglages · Notifications. Sur l'onglet Colonies, un lien « Réglages des alertes » (FR : « Réglages des alertes », EN : « Alert settings ») ouvre `{ name: 'settings', params: { section: 'notifications' } }`.
8. **Inconnu reste inconnu** : un launchpad sans `emptyAt` calculable (débit 0) ne déclenche rien ; pas de `?? 0`.

## Exemples chiffrés (maintenant = 2026-11-26 12:00, X = 1 440)

| Cas | Entrée | Sortie |
|---|---|---|
| Vide bientôt | launchpad vide à 2026-11-27 06:00 | alerte `warning` |
| Déjà vide | `emptyAt` 2026-11-26 08:30 | alerte `critical` |
| Loin | vide à 2026-11-28 12:00 | aucune alerte |
| Débit 0 | feeds à `dailyRate` 0 | aucune alerte |
| Storage à 11 400 m3 sur 12 000 | 95 % pile | alerte `warning` |
| Storage à 11 399 m3 | 94,99 % | aucune alerte |
| Storage sans volume SDE connu | volume null | aucune alerte |
| Launchpad plein | 10 000 / 10 000 | aucune alerte |
| Colonie vue il y a 25 h | X = 1 440 | alerte `info` |
| Colonie vue il y a 23 h | X = 1 440 | aucune alerte |
| Même épisode | alerte créée après `lastUpdate` | pas de seconde alerte |
| Nouvel épisode | colonie revue après la notification | nouvelle alerte |
| Autre sujet | même genre, autre pin | alerte séparée |

## Hors périmètre

- Seuils distincts par alerte, catégories de notification nouvelles, webhook Discord et calendrier (#1392).
- Projection du remplissage d'un stockage, launchpads de colonies sans usine, alerte sur une collecte d'extracteur.
- Aucune tâche planifiée nouvelle, aucune migration.

## Décisions prises en autonomie

- Une seule fenêtre X (celle de l'extracteur) pour les trois alertes : le texte de l'issue emploie le même X, et un réglage de plus aurait demandé une catégorie de notification et une migration de préférences.
- « Stockage plein » = storage facility à 95 % ou plus à la dernière vue (pas de projection) ; à confirmer par Sylvain.
- Pas d'état de dédoublonnage en base : l'épisode se déduit de `lastUpdate` et des notifications existantes (purgées à 30 jours, sans effet sur un épisode).

## Tests

- Unit `PlanetaryAlertEvaluatorTest` : une alerte par règle 2 à 5 et les lignes du tableau (dont les gardes launchpad plein et débit 0), l'envoi (catégorie, niveau, route, `data.kind`, `data.subject`).
- Unit `NotificationRepository` : la méthode de lecture sur base d'intégration si le dépôt le permet, sinon un seul test Integration.
- Handler : appelle l'évaluateur après la sync.
- Front : le lien des réglages sur l'onglet Colonies.

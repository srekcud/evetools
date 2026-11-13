# Spec #848 : suivi de la build SDE importée et alerte d'une build CCP plus récente

Issue #848 (circuit:m, group:admin-page). Dépend de #847 (PR 850) : `SdeImportService` compare déjà la build de `latest.jsonl` à `var/sde/_sde.jsonl`. Niveau de technicité 3 (migration, page admin). Mode nuit : spec validée en autonomie.

## Vocabulaire

**Build SDE** (EN : SDE build) : numéro `buildNumber` et date `releaseDate` d'un export statique CCP. Trois valeurs distinctes : build importée (celle de la base), dernière build CCP (`latest.jsonl`), dernière build notifiée. À ajouter au glossaire (`docs/glossary.md`) avec ce nom.

## Règles

1. **Fetcher de la dernière build.** `App\Service\Sde\SdeLatestBuildFetcher::fetch(): SdeBuild` (HttpClient injecté) lit `https://developers.eveonline.com/static-data/tranquility/latest.jsonl` (une ligne `{"_key":"sde","buildNumber":int,"releaseDate":"ISO-8601"}`). Erreur transport, HTTP non 2xx, JSON invalide, `buildNumber` absent ou non entier, `releaseDate` absente ou illisible : `\RuntimeException` dont le message contient `latest`. Jamais de valeur par défaut. `SdeBuild` : value object `final readonly` (`int $buildNumber`, `\DateTimeImmutable $releaseDate`) avec `fromJsonLine(string): ?self`, réutilisé pour lire `_sde.jsonl`.
2. **Refactor de #847.** `SdeImportService` reçoit `SdeLatestBuildFetcher` à la place de sa lecture interne de `latest.jsonl` (comportement identique : mêmes messages d'erreur, mêmes sorties). Les tests de #847 (`SdeImportServiceBuildRefreshTest`) ne changent que dans leur helper de construction, aucune assertion.
3. **État persisté.** Entité `SdeBuildState` (table `sde_build_state`, une seule ligne, créée à la demande par le dépôt `SdeBuildStateRepository::current()` ou `getOrCreate()`) : `importedBuildNumber` (int nullable), `importedReleaseDate` (datetime immutable nullable), `importedAt` (datetime immutable nullable), `latestBuildNumber` (int nullable), `latestReleaseDate` (nullable), `latestCheckedAt` (nullable), `notifiedBuildNumber` (int nullable). Migration `Version20261113150000` (re-vérifier l'unicité de l'horodatage au rebase) : `CREATE TABLE`, aucune donnée à migrer, `down()` supprime la table.
4. **Enregistrement à l'import.** À la fin d'un import **complet** (`$onlySections === null`, après les importers, avant le nettoyage), `SdeImportService` enregistre `importedBuildNumber`, `importedReleaseDate` (lus dans le `_sde.jsonl` extrait, donc la build réellement importée) et `importedAt` (horloge injectée `ClockInterface`). Un import partiel (`--only`) n'enregistre rien : la base n'est pas entièrement à cette build. Un import qui échoue n'enregistre rien.
5. **Message planifié.** `App\Message\CheckSdeBuild` (`final readonly`, sans champ) et `CheckSdeBuildHandler` (`#[AsMessageHandler]`) : `SyncTracker::start('sde-build')`, vérification (règles 6 et 7), `complete('sde-build', <résumé>)` ; en cas d'exception : `fail('sde-build', message)` puis relance. Une lecture impossible de la dernière build laisse `latestBuildNumber` et `latestReleaseDate` inchangés (ancienne valeur conservée, l'échec est visible dans la santé de la tâche), n'envoie aucune notification.
6. **Statut.** `SdeBuildStatus` (enum string) : `unknown` (aucune vérification faite : `latestBuildNumber` nul), `not_recorded` (dernière build connue, build importée nulle), `up_to_date` (importée ≥ dernière), `outdated` (importée < dernière). Calcul unique dans un service `SdeBuildStatusResolver::resolve(SdeBuildState): SdeBuildStatus`, utilisé par le handler et par la ressource admin.
7. **Alerte admins.** Après une vérification réussie, si le statut est `outdated` ou `not_recorded` et que `notifiedBuildNumber !== latestBuildNumber` : une notification par admin (`NotificationDispatcher::dispatch`, catégorie `Notification::CATEGORY_SDE_BUILD = 'sde_build'`, niveau `warning`, titre et message en anglais comme les autres notifications serveur, `data` = `importedBuildNumber`, `latestBuildNumber`, `latestReleaseDate`, `route` = `/admin`), puis `notifiedBuildNumber = latestBuildNumber`. Une même build CCP n'est donc notifiée qu'une fois. Statut `up_to_date` : aucune notification, `notifiedBuildNumber` inchangé. Les admins sont les utilisateurs dont le personnage principal figure dans `AdminChecker::listedEveCharacterIds()` (requête dédiée du `UserRepository`, jamais un `isAdmin` par utilisateur sur toute la table). La catégorie `sde_build` n'est PAS ajoutée à `UserNotificationPreferenceRepository::CATEGORIES` (pas de ligne de préférence ni de réglage visible des joueurs) ; sans préférence, le dispatcher notifie.
8. **Mercure.** À la fin du handler (succès ou échec de lecture), `MercurePublisherService::publishAdminHealth(AdminHealthScope::SdeBuild)` (nouveau cas `'sde-build'` de l'enum, topic `/admin/health` déjà autorisé aux seuls admins). Après un import réussi, la CLI ne publie rien (processus sans Mercure garanti) : la page se rafraîchit au chargement.
9. **Planification.** `SyncScheduler` : `self::everyViaAsync('24 hours', new CheckSdeBuild())` avec son commentaire de voisinage ; `TriggerableSyncs` : `'sde-build' => [CheckSdeBuild::class]` ; `SyncTracker::EXPECTED_INTERVALS` : `'sde-build' => 86400` ; libellés `admin.…` en.json et fr.json (clé `sde-build`, « SDE build check » / « Vérification de la build SDE »). Ligne ajoutée au tableau Scheduler de `CLAUDE.md` : `| Build SDE (dernière build CCP comparée à la build importée ; alerte admins, aucun import automatique) | 24h |`. Les tests existants de cohérence scheduler / TriggerableSyncs / SyncTracker doivent rester verts.
10. **Ressource admin.** `GET /api/admin/stats` (`AdminStatsResource`) gagne un bloc `sdeBuild` : `status` (valeurs de la règle 6), `importedBuildNumber`, `importedReleaseDate`, `importedAt`, `latestBuildNumber`, `latestReleaseDate`, `latestCheckedAt` (nullables, ISO-8601), rempli par `AdminStatsProvider` (méthode `fillSdeBuild`) depuis `SdeBuildState` ; aucune lecture réseau dans le provider. Accès inchangé (admin seulement, `AdminChecker`).
11. **Page admin.** Nouvelle carte `frontend/src/components/admin/SdeBuildCard.vue` (props : le bloc `sdeBuild` ; style des cartes admin existantes, HUD EVE) : badge de statut (`up_to_date` vert, `outdated` ambre, `not_recorded` ambre, `unknown` gris), build importée + date de sortie, dernière build CCP + date de sortie, heure de la dernière vérification relative (`formatTimeSince`), et pour `outdated` / `not_recorded` la commande à lancer (`make sde-import`) en texte. Valeur nulle = « -- », jamais 0. Insérée dans `Admin.vue` à côté de `EsiErrorBudgetCard` (`v-if="stats?.sdeBuild"`). `Admin.vue` observe `syncStore.adminHealthSignals['sde-build']` et recharge les stats comme pour `failed-messages`. Types dans `stores/admin.ts`, clés i18n `admin.sdeBuild.*` en.json et fr.json.
12. **Front notifications.** `NotificationCategory` (`stores/notifications.ts`) gagne `'sde_build'` ; `notificationConstants.ts` : icône et couleur ; libellé i18n de la catégorie en.json et fr.json.

## Exemples

| Importée | Dernière CCP | Notifiée | Statut | Notification |
|---|---|---|---|---|
| 3213160 | 3693310 | null | outdated | oui, notifiée = 3693310 |
| 3213160 | 3693310 | 3693310 | outdated | non |
| 3213160 | 3700000 | 3693310 | outdated | oui, notifiée = 3700000 |
| null | 3693310 | null | not_recorded | oui |
| 3693310 | 3693310 | null | up_to_date | non |
| 3693310 | null (jamais vérifié) | null | unknown | non |

## Cas limites

- Lecture de `latest.jsonl` en échec : ancienne dernière build conservée, tâche en échec, aucune notification, signal Mercure publié.
- Aucun admin configuré (`ADMIN_CHARACTER_IDS` vide) : aucune notification, `notifiedBuildNumber` mis à jour quand même (pas de rattrapage futur d'une build déjà annoncée à personne ; décision de nuit, voir journal).
- Table vide au premier passage : l'état est créé à la demande.
- Import complet qui échoue en cours de route : état inchangé.

## Hors périmètre

Import automatique, retéléchargement depuis la page admin, bouton de déclenchement de l'import, rapport de diff entre builds, règle de bannière globale (`AdminStatusEvaluator` inchangé), `SdeMapImporter` (#849).

## Décisions ouvertes (tranchées de nuit, à confirmer)

- Import partiel : pas d'enregistrement de build.
- Statut `not_recorded` notifie aussi (la prod actuelle n'a aucune build enregistrée).
- Catégorie de notification propre, absente des préférences joueurs.
- La bannière d'état global de la page admin ne change pas de niveau pour une build en retard.

# #798 — Match jobs : isoler les échecs par personnage et par ligne

Issue : #798 (circuit:m, group:jobs-sync). Mode nuit, option 2 de l'issue : correctif défensif seul. La cause SQL sous-jacente reste hors périmètre (logs prod inaccessibles).

## Problème

`POST /api/industry/projects/{projectId}/match-jobs` (`MatchJobsProcessor`) renvoie 500 dans deux cas :
1. La sync des jobs d'un personnage lève une exception SQL pendant un flush : l'EntityManager se ferme, l'exception est journalisée puis avalée, et le flush suivant (`IndustryJobMatcher::matchEsiJobs`) lève sans que rien l'attrape.
2. Une exception de domaine dans le rapprochement : `ProducerMissingFromSdeException` (`IndustryActivityProductRepository::producerOf`), `LogicException` d'une ligne sans runs (`IndustryJobMatcher`), `LogicException` d'un projet sans participant propriétaire (`DerivedContributionService::refreshLine`).

## Règles

1. **Un personnage = une transaction.** `CharacterSyncIsolation::run(\Closure)` exécute la sync d'un personnage dans une transaction de connexion : commit si la closure réussit ; sinon rollback (si la transaction est encore active), `ManagerRegistry::resetManager()`, puis relance l'exception d'origine.
2. **Le processor continue après un échec.** Un personnage dont la sync échoue est annulé, rangé dans `failedCharacters` avec sa raison (`CharacterSyncFailure::fromThrowable`, inchangé), journalisé en warning `Failed to sync jobs for character`. Les autres personnages sont synchronisés, le rapprochement tourne, la réponse est 200.
3. **Entités rechargées.** Après un reset du manager, les entités gérées avant sont détachées : à chaque tour le processor relit le personnage par `CharacterRepository::findByEveCharacterId` (le contrôle du jeton se fait sur l'entité relue), et si au moins un personnage a échoué il relit le projet par `IndustryProjectRepository::find` avant le rapprochement. Sans échec, une seule lecture du projet (comportement actuel).
4. **Ligne non rapprochable = ligne signalée.** `IndustryJobMatcher::matchEsiJobs` renvoie `list<LineMatchFailure>`. Une ligne dont le producteur manque au SDE (`ProducerMissingFromSdeException`) donne la raison `producer_missing_from_sde` ; une ligne de type job sans runs donne `no_runs` (plus de `LogicException`). Les autres lignes sont rapprochées normalement. Chaque échec est journalisé en warning `Failed to match jobs of line` (`projectId`, `typeId`, `reason`).
5. **Contribution non recalculée = ligne signalée.** Dans le processor, une `LogicException` de `DerivedContributionService::refreshLine` pour une ligne donne la raison `contribution_not_refreshed` pour cette ligne ; les autres lignes sont recalculées ; warning `Failed to match jobs of line` journalisé avec `projectId`, `typeId`, `reason` et `error` (message de l'exception). Aucune autre exception n'est attrapée.
6. **Réponse.** `MatchJobsResultResource` gagne `failedLines: list<array{typeId: int, typeName: string, reason: string}>`, vide quand tout passe. `LineMatchFailure::toArray()` produit ce format. Les lignes du matcher précèdent celles du `refreshLine`.
7. **Front.** Le panneau « Jobs to launch » (`JobsTable.vue`) liste, sous les personnages en échec, les lignes en échec (nom du type et raison traduite en en/fr : `producer_missing_from_sde`, `no_runs`, `contribution_not_refreshed`) ; les raisons des personnages passent par les libellés existants de `CharacterSyncFailureReason` quand ils existent. `projectStore.matchJobs` renvoie `{ failedCharacters, failedLines }`.

## Exemples

| Cas | Entrée | Sortie |
|---|---|---|
| Violation d'unicité sur le personnage B | A, B, C ; B lève pendant un flush | 200, `failedCharacters = [B/sync_failed]`, jobs de A et C rapprochés, EM utilisable, rien de B persisté |
| Producteur absent | lignes X (absent du SDE) et Y | `failedLines = [X/producer_missing_from_sde]`, Y rapprochée |
| Ligne sans runs | ligne job, `runs = null` | `failedLines = [.../no_runs]`, plus de `LogicException` |
| refreshLine lève `LogicException` | 2 lignes job, la première lève | seconde recalculée, `failedLines = [première/contribution_not_refreshed]` |
| Aucun échec | tous OK | `failedCharacters = []`, `failedLines = []`, projet lu une fois |

## Cas limites

- Personnage sans jeton : inchangé (`missing_token`, aucune sync, aucune transaction).
- Personnage introuvable au rechargement : échec `sync_failed` de ce personnage (warning `Failed to sync jobs for character`), sans sync ni isolation.
- `OpenProjectsJobMatcher` (sync planifiée) ignore la liste renvoyée ; l'échec est déjà journalisé par le matcher.

## Hors périmètre

- Cause SQL de l'exception en prod (à lire dans les logs : `Uncaught PHP Exception`, `Failed to sync jobs for character`, `Project activity not recorded`) : « Actions Sylvain ».
- `IndustryJobSyncService::syncUserJobs` (tâche planifiée) et `OpenProjectsJobMatcher` : même risque d'EM fermé, non traité ici.
- Échec SQL du flush du rapprochement lui-même (EM fermé après) : reste une 500 journalisée.
- Aucune migration, aucun nouveau endpoint, aucun réglage retiré.

## Tests

Unit : `CharacterSyncIsolationTest`, `MatchJobsProcessorTest` (échecs, rechargement, `failedLines`, `refreshLine`), `IndustryJobMatcherFailedLinesTest`. Integration : `CharacterSyncIsolationTest` sur la vraie base (violation d'unicité réelle, EM rouvert, données du personnage suivant persistées). Front : `JobsTable` (panneau des lignes) et store `matchJobs`.

# #1200 Un membre synchronise ses propres jobs, dernière et prochaine sync par installateur

Circuit M, mode nuit, spec validée en autonomie. Décision D1 de l'issue : dans un groupe, un membre agit sur son propre travail.

## Règles

1. `POST /industry/projects/{projectId}/match-jobs` est ouvert à tout participant accepté (propriétaire inclus, `ProjectAccessChecker::assertAcceptedMember`). Il synchronise les jobs des personnages **de l'appelant seulement**, puis relance le rapprochement du projet (inchangé : tous les participants, jobs contestés, apports dérivés). Un utilisateur sans accès, un participant en attente : 403 « You do not have access to this project », ni sync ni rapprochement.
2. `GET /industry/projects/{projectId}/jobs-sync` (participant accepté, sinon 403) renvoie `intervalSeconds` (fréquence de la tâche planifiée `SyncIndustryJobs`, `ScheduledSyncReader::industryJobsIntervalSeconds()`) et `installers` : un par participant accepté, propriétaire d'abord, puis les membres dans l'ordre d'adhésion.
3. Un installateur porte `name` (nom du main, `PilotName::of`, « Unknown » sans main), `isViewer` et `lastSyncedAt`. `lastSyncedAt` est la plus ancienne, parmi les personnages de l'installateur qui ont des jobs en cache, de la date de cache la plus récente de chacun (même règle que `jobsSyncedAt` du Slot Tracker) ; `null` quand aucun personnage n'a de job en cache (inconnu, jamais 0 ni « maintenant »).
4. Front, onglet Production : l'unique libellé « Jobs synced X ago » (sync du lecteur) est remplacé par une ligne par installateur : nom, « Jobs synced X ago », « next around HH:MM » (dernière sync + `intervalSeconds`, seulement si c'est dans le futur) ; « Jobs sync unknown » pour un installateur sans date ; « Jobs sync unknown » seul quand la liste est vide ou illisible.
5. Le bouton « Match jobs » est montré à tout participant accepté (`canMatchJobs` propriétaire ou `canLinkJobs`) ; au clic, la liste des installateurs est relue. Le retrait d'un lien reste réservé au propriétaire (`canMatchJobs`).
6. Glossaire, entrée « Job lié » : le rapprochement se relance à la sync des jobs d'un participant accepté ou au bouton de tout participant accepté pour ses propres personnages (#1200).

## Exemples

| Cas | Résultat |
|---|---|
| Membre accepté, 2 personnages avec token, POST match-jobs | 200, 2 personnages synchronisés (les siens), rapprochement du projet |
| Participant en attente, POST match-jobs | 403, aucun personnage synchronisé |
| Propriétaire (main A, jobs cachés à 10:00 et 10:20) + membre (aucun job caché), intervalle 1 800 s | installers = [A lastSyncedAt 10:00, membre null], intervalSeconds 1800 |
| Un personnage caché à 10:00, un autre à 10:20 | lastSyncedAt 10:00 |

## Hors périmètre

Pas de bouton pour synchroniser les jobs d'un autre participant ; pas de nouveau topic Mercure (le bouton reste synchrone, comme aujourd'hui) ; la sync planifiée et le Slot Tracker sont inchangés.

## Décisions

- Données de sync servies par une ressource dédiée (`ProjectJobsSyncResource`) plutôt que greffées sur `ProjectActivity` : lecture à la demande, indépendante du fil d'activité.
- Les installateurs sont les participants acceptés, pas les personnages : le propriétaire voit tous les participants, un membre voit les mêmes noms (nom du main, déjà visible dans la liste des membres).

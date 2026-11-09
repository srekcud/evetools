# #321 : fil d'activité en direct et jobs en cours d'un projet

Maquette : `industry-project-v3.html` onglet Journal (la même carte que l'étape 16 de `group-industry-v1.html`). Mode nuit : spec validée en autonomie, niveau 3 (code Industry, migration).

## Contexte
Le détail d'un projet se recharge sur les événements Mercure `/group-project/{id}/events` (#148) mais n'en garde aucune trace, et un job lancé n'émet rien. L'onglet Journal du cockpit (#336) affiche « bientôt » (`COMING_SOON_TABS`).

## Règles
1. **Trace persistée.** Table `industry_project_activities` (entité `IndustryProjectActivity` : id UUID, projet en `onDelete CASCADE`, `kind` string, `occurredAt`, `data` json). Index (projet, occurredAt).
2. **Un seul point d'enregistrement.** `MercurePublisherService::publishGroupProjectEvent` envoie d'abord un événement Symfony `GroupProjectEventPublished` (projet, action, données, horodatage) par un `?EventDispatcherInterface $eventDispatcher = null` ajouté en dernier argument du constructeur (défaut `null` : une dizaine de tests construisent le service à la main avec `hub` et `logger` seuls, ils ne changent pas), puis publie sur Mercure comme aujourd'hui. L'écouteur `ProjectActivityRecorder` (`#[AsEventListener]`) enregistre l'événement si `ProjectActivityKind::tryFrom(action)` existe, sinon l'ignore. Un échec d'enregistrement (projet introuvable, base) est journalisé en `warning` et n'empêche ni l'action de l'utilisateur ni la publication Mercure.
3. **Genres enregistrés** (`ProjectActivityKind`) : `member_joined`, `member_accepted`, `member_left`, `contribution_submitted`, `contribution_approved`, `contribution_rejected`, `sale_recorded`, `sale_updated`, `sale_deleted`, `status_changed`, `fees_updated`, `jobs_detected`, `jobs_finished`. Non enregistrés (bruit) : `line_reserved`, `contribution_synced`, `transfer_received`.
4. **Événements à ajouter.** `member_accepted` (`UpdateParticipantProcessor`, quand un membre `pending` passe `accepted` : `memberId`, `characterName` via `PilotName::of`). `fees_updated` (`UpdateProjectProcessor`, seulement si `brokerFeePercent` ou `salesTaxPercent` change de valeur : nouvelles valeurs et `previousBrokerFeePercent`, `previousSalesTaxPercent`). `contribution_approved` et `contribution_rejected` ajoutent `characterName` (contributeur, `PilotName::of`), `bomItemName` et `quantity` à leurs données.
5. **Démarrage du job lu dans l'ESI.** `IndustryProjectJobMatch` gagne `startDate` (nullable, colonne `start_date`), copiée par `copyJobState` depuis `CachedIndustryJob::getStartDate`. Un lien existant sans `startDate` reste inconnu (`null`) : jamais l'heure de détection à la place.
6. **Jobs détectés et terminés.** `ProjectJobActivityRecorder::recordJobsOf(IndustryProject)` est appelé juste après `matchEsiJobs` par `OpenProjectsJobMatcher::matchOpenProjectsOf` et par `MatchJobsProcessor` (rafraîchissement manuel). Il lit tous les liens de job du projet, écarte les `esiJobId` déjà présents dans `data.jobIds` d'un événement `jobs_detected` (respectivement `jobs_finished`) du projet, regroupe les restants par (installateur, ligne de BOM) et publie via `publishGroupProjectEvent` (l'écouteur de la règle 2 persiste ; rien d'autre n'écrit la table pour ces genres).
   - `jobs_detected` : tout job nouveau (quel que soit son statut) ; `jobs_finished` : tout job de statut `delivered` ou `ready` pas encore terminé dans la trace. Un job vu pour la première fois déjà livré produit les deux. Statuts `cancelled` et `reverted` : aucun événement de fin.
   - Données `jobs_detected` : `characterName` (`PilotName::of` de l'utilisateur de `ProjectJobInstaller::userOf`, pas le nom brut du personnage), `typeId`, `typeName`, `jobCount`, `runs` (somme), `startedAt` (plus ancienne `startDate`, ATOM, `null` si une est inconnue), `endsAt` (plus tardive `endDate`), `cost` (somme ; `null` si un seul coût est inconnu, jamais 0), `jobIds`. Données `jobs_finished` : `characterName`, `typeId`, `typeName`, `jobCount`, `runs`, `endedAt` (plus tardive `endDate`), `jobIds`.
   - `occurredAt` = heure d'enregistrement ; le fil affiche `startedAt` / `endedAt` pour l'heure de jeu.
7. **Lecture.** `GET /industry/projects/{projectId}/activity` (ressource `ProjectActivityResource`, `uriVariables` avec `Link`, provider `ProjectActivityProvider`, `ProjectAccessChecker::assertAcceptedMember`, comme les autres onglets). Réponse : `events` (au plus `ACTIVITY_PAGE_SIZE = 100`, du plus récent au plus ancien : `id`, `kind`, `occurredAt`, `data`) et `runningJobs`.
8. **En cours maintenant.** `runningJobs` est calculé à la lecture (rien de persisté) à partir des liens de job du projet de statut `active` uniquement (`paused`, `ready`, `delivered`, annulés : non), regroupés par (installateur, ligne de BOM) : `characterName` (`JobInstallerName::seenBy` pour le lecteur), `typeId`, `typeName`, `jobCount`, `runs`, `startedAt` (plus ancienne, `null` si inconnue), `endsAt` (plus tardive), `cost` (somme, `null` si un coût est inconnu). Triés par `endsAt` croissant.
9. **Front.** L'onglet `journal` quitte `COMING_SOON_TABS` et rejoint `DETAIL_TABS`. Composant `ProjectJournal.vue` : bloc « En cours maintenant (ESI) » (un encart par groupe : pilote, produit, `n jobs × r runs`, démarrage, coût réel ou « inconnu », fin, compte à rebours `HH:MM:SS` qui défile chaque seconde et passe à « terminé » à 0) et fil daté avec pastille « en direct » (cette pastille n'est affichée que si la connexion Mercure est ouverte). Store `stores/group-industry/activity.ts`. Tout événement Mercure du projet recharge le fil (`GroupProjectRefresh` `activity`, ajouté aux listes de `groupProjectRefreshes`, y compris le défaut) ; `fees_updated` recharge aussi `projectDetail`, `distribution`, `cockpit` ; `member_accepted` recharge `members`, `projectDetail`. Direction HUD EVE, noms de pilotes longs tronqués (`truncate` + `title`). i18n en et fr.

## Exemples (tests rouges)
| Cas | Entrée | Sortie |
|---|---|---|
| Écouteur | `publishGroupProjectEvent(p, 'sale_recorded', {…})` | une ligne `sale_recorded` du projet, `data` identique |
| Ignoré | `'line_reserved'`, `'contribution_synced'`, `'transfer_received'` | aucune ligne |
| Mercure inchangé | l'enregistrement lève | la publication Mercure a lieu, aucune exception |
| Job détecté | 2 jobs actifs, même pilote, même ligne, 10 runs chacun, coûts 1 000 000 et 2 000 000 | 1 `jobs_detected` : `jobCount` 2, `runs` 20, `cost` 3 000 000 |
| Coût inconnu | idem, un coût `null` | `cost` `null` |
| Doublon | seconde sync, mêmes jobs | aucun événement |
| Fin | un des jobs passe `delivered` | 1 `jobs_finished` `jobCount` 1 ; pas de second `jobs_detected` |
| Vu déjà livré | job inconnu de la trace, `delivered` | `jobs_detected` puis `jobs_finished` |
| Annulé | job `cancelled` jamais vu | `jobs_detected`, pas de `jobs_finished` |
| Début inconnu | `startDate` `null` | `startedAt` `null` |
| Nom du pilote | installateur = alt d'un autre participant | `characterName` = nom du pilote (`PilotName`), pas le nom du personnage |
| En cours | 3 liens : 2 `active`, 1 `delivered` | 1 groupe `runningJobs`, `jobCount` 2 |
| Frais inchangés | `PATCH` avec le même `brokerFeePercent` | pas de `fees_updated` ; changé : 1 événement avec les valeurs précédentes |
| Acceptation | `PATCH` membre `pending` → `accepted` | `member_accepted` publié |
| Accès | non-membre ou membre `pending` | refusé comme `/surplus` ; membre accepté : 200 |
| Plafond | 120 lignes | 100 retournées, les plus récentes |
| Front | compte à rebours à `endsAt - 3 725 s` | `01:02:05` ; à 0 : « terminé » |

## Cas limites
- Projet supprimé : les lignes partent en cascade. Projet `completed` : le fil reste lisible, `runningJobs` vide par la règle 8 seulement si plus aucun lien n'est `active`.
- `matchEsiJobs` efface et recrée les liens automatiques à chaque passage : la déduplication s'appuie sur `esiJobId` dans la trace, jamais sur l'identifiant du lien.
- Un job lié à la main (`manual`) est traité comme les autres.
- Anciens projets : fil vide au départ, `startDate` rempli à la sync suivante ; aucun rattrapage de l'historique.

## Accès et fraîcheur
Lecture : propriétaire et participants acceptés (même contrôle que les autres onglets). Les données de la trace sont celles déjà envoyées sur le topic Mercure privé du projet ; les noms de pilotes passent par `PilotName`, jamais par un nom de personnage brut hors `JobInstallerName::seenBy`.

## Tests existants modifiés
- Aucun constructeur de `MercurePublisherService` à changer (argument optionnel, règle 2).
- `OpenProjectsJobMatcher` et `MatchJobsProcessor` reçoivent `ProjectJobActivityRecorder` : constructeurs des tests existants à adapter en phase rouge (grep `new OpenProjectsJobMatcher(`, `new MatchJobsProcessor(`), assertions inchangées. Nombre de lignes de `ReviewContributionProcessorTest` : données enrichies, assertions sur le payload à vérifier.
- Test front des onglets « bientôt » : `journal` n'y figure plus (assertion modifiée, volontaire).

## Hors périmètre
`container_verified` (aucun émetteur : la vérification est une lecture), onglet Invention, rattrapage de l'historique, pagination du fil, filtres, purge périodique, notifications push.

## Glossaire
Ajouter **Fil d'activité** (Activity feed) : trace datée des événements d'un projet ; **Jobs en cours** (Running jobs) : jobs ESI actifs liés au projet, regroupés par pilote et ligne.

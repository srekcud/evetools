# #422 — Alerter le propriétaire quand le contrat de courrier d'un projet échoue

Maquette : `industry-project-v3.html#p=35`. Circuit:m, mode nuit. Dépend de #351 (lien et état du contrat). Tranche complète de l'issue : une alerte in-app (et push Web si activé) par contrat échoué.

## Règles

1. **Déclencheur** : lecture planifiée toutes les 30 minutes (`CheckCourierContracts`, clé `SyncTracker` `courier-contracts`, intervalle 1800). Un déclencheur à la lecture de `GET /courier` n'est pas retenu : il ne préviendrait que le propriétaire qui ouvre l'onglet, ce que l'issue veut éviter.
2. **Contrats lus** : liens `IndustryProjectCourierContract` dont `failureNotifiedAt` est `null` et dont le projet n'est pas `completed`. Les contrats des personnages du propriétaire sont lus par `CourierContractReader::read` : un seul appel par propriétaire et par exécution, avec la date de création la plus ancienne de ses projets en `issuedAfter`.
3. **Échec** : l'état du contrat lié (`CourierContractState::fromEsiStatus`) vaut `Failed` (`failed`, `rejected`, `cancelled`, `deleted`, `reversed`). Tout autre état, un contrat absent de l'ESI, un personnage sans jeton ou une erreur ESI n'alertent pas : `Unknown` n'est jamais un échec, et le lien reste surveillé à l'exécution suivante.
4. **Une alerte par contrat** : à l'alerte, le lien reçoit `failureNotifiedAt` (maintenant) et le lien n'est plus lu. Remplacer le lien par un autre contrat (`POST /courier-contract`) remet `failureNotifiedAt` à `null` ; délier supprime le lien.
5. **Notification** (via `NotificationDispatcher`, qui applique la préférence de catégorie, persiste, publie sur le topic Mercure `/user/{userId}/notifications` déjà déclaré et envoie le push Web) : destinataire le propriétaire du projet, catégorie `industry`, niveau `warning`, titre `Courier contract failed: <nom du projet>`, message qui nomme le projet et le contrat, données `{projectId, contractId}`, route `/industry/projects/<projectId>`. Projet sans nom : `Untitled project`.
6. **Persistance** : colonne nullable `failure_notified_at` (migration, sans effet sur les lignes existantes : elles restent `null`, donc surveillées).
7. **Suivi** : le handler démarre, complète (`<n> alert(s) sent`) ou fait échouer (`SyncTracker::fail`, relance de l'exception) la clé `courier-contracts`. Pas de topic Mercure dédié : synchronisation globale, suivie via `admin-sync`.

## Exemples (tests rouges)

| Situation à l'exécution | Alerte | `failureNotifiedAt` |
|---|---|---|
| contrat lié `failed` | 1 | renseigné |
| `rejected`, `cancelled`, `deleted`, `reversed` | 1 chacun | renseigné |
| `outstanding`, `in_progress`, `finished` | 0 | `null` |
| contrat absent de la réponse ESI | 0 | `null` |
| personnage sans jeton | 0 | `null` |
| contrat `failed` déjà notifié, exécution suivante | 0 de plus | inchangé |
| deux projets du même propriétaire, deux contrats `failed` | 2, un seul appel ESI | renseignés |

## Cas limites

- Projet `completed` : lien ignoré (repository).
- Notification désactivée par le propriétaire pour la catégorie `industry` : le dispatcher n'envoie rien, le lien est quand même marqué notifié (le choix de l'utilisateur vaut pour cet échec).
- Un contrat déjà `failed` au moment du lien alerte à la prochaine exécution.

## Accès et fraîcheur

Aucune route ni ressource API nouvelle. Seul le propriétaire est notifié, à partir des contrats de ses propres personnages. Fraîcheur : 30 minutes au plus, plus le cache ESI des contrats.

## Tests existants modifiés

- `SyncSchedulerIntervalsTest`, `SyncSchedulerMissedRunsTest`, `SyncTrackerTest` : un message planifié de plus (liste exhaustive des clés et des messages), valeurs nouvelles exactes.

## Tests (cible 14)

Unit : service de notification 7 (échec, quatre statuts d'échec, états sans alerte, contrat absent ou sans jeton, une seule alerte, un appel ESI par propriétaire), entité 1 (`link` remet à zéro), handler 2. Integration : repository 1. Hors périmètre de test : message, schedule et tracker (listes existantes mises à jour).

## Hors périmètre

- Récompense du courrier dans le coût (fait par #421), alerte des autres états (en route, livré), notification ESI in-game.
- Déclencheur à la lecture de `GET /courier`.
- Détail du statut ESI brut dans le message (l'état `Failed` regroupe les cinq statuts).

## Décisions prises en autonomie

- Lecture planifiée seule : suffisante pour l'alerte sans ouvrir l'onglet, sans doublon de logique à la lecture.
- Les liens dont le contrat est `finished` restent lus jusqu'à la fin du projet (un seul appel ESI par propriétaire, contrats en cache ETag) : pas de nouvel état de lien à persister.
- Aucune migration de données : colonne nullable.

## Termes du glossaire

Aucun ajouté.

## Actions de déploiement

Migration `failure_notified_at` à appliquer (le déploiement lance les migrations ; à rappeler).

# #1194 — A member links their own unmatched jobs, the owner lists every participant's jobs

Décision produit D1 (issue) : dans un groupe, un membre agit sur son propre travail.

## Règles

1. `GET /industry/projects/{projectId}/available-jobs` : tout participant accepté (propriétaire compris) y accède ; un non-participant ou un participant en attente reçoit 403 « You do not have access to this project ».
2. Pour le propriétaire, la liste contient les jobs des personnages du propriétaire et de chaque participant accepté (même définition que le matcher : propriétaire + participants `accepted`). Un participant en attente n'y figure pas.
3. Pour un participant accepté qui n'est pas propriétaire (admin ou membre), la liste ne contient que les jobs de ses propres personnages.
4. `POST /industry/projects/{projectId}/bom/{lineId}/link-job` : tout participant accepté y accède (403 sinon, même message).
5. Le propriétaire lie le job de n'importe quel participant accepté ; un autre participant ne lie que le job d'un de ses propres personnages. Tout autre job (personnage d'un tiers, d'un participant en attente, d'un autre participant pour un non-propriétaire, ou inexistant) reçoit la même réponse 400 « ESI job N not found ».
6. Les autres contrôles du lien sont inchangés (activité, blueprint du producteur, job sans production, déjà lié, ligne d'un autre projet en 404).
7. Le lien reste `manual` et rafraîchit l'apport dérivé de la ligne : l'installateur du job reçoit l'apport d'installation, donc sa part.
8. Le retrait d'un lien (`DELETE .../job-matches/{id}`) et le bouton de rapprochement automatique restent réservés au propriétaire (hors périmètre, voir Décisions).
9. Front : le bouton « Lier un job » et la modale sont proposés à tout participant accepté (prop `canLinkJobs` de `ProductionChain`, `isAcceptedMember` dans `GroupIndustryDetail`) ; le bouton de retrait et le bouton de rapprochement restent sous `canMatchJobs` (propriétaire).
10. Libellé de `groupIndustry.jobs.linkModalDescription` (fr, en) neutre : le job du participant, ou n'importe quel participant pour le propriétaire.
11. Glossaire : l'entrée « Job lié » dit qui liste et qui lie (propriétaire : tous les participants acceptés ; autre participant accepté : ses propres jobs) ; les autres points de l'entrée restent.

## Exemples

| Utilisateur | Job | Résultat |
|---|---|---|
| propriétaire, liste | jobs de l'admin accepté | présents |
| admin accepté, liste | jobs du propriétaire | absents |
| admin accepté, lien | son job Ishtar | 200, `manual` |
| admin accepté, lien | job du propriétaire | 400 not found |
| propriétaire, lien | job de l'admin accepté | 200, `manual` |
| propriétaire, lien | job d'un tiers | 400 not found |
| tiers, liste ou lien | n'importe lequel | 403 |

## Hors périmètre

Retrait de lien par un membre, bouton « Lier » seulement là où il peut réussir (#1199), sync de ses propres jobs (#1200), copie et invention (#1193).

## Décisions

- Retrait de lien laissé au propriétaire : ouvrir le retrait demande de retrouver l'installateur du lien, et l'issue ne le demande pas.

# #1195 — Next action: no "launch 0 runs", add a waiting action

Circuit m, mode nuit (spec validée en autonomie). Groupe `flow-cockpit`. Liées : #1162, #1196, #1201.

## Problème

`ProjectCockpitCalculator::launchJob` prend la première ligne de job non livrée, même quand tous ses runs sont déjà liés à un job ESI (`runs - matchedRuns = 0`). Le cockpit et l'Atelier affichent « Launch a job : 0 runs left to launch » alors qu'il n'y a qu'à attendre.

## Règles

1. Une ligne de job est **à lancer** si `runs === null` (reste inconnu) ou `runs - matchedRuns > 0`. `launch_job` ne choisit que parmi les lignes à lancer, avec le tri existant (groupe de job, puis nom).
2. Une ligne non livrée et non à lancer est **en cours** (tous ses runs sont liés, pas tous livrés).
3. Quand les matières sont couvertes, que des jobs ne sont pas livrés et qu'aucune ligne n'est à lancer, l'action suivante est `await_jobs` : `blocked = false`, `tab = materials`, `targetStatus = null`, `bomLineId`, `typeId`, `typeName` et `quantity` à `null`, `endsAt` = date de fin du dernier job.
4. `endsAt` = plus grande `endDate` des jobs ESI liés aux lignes en cours (statut `active`, `paused` ou `ready` ; ni livré, ni annulé, ni reverted). Aucune date connue (jobs sans `endDate`, ou runs couverts par une marque manuelle) : `endsAt = null`, jamais une date par défaut.
5. Un membre non gestionnaire voit `await_jobs` comme `launch_job` (aucune restriction de rôle).
6. Les autres actions et leur ordre de priorité sont inchangés : contributions à revoir avant tout, matières à fournir avant les jobs, `start_selling` quand tout est livré.
7. File d'actions (`/industry/actions`) : `await_jobs` est classé après toutes les autres actions (rang 7). Gravité `info` si `endsAt` est connue, `unknown` sinon (comme une quantité inconnue). Pas d'impact en ISK.
8. L'API expose `endsAt` (ISO 8601, nullable) dans `nextAction` du cockpit et dans chaque entrée de `/industry/actions` (`null` pour toutes les autres actions).
9. Front : le cockpit du projet et l'Atelier affichent un texte d'attente avec la date de fin (`formatDateTime`), ou un texte « date de fin inconnue » quand `endsAt` est `null`. Bouton « See the jobs » : même comportement que `launch_job` (onglet chaîne, défilement vers les jobs). Libellés FR et EN.

## Exemples

| Lignes de jobs (runs / liés / livrés) | Fin des jobs liés | Action |
|---|---|---|
| A 14 / 10 / 2 | — | `launch_job` A, quantité 4 |
| A 3 / 3 / 1 | 2026-11-22 08:00 | `await_jobs`, `endsAt` 2026-11-22 08:00 |
| A 3 / 3 / 1, B 2 / 2 / 0 | A 2026-11-22 08:00, B 2026-11-23 10:30 | `await_jobs`, `endsAt` 2026-11-23 10:30 |
| A 3 / 3 / 1 (composant), B 5 / 2 / 0 (final) | — | `launch_job` B, quantité 3 |
| A 3 / 3 / 1 | aucun `endDate` | `await_jobs`, `endsAt` null |
| A sans runs, 0 liés | — | `launch_job` A, quantité inconnue |

## Cas limites

- Job lié livré (`delivered`) : ne compte pas pour `endsAt`.
- Projet `Selling` ou `Published` : inchangé (le chemin `inProgressAction` seul est touché).
- Matières non couvertes : `source_material` reste prioritaire.

## Hors périmètre

- Ordre de la chaîne (#1196), état unique d'affichage d'un job (#1201), règle unique de to-do (#1162).
- Barre de phase et compteurs : inchangés.

## Décisions

- Code `await_jobs`, rang de file 7 (dernier), gravité `unknown` sans date : choix de l'auteur de la spec, à confirmer par Sylvain.
- Aucun champ, constante ou écran existant retiré.

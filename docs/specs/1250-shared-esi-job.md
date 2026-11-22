# #1250 — Share one ESI job between lines of several projects

Circuit m, chaîne B du chantier « Industrie en flux ». Aucune migration : `industry_project_job_matches.runs` et `.cost` existent déjà par match. Aucun nouveau topic Mercure (actions synchrones, comme le lien manuel actuel).

## Règles

1. **Un match porte sa part.** `IndustryProjectJobMatch::runs` est la part du job attribuée à la ligne ; `cost` est le coût du job au prorata : `jobCost × runs / jobRuns` (null si le coût du job est null).
2. **La synchronisation garde la part.** `copyJobState` recopie statut, dates de début et de fin, et recalcule `cost` au prorata de la part ; `runs` n'est plus écrasé par les runs du job. Un match sans part encore posée prend les runs du job (création automatique ou manuelle sans part demandée).
3. **Runs libres d'un job** = runs du job − somme des `runs` de tous ses matches, tous projets confondus.
4. **Lien manuel** (`POST …/bom/{lineId}/link-job`, corps `{esiJobId, runs?}`) :
   - `runs` absent : toutes les runs libres du job ;
   - `runs` présent : entier ≥ 1 et ≤ runs libres, sinon 400 ;
   - runs libres = 0 : 400 `ESI job {id} is already linked to a BOM line` (message actuel conservé) ;
   - le même job déjà lié à la même ligne : 400, même au titre d'un autre match (une ligne ne porte qu'une part d'un job).
5. **Assignation d'un job à attribuer** (`POST …/unassigned-jobs/{esiJobId}/assign`, corps `{lineId, runs?}`) : mêmes règles que 4, via `ManualJobLinker`. Le job sort de la liste des jobs à attribuer dès la première part (comportement actuel de `linkJobToBomLine`) ; le reste se lie depuis la modale « Lier un job » du projet.
6. **La somme des parts ne dépasse jamais les runs du job.** Garantie par `ManualJobLinker` ; le rapprochement automatique reste « job entier » et n'offre pas un job qui porte déjà une part (comportement actuel : un job lié n'est plus candidat).
7. **Retirer un lien** (`DELETE …/job-matches/{id}`) libère sa part ; les autres parts du job restent. Un match d'un job annulé ou sans sortie est libéré indépendamment des autres (comportement actuel de `releaseMatchesWithoutOutput`).
8. **Contribution dérivée inchangée dans son code** : `DerivedContributionService` additionne déjà `runs` et `cost` par match ; avec les parts, un installateur reçoit pour chaque ligne ses runs de part et son coût au prorata. Deux lignes de deux projets partagent donc le coût du job sans le compter deux fois.
9. **Modale « Lier un job »** (`GET …/available-jobs`) : `AvailableJobResource` expose `linkedRuns` (somme des parts de tous projets) et `remainingRuns` (runs − linkedRuns). `linkedToBomLineId`/`Name`/`matchId` gardent leur sens (une ligne du projet qui porte déjà une part de ce job, la plus récente si plusieurs). Le bouton Lier reste proposé tant que `remainingRuns > 0`, même si le job porte déjà une part dans le projet ; le refus d'une seconde part sur la même ligne vient du 400 de la règle 4.
10. **Front** : `JobLinkModal` affiche « {linkedRuns} / {runs} runs déjà attribuées » sur un job partagé, un champ « runs à attribuer » (défaut `remainingRuns`, min 1, max `remainingRuns`) et émet `link(esiJobId, runs)` où `runs` est `undefined` quand la saisie égale `remainingRuns` ; `projectStore.linkJob(projectId, lineId, esiJobId, runs?)` envoie `runs` dans le corps seulement s'il est défini. Libellés FR + EN.

## Exemples chiffrés

| Cas | Job | Parts existantes | Demande | Résultat |
|---|---|---|---|---|
| Deux projets | 20 runs, coût 1 000 000 | aucune | A : 10 puis B : 10 | A : 10 runs, 500 000 ; B : 10 runs, 500 000 |
| Sans `runs` | 20 runs | A : 10 | B : sans `runs` | B : 10 runs (les libres) |
| Dépassement | 20 runs | A : 10 | B : 11 | 400 |
| Plein | 20 runs | A : 12, B : 8 | C : 1 | 400 `already linked` |
| Même ligne | 20 runs | A/ligne L : 5 | A/ligne L : 5 | 400 |
| Coût inconnu | 20 runs, coût null | aucune | A : 5 | `cost` null |
| Sync | job 20 runs, coût passe de 1 000 000 à 1 200 000 | A : 10, B : 10 | sync | A : 10 runs 600 000 ; B : 10 runs 600 000 |
| Retrait | 20 runs | A : 10, B : 10 | retire A | libres : 10, B intacte |

## Cas limites

- `runs` ≤ 0 ou non entier : 400 (validation `Positive`).
- Job aux runs libres 0 mais `runs` demandé : 400 `already linked`.
- Une part de job sans coût (null) ne casse pas `installCostOf` (comportement existant sur null).

## Hors périmètre

- Le rapprochement automatique ne découpe pas un job (pas de répartition automatique entre plusieurs lignes).
- Pas de verrou entre deux liens manuels simultanés (même limite que #1302).
- Le panneau des jobs à attribuer n'a pas de champ « runs » : le partage se termine depuis la modale du projet.
- Aucun nouvel écran du prototype : champ dans la modale existante.

## Décisions

- Pas de colonne ni de table : la part est `runs` du match, déjà en base.
- Les parts d'un job sont comptées tous projets confondus ; seul le nombre de runs libres est exposé à un autre projet, pas son identité.
- Message d'erreur « already linked » conservé quand rien n'est libre, pour ne pas casser les tests et le front existants.

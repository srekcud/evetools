# Spec #252 — Un recalcul écrit le même `totalGroupRuns` sur tous les jobs d'un groupe découpé

**Statut : validée par Sylvain le 2026-10-28.**

## Contexte

`IndustryStepCalculator::recalculateStepQuantities()` (appelé par `UpdateStepProcessor`, `LinkJobProcessor`, `IndustryJobMatcher`) délègue la répartition d'un groupe découpé à `SplitGroupQuantities::applyNeedToSteps()`. Sans job aux runs fixés, un job n'est réécrit que si ses runs ou sa quantité changent : un job dont runs et quantité restent identiques garde l'ancien `totalGroupRuns`, alors que les autres jobs reçoivent le nouveau. Avec au moins un job aux runs fixés, la comparaison porte déjà sur `totalGroupRuns` et le défaut n'existe pas.

Conséquences :

- les jobs d'un même groupe portent deux totaux différents ;
- l'onglet Étapes (`StepTree.vue`) prend le `totalGroupRuns` du premier job qui en a un et compare la somme des runs à ce total : selon l'ordre des étapes, le groupe s'affiche en rouge, « 6 / 5 runs (+1) », alors qu'il est juste ;
- le job inchangé n'est pas dans la liste des étapes modifiées : la réponse du PATCH d'une étape ne le renvoie pas, le store frontend garde l'ancienne valeur.

`#112` avait classé ce point hors périmètre (`testUnchangedJobOfARecalculatedSplitGroupCurrentlyKeepsTheOldTotalGroupRunsIssue252`).

## Règles métier

- **R1 — Un seul total par groupe.** Après un recalcul, tous les jobs d'un groupe découpé portent le même `totalGroupRuns`, y compris un job dont runs et quantité ne changent pas.
- **R1 bis — Groupe à un seul job.** Un groupe découpé à un seul job reçoit aussi les runs du besoin comme `totalGroupRuns` au recalcul.
- **R2 — Valeur du total inchangée.** La valeur écrite reste celle d'aujourd'hui : sans job aux runs fixés, les runs du besoin (`SplitGroupQuantities::runsOfNeed()`) ; avec au moins un job aux runs fixés, la somme des runs des jobs (spec #185).
- **R3 — Job modifié.** Un job dont seul `totalGroupRuns` change compte comme modifié : il est dans la liste renvoyée par `recalculateStepQuantities()` (donc dans la réponse du PATCH d'une étape) et déclenche le `flush`.
- **R4 — Idempotence.** Un second recalcul sans changement ne modifie aucun job et renvoie une liste vide.
- **R5 — Le reste inchangé.** Répartition des runs (prorata, minimum d'un run par job), quantités (#112), matériaux (#73), besoins (#16).

## Exemples chiffrés

Golden SDE, sans structure, ME 0. Fernite Alloy : 200 unités par run ; un run de Fernite Carbide en demande 100. Colonnes : `[runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns]`.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Fernite Carbide 11 runs (besoin 1 100, 6 runs). Groupe Fernite Alloy 2 + 2 + 1 runs, quantités 400, 350, 200, `totalGroupRuns` 5, étapes dans l'ordre de `splitIndex` | `[2, 400, 1, 0, 20, 0, 5]`, `[2, 400, 1, 0, 20, 1, 6]`, `[2, 300, 1, 0, 20, 2, 6]` ; modifiés : index 1 et 2 | `[2, 400, 1, 0, 20, 0, 6]`, `[2, 400, 1, 0, 20, 1, 6]`, `[2, 300, 1, 0, 20, 2, 6]` ; modifiés : index 0, 1 et 2 |
| E2 | Même groupe, étapes dans l'ordre 2, 0, 1 (prorata dans l'ordre du tableau : 1, 2, 3 runs) | index 0 : `[2, 400, 1, 0, 20, 0, 5]` ; index 1 : `[3, 600, …, 1, 6]` ; index 2 : `[1, 100, …, 2, 6]` | index 0 : `[2, 400, 1, 0, 20, 0, 6]`, le reste inchangé |
| E3 | Fernite Carbide 12 runs (besoin 1 200, 6 runs). Groupe 2 + 2 + 2 runs, quantités 400 × 3, `totalGroupRuns` 5 sur les trois (valeur périmée) | rien ne change, liste vide | `totalGroupRuns` 6 sur les trois, runs et quantités inchangés ; modifiés : les trois |
| E4 | Recalcul de E1 une seconde fois | — | aucun job modifié, liste vide (R4) |
| E5 | Fernite Carbide 1 run (besoin 100, 1 run). Groupe 3 + 2 runs sans `totalGroupRuns` (cas #131) | `[1, 100, 1, 0, 20, 0, 1]`, `[1, 0, 1, 0, 20, 1, 1]` | inchangé (R2 : total = runs du besoin, même si la somme des runs vaut 2) |
| E6 | Groupe avec un job aux runs fixés (spec #185) | tous les jobs reçoivent la somme des runs | inchangé (garde-fou) |
| E7 | PATCH des runs de Fernite Carbide qui mène à E1 | réponse : l'étape modifiée + Alloy index 1 et 2 | réponse : l'étape modifiée + Alloy index 0, 1 et 2 |
| E8 | Fernite Carbide 13 runs (besoin 1 300, 7 runs). Fernite Alloy à un seul job de 7 runs, quantité 1 300, `totalGroupRuns` 4 | `[7, 1300, 1, 0, 20, 0, 7]` | job dans les modifiés, un `flush` (R1 bis) |

## Cas limites

- Job sans `totalGroupRuns` (null) dans un groupe : il reçoit le total au recalcul, comme les autres.
- Projets déjà en base : alignés au prochain recalcul (saisie d'une étape, rattachement d'un job, matching des jobs ESI), pas de migration.
- Régénération (`IndustryProjectFactory::jobsKeepingFixedRuns()`) : ne garde que les groupes à runs fixés, déjà alignés ; les autres groupes sont redécoupés. Inchangé.

## Hors périmètre

- Groupe qui garde plus de runs que le besoin (minimum d'un run par job, E5) et valeur du total dans ce cas : #131.
- Ajout manuel d'un job à un groupe (`CreateStepProcessor`, `splitGroupId` fourni) : le nouveau job reprend le total du groupe sans le changer, la différence affichée signale le surplus. Inchangé.
- `StepTree.vue` : aucun changement, le défaut d'affichage disparaît avec R1.

## Décisions (validées par Sylvain le 2026-10-28)

- Job unique périmé : son `totalGroupRuns` est réécrit (décidé par Sylvain le 2026-10-28).

- **Valeur du total quand le groupe garde plus de runs que le besoin** (minimum d'un run par job, E5) : option A retenue, `totalGroupRuns` reste les runs du besoin (R2). Le cas reste à #131.

## Tests existants appelés à changer

- `IndustryProjectFactoryGoldenStepsCharacterizationTest::testUnchangedJobOfARecalculatedSplitGroupCurrentlyKeepsTheOldTotalGroupRunsIssue252` (groupe `issue-252`) : fige l'ancien total 5 sur l'index 0 et la liste modifiée `[1, 2]`. Attendu E1 : 6 sur l'index 0, liste `[0, 1, 2]`. Renommé sans `Currently…Issue252`, le groupe `issue-252` devient un groupe de régression.
- `IndustryProjectFactoryGoldenStepsCharacterizationTest::testRecalculatedSplitGroupSharesTheNeedBySplitIndexWhateverTheOrderOfTheSteps` (groupe `issue-112`) : fige aussi l'ancien total sur l'index 0, `[2, 400, 1, 0, 20, 0, 5]` devient `[2, 400, 1, 0, 20, 0, 6]` (E2).

Restent verts (garde-fous, à confirmer par la phase rouge) :

- `testSplitChildGroupCurrentlyKeepsTwoRunsForANeedOfOneIssue131` : les deux jobs changent déjà, total 1 inchangé (E5, décision A).
- `testRecalculatingTheStepsOfAJustCreatedNomadChangesNoStep`, `testSecondRecalculationOfOneNomadChangesNoStep`, le test du snapshot `nomad-1run-me10-no-structure-recalculated-per-product` (liste modifiée vide) : les groupes créés par la factory portent déjà le total du besoin. Si l'un d'eux tombe, c'est qu'un groupe créé porte un total différent des runs du besoin : à remonter, pas à masquer.
- `SplitGroupQuantitiesTest` et `IndustryStepCalculatorTest` (`testRedistributeAfterSplitProportionally`, `testSplitGroupWithZeroOldTotalRunsUsesOneAsDefault`) : tous les jobs y changent de runs. Les tests à runs fixés (spec #185) comparent déjà le total.
- `IndustryJobMatcherTest`, `LinkJobProcessorTest` : le calculateur y est doublé.

## Glossaire

Précision ajoutée à **Groupe découpé** dans [`docs/glossary.md`](../glossary.md) : « Tous les jobs du groupe portent le même `totalGroupRuns` : un recalcul l'écrit sur chacun, même sur un job dont runs et quantité ne changent pas (spec #252). »

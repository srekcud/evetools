# #131 : tests tueurs de mutants (revue test-skeptic)

Rien n'est commité. Pas de changement dans `src/`. `frontend/dist/index.html` et `frontend/tsconfig.tsbuildinfo`, réécrits par `npm run build`, ont été remis à HEAD.

## Tests ajoutés

### `tests/Unit/Service/Industry/SplitGroupQuantitiesTest.php` (tous verts)

| Point | Test | Ce qu'il fige |
|---|---|---|
| 1 | `testKeptJobsAreSelectedAndRenumberedByAscendingSplitIndexWhateverTheInputOrder` | Jobs passés dans l'ordre de `splitIndex` [3, 2, 0, 1] ; trois jobs engagés pour deux runs : on garde les `splitIndex` 1 et 2 (R3) ; le `splitIndex` 2 absorbe le job matché, le stock et la marque « acheté » du job 3 (R4, stock 25 + 12 = 37) ; les jobs gardés sont renumérotés 0 et 1, quantités 40 / 30 (R5, R10) |
| 2 | `testRunsMissingFromTheLastJobAreTakenFromTheSmallestSplitIndexAmongTheJobsWithTheMostRuns` | Jobs 6 + 6 + 1, passés dans l'ordre [1, 0, 2], besoin de 4 runs → [1, 2, 1] (R8, égalité de runs : le plus petit `splitIndex`) |
| 2 | `testTwoRunsMissingFromTheLastJobsAreTakenOneByOneFromTheJobWithTheMostRuns` | Jobs 9 + 9 + 1 + 1, besoin de 4 runs → [1, 1, 1, 1] (excès de 2) |
| 3 | `testKeptJobWhoseOnlyChangeIsItsSplitIndexIsAChangedStep` | `totalGroupRuns` déjà juste, seul le `splitIndex` passe de 2 à 1 : `changedSteps === [ce job]` |
| mutant l. 95 | `testKeptJobWhoseOnlyChangeIsItsQuantityIsAChangedStep` | Seule la quantité change (80 → 70) : le job est modifié et remonté |
| 7 | `testCommittedJobsThatAreNotFixedAreRemovedAndAbsorbedByTheFixedJobThatRunsMoreThanTheNeed` (`issue-185`) | Job fixé à 12 runs, besoin de 10 runs (reste −2) : les deux jobs non fixés engagés sont supprimés, absorbés par le job fixé, groupe dissous |
| 7 | `testSurplusJobsThatAreNotFixedAreAbsorbedByTheFixedJobOfLargestKeptSplitIndex` (`issue-185`) | Besoin > 0, job fixé au `splitIndex` 2 : il absorbe le stock du job 3 en trop ; ordre de `changedSteps` = ordre des jobs |

### `tests/Unit/State/Processor/Industry/UpdateStepProcessorTest.php`

- Point 6, vert : `testReleasingKeepsTheCommittedEditedJobAndRemovesTheSmallerSplitIndex`. Groupe Fernite Alloy 3 + 2 + 2 + 2 entièrement fixé, besoin de 2 runs, `runsFixed: false` sur le job 1 (engagé). Résultat : les jobs 1 et 2 sont gardés à `[1, 200]`, avec les `splitIndex` 0 et 1 ; le job 2 reçoit le job matché et la marque « acheté » du job 3 ; les jobs 0 et 3 sont supprimés ; la réponse contient les deux jobs gardés.
- Point 4, **rouge** : `testEditedJobRemovedAsASurplusJobWhileNoOtherStepChangesIsNotInTheResponse`. Voir plus bas.

### `tests/Unit/Service/Industry/IndustryStepCalculatorTest.php`

- Point 4, **rouge** : `testSurplusJobRemovedWhileNoKeptJobChangesIsFlushed`. Voir plus bas.

### `frontend/tests/stores/stepsSurplusJobs.spec.ts` (point 5, tous verts)

- `reloads the project when a returned split job is not in the store` : tue le retrait de `answeredStep.splitGroupId !== null` (dans ce test, aucune étape de la réponse n'était découpée dans le store).
- `returns the edited step found by its id in a list response` : l'étape éditée est en deuxième position de la réponse, ce qui tue `enriched[0]`.
- `returns no step when the edited job is no longer in the response` : `undefined`.
- `leaves the steps of another current project untouched when no split group is involved`.

`npm run build` (type-check) passe ; vitest donne 10/10 sur le fichier.

## Tests rouges (défauts réels)

1. **`IndustryStepCalculator::recalculateStepQuantities()` ne flushe pas un retrait de job sans autre changement.**
   - Données : jobs fixés à 6 et 4 runs, plus un job non fixé à 0 run hérité de #185 ; `totalGroupRuns` 10 ; besoin de 10 runs.
   - Le job en trop est bien retiré du projet, mais `$updatedSteps === []`, donc `flush()` n'est pas appelé. Échec : « flush expected 1 time, actually called 0 times ».
   - Impact : `UpdateStepProcessor` flushe de toute façon. En revanche, `IndustryJobMatcher` (l. 153-156) flushe *avant* le recalcul : sur ce chemin, le retrait n'est jamais écrit en base.
   - Écart avec le brief : avec deux jobs seulement (fixé 10 + non fixé 0), le test serait **vert**. La dissolution renvoie toujours le job gardé dans `changedSteps` (`SplitGroupQuantities` l. 77), donc il y a un flush. Le défaut demande au moins deux jobs gardés inchangés.
2. **`UpdateStepProcessor` renvoie la ressource de l'étape éditée alors qu'elle vient d'être supprimée.**
   - Données : Fernite Alloy 1 + 1 + 0 (`totalGroupRuns` 2, besoin de 2 runs) ; on change le ME du job à 0 run.
   - La cascade supprime ce job sans modifier les autres. `$cascadedSteps` est vide, donc la branche « sans cascade » mappe l'étape supprimée (dont l'identifiant vaut null après le flush).
   - Réponse attendue, déduite de R15 : une liste (une cascade a eu lieu), sans l'étape éditée (supprimée) et sans étape gardée modifiée, donc `[]`.
   - **Écart avec la spec** : R15 affirme qu'« une liste vide n'est pas possible : une suppression modifie toujours au moins un job gardé ». Ce cas le contredit. À trancher par Sylvain : liste vide, ou liste avec les jobs gardés même inchangés. Le front recharge déjà le projet quand l'étape éditée manque à la réponse : `[]` y mène.

## Mutation testing (Infection, sans toucher à `src/`)

- Commande : `wt-php.sh … unit -d memory_limit=3G vendor/bin/infection --filter=src/Service/Industry/SplitGroupQuantities.php --test-framework-options="--testsuite=Unit --filter=<regex excluant les 2 rouges>"`.
- Résultat : 158 mutants, 153 tués, 1 en timeout, 4 survivants. Les 4 survivants sont équivalents :
  - l. 84, `max(0, …)` → `max(±1, …)` (2 mutants) : la valeur n'est jamais ≤ 0 quand un job non fixé est gardé ;
  - l. 137, `array_intersect_key` retiré : le résultat n'est lu que par ses clés ;
  - l. 191, `oldTotalRuns = 2` : tous les runs valent 0, donc le prorata reste 0.
- Tués grâce à ces ajouts : l. 128 (`? 1 : -1`, reste négatif), l. 66 (ordre de `changedSteps`), l. 95 (quantité seule).
- Sur les trois fichiers (dont `IndustryStepCalculator` et `UpdateStepProcessor`), hors tests rouges : seuls les survivants équivalents ci-dessus restent.

## Doutes

- Brief point 1 : l'ordre suggéré [2, 0, 1] sur 3 jobs ne tue ni le mutant « sans tri » de l'absorbeur ni celui du réindexage. Les deux ensembles de clés coïncident. J'ai donc utilisé 4 jobs dans l'ordre [3, 2, 0, 1], ce qui couvre sélection, absorption et réindexage dans un seul test.
- Point 5, « currentProject d'un autre projet » : le test du store vérifie la garde des identifiants dans le seul cas réaliste.
  - Le mutant qui retire `currentProject?.id === projectId` est équivalent tant que les identifiants ne collisionnent pas (UUID).
  - Autre point non figé : avec un autre projet courant et une réponse contenant un job découpé, `fetchProject` remplace le projet courant. La spec R16 dit « recharge le projet », mais l'effet de bord semble discutable. Il est inatteignable aujourd'hui, puisque seul `StepsTab` appelle `updateStep`, avec le projet courant.
- Aucun groupe `issue-131` posé, comme pour les tests rouges et verts précédents (décision laissée à Sylvain).

## Messages de commit proposés

- `test(industry): kill the surviving mutants of split group surplus jobs (#131)`
- `test(industry): specify the flush and the response when only a surplus job is removed (#131)`
- `test(frontend): pin the return value and the reload of updateStep on split jobs (#131)`

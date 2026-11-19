# #202 : tests tueurs de mutants (revue test-skeptic)

Fichier unique : `tests/Unit/State/Processor/Industry/DeleteStepProcessorTest.php`. Aucun changement dans `src/`, aucun commit.

## Harnais

- L'`EntityManagerInterface` doublé enregistre aussi chaque `remove` dans `$removedEntities` (stub `willReturnCallback`, même style que `projectAtEachFlush`). La cascade ne passe pas par `remove` (jobs en trop : `IndustryProject::removeStep` + `orphanRemoval`), donc la liste ne contient que l'étape supprimée par le processor.

## Points traités (tous verts, le code est correct)

| Point | Mutant visé | Test |
|---|---|---|
| 1 | `DeleteStepProcessor` l.77 `flush` retiré | `testDeletedProductAddedFromATypeLeavesTheOtherStepsUnchanged`, `testDeletedCopyStepLeavesTheProductionStepsUnchanged` : un seul `flush`, dernier instantané = les 3 étapes FC4 `[[4, 40 000, false, 0, null], [2, 400, …], [1, 30, …]]`. La cascade ne flushe pas (rien ne change), seul l.77 écrit |
| 2 | l.69 `remove` retiré | même deux tests + `testDeletedJobOfASplitGroupOfTwoDissolvesTheKeptJob…` : `$removedEntities === [étape supprimée]` |
| 3 | `SplitGroupQuantities::passDeletedJobOnToGroup` l.120 (`totalGroupRuns` = nombre de jobs) | nouveau `testRootJobsKeptAfterADeletedRootJobHaveTheSumOfTheirRunsAsTotalGroupRuns` : racine `[3, 2, 1]`, suppression du job de 1 run → `[3, 30 000, false, 0, 5]`, `[2, 20 000, false, 1, 5]`, Fernite Alloy `[3, 500]`, Hydrogen Fuel Block `[1, 40]` |
| 4 | `IndustryProject::isLastProductionStepOfProjectProduct` l.480 et l.486 (condition `copy`) | nouveaux `testLastProductionStepOfTheProjectProductBesideACopyOfItIsRefusedWithoutWritingAnything` (Quantum Microprocessor + sa copie, refus, 0 `flush`, 0 `remove`) et `testDeletedCopyOfTheProjectProductWithoutAnyProductionStepOfItLeavesTheMaterialSteps` (copie seule du produit, acceptée, Titanium Carbide `[1, 490]` et Oxygen Fuel Block `[1, 5]` restent) |
| 5 | ordre R2 / R3 inversé | nouveau `testLastStepOfTheProjectProductHoldingStockIsRefusedAsTheLastStepOfTheProjectProductFirst` : Fernite Carbide seule, stock 100 → `LAST_STEP_OF_THE_PROJECT_PRODUCT`, stock gardé, 0 `flush` |
| 7 | `usort` de `stepIndexesInSplitIndexOrder` retiré | nouveau `testDeletedJobPassesItsPurchaseStockAndPurchasedMarkToTheKeptJobOfLargestSplitIndexWhateverTheOrderOfTheSteps` : jobs Fernite Alloy insérés dans l'ordre splitIndex 2, 0, 1 ; le job 1 (stock 120, « acheté », achat 120 × 1 912,25) est supprimé → le job d'index 2 reçoit stock, marque et achat, devient `[2, 400, false, 1, 5]` ; le job 0 `[3, 600, false, 0, 5]` sans rien ; Hydrogen Fuel Block `[2, 75]` |

Points 6 et 8 non traités (hors périmètre, comme demandé).

Valeur Hydrogen Fuel Block du point 3 : 40 = 5 par run de Fernite Carbide (5 runs) + 5 par run de Fernite Alloy (3 runs) ; même rapport que D-E1 (75), D-E2 (60), D-E5 (15), D-E9 (20). Ma première prévision (45) était fausse, pas le code.

## Vérification des mutants

- Infection (`--filter` sur les 3 classes, `--test-framework-options=--filter=DeleteStepProcessorTest`) : 23/23 tués, dont l.69 et l.77. `#[CoversClass(DeleteStepProcessor::class)]` limite la couverture à `DeleteStepProcessor` : Infection ne mute pas `SplitGroupQuantities` ni `IndustryProject` depuis ce test.
- Pour ces deux classes : copies mutées dans `/tmp`, montées en lecture seule par-dessus `src/…` dans un conteneur `evetools-app-dev` éphémère (mêmes options que `wt-php.sh unit`), `src/` du worktree intact. Résultat, un seul échec par mutant, sur le test prévu :
  - l.120 `count($keptJobs)` → point 3 ;
  - `usort` retiré → point 7 ;
  - l.480 sans `copy ||` → point 4 (copie acceptée) ;
  - l.486 sans `!== 'copy'` → point 4 (refus) ;
  - R3 avant R2 → point 5.

## Exécution

- `--filter DeleteStepProcessorTest` : 33 tests, 65 assertions, OK.
- `tests/Unit/State/Processor/Industry` : 282 tests OK (1 notice PHPUnit déjà présente, `DeleteStockpileTargetProcessorTest`).

Aucun défaut de production trouvé.

## Message de commit proposé

```
test(industry): kill the surviving mutants of a step deletion (#202)

Pin the flush and the removal of the deleted step, the total group runs of kept root jobs, the copy rule of the last project product step, the R2 before R3 order and the absorbing job whatever the step order.
```

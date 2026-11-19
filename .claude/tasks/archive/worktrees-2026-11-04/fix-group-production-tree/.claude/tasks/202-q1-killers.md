# #202 Q1 (O2 f révisée) - tests rouges et tueurs

Commits : 89ac9750 (rouges), d597d3a4 (tueurs). Aucun changement dans `src/`.

## Rouges (5, échouent sur assertion, pas sur classe absente)

`catch (StepMaterialsMissingFromSdeException)` et `::class` ne chargent pas la classe : aucun « class not found », l'échec porte sur les valeurs (le lecteur rend `[]`).

- `StepMaterialTypeIdsReaderTest::testStepWhoseBlueprintIsMissingFromTheSdeRaisesAnError` : clé de Titanium Carbide absente de la réponse.
- `StepMaterialTypeIdsReaderTest::testEveryStepOfAProjectWithAnotherBlueprintMissingFromTheSdeRaisesAnErrorAtEachRead` : clé d'Oxygen Fuel Block absente ; QM, TC, QM de nouveau, OFB lèvent tous (le mapper lit ensuite chaque étape avec le même lecteur).
- `DeleteStepProcessorTest::testStepWhoseBlueprintIsMissingFromTheSdeIsRefusedWithoutWritingAnything` : remplace `testStepWithoutMaterialsInTheSdeIsNotRefusedForTheStepsOfItsFormerMaterials` (figeait l'ancien O2 f, 204). 400 + message exact, étapes et `[runs, quantity]` inchangés, 0 flush, rien supprimé.
- `DeleteStepProcessorTest::testStepIsRefusedWithoutWritingAnythingWhileTheMaterialsOfAnotherBlueprintOfTheProjectAreMissingFromTheSde` : DELETE d'Oxygen Fuel Block (accepté aujourd'hui) quand le lecteur n'a pas la clé de Quantum Microprocessor.
- `IndustryResourceMapperDeletableStepTest::testNoStepOfAProjectWithABlueprintMissingFromTheSdeIsDeletable` : QM-P sans la clé QM, `[false, false, false, false]` (aujourd'hui `[false, true, true, true]`), sans exception.

## Tests existants modifiés dans le lot rouge

- `IndustryProjectRefusalToDeleteStepTest::testStepWithoutMaterialsInTheSdeIsNotRefusedForTheStepsOfItsFormerMaterials` supprimé : la clôture ne rend plus jamais `[]` pour un blueprint absent, la règle est portée par le lecteur.
- `DeleteStepProcessorTest::testDeletionWhoseCascadeReachesAMaterialStepWithoutProductInTheSdeIsRefusedWithoutFlushing` : le lecteur reçoit les matériaux de Hydrogen Fuel Block sous la clé `999999999-1`, sinon O2 f le refuserait avant la cascade et le test ne couvrirait plus `BlueprintProductMissingFromSdeException`.
- Harnais : `delete()`, `processor()`, `assertDeletionRefused()` prennent un dépôt de matériaux optionnel ; `BuildsProjectOnGoldenSde::materialRepositoryOnGoldenSdeWithoutTheBlueprintOf()` ajouté.

## Tueurs (passent déjà)

1. `#[CoversClass(StepMaterialTypeIdsReader::class)]` sur `DeleteStepProcessorTest` et `IndustryResourceMapperDeletableStepTest`. Lecteur : matériaux exacts du SDE golden ; second projet aux mêmes blueprints = aucune relecture ; étape d'un blueprint neuf = une lecture du seul blueprint 1016680.
2. Entité, clôture qui rend aussi les matériaux des copies : `testDeletedCopyIsNotRefusedWhenTheSdeGivesItsBlueprintMaterials`, `testCopyIsNotAConsumerWhenTheSdeGivesItsBlueprintMaterials` (refus O1), `testCopyOfAMaterialIsNotAMaterialStepWhenTheSdeGivesItsBlueprintMaterials`, `testOnlyProductionStepOfTheProjectProductBesideACopyOfItIsRefusedAsTheLastStepOfTheProjectProduct` (R2), `testJobOfTheProjectProductBesideACopyOfItAndAnotherJobOfItIsNotRefused` (jobs 20 + 12).
3. `testOnlyConsumerOfASplitMaterialStepIsRefused` : OFB en deux jobs `[1, 40]` et `[2, 80]`, DELETE TC refusé O1.
4. `DeleteStepProcessorTest::testRefusedDeletionReadsTheMaterialsOfTheProjectFromTheSdeOnce` (O6).

## Points pour la phase verte

- Namespace de l'exception pris du brief : `App\Service\Industry\StepMaterialsMissingFromSdeException` (les autres exceptions SDE sont dans `App\Exception`). Les tests importent ce FQCN.
- Le lecteur doit lever à chaque appel pour toute étape du projet, y compris au second appel (pas seulement à la lecture qui a révélé la clé absente).
- Les tests de mapper hors `deletable` (`IndustryResourceMapperTest`, `InStockStepTest`, `IndustryProjectFactoryTest`, `SyncCharacterSkillsProcessorTest`) passent un dépôt qui rend `[]` : avec O2 f, le mapper doit attraper l'exception pour qu'ils restent verts.

## Exécution

`--group issue-202` : 78 tests, 5 échecs (les 5 rouges). Suite Unit : 14 435 tests, 5 échecs (les mêmes), 1 incomplet antérieur.

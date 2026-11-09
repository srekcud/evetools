# #115 lot 2 — phase rouge (services industrie)

Branche `fix/type-name-missing-from-sde-services`, worktree `evetools-wt-DT`. Rien de commité.

## Empreinte des tests

`79d958106ada` = `cat $(git diff --name-only -- tests | sort) | sha256sum | cut -c1-12`, sur les 6 fichiers :

- tests/Unit/Service/Industry/BatchProfitScannerServiceTest.php
- tests/Unit/Service/Industry/InventionServiceTest.php
- tests/Unit/Service/Industry/PivotAdvisorServiceTest.php
- tests/Unit/Service/Industry/ResolvesTypeNamesFromSde.php
- tests/Unit/Service/Industry/SlotTrackerServiceTest.php
- tests/Unit/Service/TypeNameResolverTest.php

## Squelette dans src/ (exception TDD rouge)

`TypeNameResolver::findNames(array $typeIds): array` délègue à `resolveMany()`. Il rend `9848 => 'Type #9848'`, donc E1 échoue sur l'assertion et non sur une méthode manquante. La phase verte doit le réécrire.

## Tests (18 nouveaux, tous `#[Group('issue-115')]`)

| Ex. | Test | État | Raison de l'échec |
|---|---|---|---|
| E1 | TypeNameResolverTest::testFindNamesReturnsOnlyTheSdeNamesOfTypesInInvTypesIndexedByTypeId | rouge | `9848 => 'Type #9848'` en trop |
| E1 | TypeNameResolverTest::testFindNamesOfNoTypeIsEmpty | vert | — |
| E2 | PivotAdvisorServiceTest::testPivotAnalysisOfTypesKnownToTheSdeKeepsTheirSdeNames | vert | garde-fou |
| E3 | PivotAdvisorServiceTest::testMaterialMissingFromTheSdeHasAnUnknownNameInThePivotMatrixWithUnchangedQuantitiesAndCosts | rouge | ligne 9848 de la matrice : `'Type #9848'` au lieu de `null` (puis missingComponents, keyComponents) |
| E4 | PivotAdvisorServiceTest::testSourceProductMissingFromTheSdeWithoutBlueprintHasAnUnknownName | rouge | `sourceProduct.typeName` `'Type #9848'` (emptyResult) |
| E5 | PivotAdvisorServiceTest::testCandidateProductMissingFromTheSdeHasAnUnknownNameWithUnchangedMarginAndRank | rouge | candidat 9848 `'Type #9848'` ; marge 207.41, couverture 35.9, score 7445.56, ordre [9848, 22458] |
| E2 | BatchProfitScannerServiceTest::testScannedProductsKnownToTheSdeKeepTheirSdeNames | vert | garde-fou |
| E6 | BatchProfitScannerServiceTest::testScannedProductMissingFromTheSdeHasAnUnknownNameAndStaysListedWithUnchangedProfit | rouge | `[ 'Type #9848', 'Rifter' ]` au lieu de `[null, 'Rifter']` ; coût 1710, profit 1290, marge 75.44, ISK/jour 9030 |
| E2 | InventionServiceTest::testBpcKitOfAProductKnownToTheSdeKeepsTheSdeNamesOfTheProductAndItsDatacores | vert | garde-fou |
| E2 | InventionServiceTest::testCopyCostsOfTypesKnownToTheSdeKeepTheirSdeNames | vert | garde-fou |
| E7 | InventionServiceTest::testDatacoreMissingFromTheSdeHasAnUnknownNameInTheInventionDataWithUnchangedQuantity | rouge | `materials[1].typeName` `'Type #9848'` |
| E7 | InventionServiceTest::testDatacoreMissingFromTheSdeHasAnUnknownNameInTheInventionCostWithUnchangedPrices | rouge | `datacores[1].typeName` `'Type #9848'` ; coût par tentative 310000, total 1240000 |
| E8 | InventionServiceTest::testBpcKitOfAProductMissingFromTheSdeHasAnUnknownProductNameWithUnchangedProbabilityAndCosts | rouge | `productName` `'Type #9848'` ; probabilité 0.30, total 1040000 |
| E9 | InventionServiceTest::testCopyCostsOfABlueprintAndProductMissingFromTheSdeHaveUnknownNamesWithUnchangedCost | rouge | `blueprintName` et `productTypeName` `'Type #9848'` ; coût 20000 |
| E2 | SlotTrackerServiceTest::testJobOfAProductKnownToTheSdeKeepsItsSdeName | vert | garde-fou |
| E10 | SlotTrackerServiceTest::testJobOfAProductMissingFromTheSdeHasAnUnknownNameWithUnchangedOrder | rouge | `[ 'Type #9848', 'Nitrogen Fuel Block' ]` ; ordre des jobs [2, 1] |
| E11 | SlotTrackerServiceTest::testFreeSlotSuggestionOfATypeMissingFromTheSdeKeepsTheNameStoredOnTheStockpileTarget | vert | garde-fou |
| R4 | SlotTrackerServiceTest::testFreeSlotSuggestionOfATypeMissingFromTheSdeKeepsTheStoredNameWhileAJobProducesThatType | rouge | suggestion `'Type #9848'` au lieu de `'Robotics'` |

Preuve que les autres assertions sont justes : sur des copies, j'ai remplacé chaque nom attendu `null`/`'Robotics'` par `'Type #9848'`. Les 133 tests des quatre classes passaient alors tous. Les quantités, coûts, marges, scores et ordres attendus correspondent donc au comportement actuel (R3). Les fichiers ont ensuite été restaurés.

## Cas limite ajouté hors tableau de la spec (à valider)

Le dernier test (R4) n'est pas un exemple de la spec. Aujourd'hui, les noms passés à la suggestion viennent de `resolveMany()` sur les produits des jobs. Si un job tourne sur 9848, la suggestion prend donc `'Type #9848'` au lieu du nom enregistré « Robotics ». C'est un vrai défaut de R4, que E11 ne voit pas, car E11 n'a pas de job sur ce type.

## Tests existants adaptés (harnais, sans changer d'assertion)

- `ResolvesTypeNamesFromSde` : le `InvTypeRepository` simulé répond aussi à `findByTypeIds()`, avec les seuls types connus. Avant, il rendait `[]`, et `resolveMany()` inventait alors un nom même pour un type connu.
- `BatchProfitScannerServiceTest` : le mock `TypeNameResolver` est remplacé par un vrai résolveur sur un SDE simulé (`SCAN_SDE_TYPE_NAMES`), et les 15 `->method('resolveMany')` sont supprimés. Raison : 3 tests (`testScanCalculatesProfitCorrectly` 'Rifter', `testScanHandlesMultipleOutputPerRun` 'Scourge Heavy Missile', `testScanFiltersByCategoryT2Ships` 'Jaguar') auraient cassé dès le passage à `findNames()`, puisqu'un mock non configuré rend `[]`.
- `InventionServiceTest` : un vrai `TypeNameResolver` sur le mock `InvTypeRepository` du setUp remplace le mock du résolveur. `->method('resolve')->willReturn('Sabre')` est supprimé de `stubKnownCostsPerAttempt()`. `setupSabreInventionChain()` délègue maintenant à `setupInventionChain(t2ProductTypeId, probabilité, runs, datacores)`. Ainsi `testGetInventionDataReturnsCorrectChain` reste valide, que les matériaux passent par `InvTypeRepository::findByTypeIds()` ou par `findNames()`.
- `PivotAdvisorServiceTest`, `SlotTrackerServiceTest` : aucun test existant ne vérifie un nom, et leurs mocks `resolveMany()`/`resolve()` restent valides. Les nouveaux tests utilisent une instance du service construite avec un vrai résolveur.
- `BatchProfitScanRulesTest` : son stub `resolveMany()` reste en place, et aucune assertion ne porte sur `typeName`. Il n'est pas touché.

Hypothèse du harnais pour la phase verte : `findNames()` s'appuie sur `InvTypeRepository::findByTypeIds()` et `findName()` sur `find()`. Une autre méthode de dépôt obligerait à rouvrir `tests/`.

## Suites complètes

- Unit : 2722 tests, 11 échecs, tous des tests rouges ci-dessus. Aucun autre test cassé. Restent 1 incomplete déjà présent (`InstallCostTest::testCopyingInstallCostWithSccSurcharge`) et 221 notices PHPUnit (218 avant, +3 venant des mocks non utilisés du setUp de Pivot).
- Les deux tests E7 lèvent un warning PHP dans `src/Service/Industry/InventionService.php:186` (`Undefined array key 9848`, à cause de `$typeNames[$id]?->` sur une clé absente). C'est un défaut existant, qui disparaît avec la correction.
- Integration : 183 tests, OK.

## Messages de commit proposés

- `test(industry): specify that a type missing from the SDE has an unknown name in the industry analyses`

# #115 lot 1 — phase rouge

## Harnais (écart assumé par rapport au brief)

Pas de mock de `IndustryCalculationService::resolveTypeName()`. Les tests passent par un vrai `IndustryCalculationService` et un vrai `TypeNameResolver`. Seule la base est simulée : un stub de `InvTypeRepository::find()` qui connaît 4051 (Nitrogen Fuel Block) et renvoie `null` pour 9848 (Robotics). Trait partagé : `tests/Unit/Service/Industry/ResolvesTypeNamesFromSde.php`.
Pourquoi : la règle du dépôt veut des mocks aux frontières seulement (ici la base). Surtout, un mock qui renvoie `null` aurait obligé à passer `resolveTypeName()` en `?string` **et** à rendre nullables `ProjectStepResource::$productTypeName`, `StepPurchaseResource::$typeName`, `ProjectResource::$productTypeName/$displayName`, `AvailableJobResource::$productTypeName` et `PurchaseSuggestionResource::$typeName`. Sans ça, l'échec aurait été un TypeError. Avec ce harnais, le code actuel produit `'Type #9848'` et chaque test échoue sur une assertion, sans toucher aux signatures. Pour passer au vert, l'implementer devra rendre ces types nullables lui-même.

## Squelette créé (src/)

- `src/Service/TypeNameResolver.php` : `public function findName(int $typeId): ?string { return $this->resolve($typeId); }`. Aucune autre signature n'a été modifiée.

## Tests (groupe `issue-115`)

| Ex. | Fichier::méthode | État | Échec |
|---|---|---|---|
| E1 | IndustryResourceMapperTest::testStepPurchaseAndProjectOfAKnownTypeKeepTheirSdeNames | vert (garde-fou) | — |
| E2 | TypeNameResolverTest::testFindNameReturnsTheSdeNameOfATypeInInvTypes | vert (garde-fou) | — |
| E2 | TypeNameResolverTest::testFindNameReturnsAnUnknownTypeNameForATypeMissingFromInvTypes | rouge | `'Type #9848' is null` |
| E3 | IndustryResourceMapperTest::testStepWhoseProductIsMissingFromTheSdeHasAnUnknownTypeName | rouge | `'Type #9848' is null` |
| E4 | IndustryResourceMapperTest::testPurchaseOfATypeMissingFromTheSdeHasAnUnknownTypeNameAndKeepsItsQuantityAndPrice | rouge | `'Type #9848' is null` |
| E5 | IndustryResourceMapperTest::testPurchaseOfATypeMissingFromTheSdeListedInAStepHasAnUnknownTypeName | rouge | `'Type #9848' is null` |
| E6 | IndustryResourceMapperTest::testProjectWhoseOnlyRootProductIsMissingFromTheSdeHasNoDisplayName | rouge | `'Type #9848' is null` (productTypeName) |
| E7 | IndustryResourceMapperTest::testProjectWithOneRootProductMissingFromTheSdeAmongTwoHasNoDisplayName | rouge | rootProducts[1].typeName `'Type #9848'` au lieu de `null` |
| E8 | IndustryResourceMapperTest::testCustomNameOfAProjectWhoseProductIsMissingFromTheSdeStaysItsDisplayName | rouge | displayName « Batch robotics » OK, productTypeName `'Type #9848'` au lieu de `null` |
| E9 | IndustryResourceMapperTest::testProjectWithoutRootStepWhoseProductIsMissingFromTheSdeHasNoDisplayName | rouge | `'Type #9848' is null` |
| E9 | IndustryCalculationTest::testProjectDisplayNameIsUnknownWithoutCustomNameWhenItsProductIsMissingFromTheSde | rouge | `'Type #9848' is null` |
| E9 | IndustryCalculationTest::testProjectDisplayNameIsTheSdeNameWithoutCustomNameWhenItsProductIsKnown | vert (garde-fou) | — |
| E10 | AvailableJobsProviderTest::testJobProducingATypeMissingFromTheSdeIsListedWithAnUnknownTypeNameAndStaysLinkedToItsStep | rouge | `'Type #9848' is null` (productTypeName) |
| E11 | PurchaseSuggestionProviderTest::testWalletTransactionOnATypeMissingFromTheSdeIsSuggestedWithAnUnknownTypeNameAndUnchangedQuantityAndPrices | rouge | `'Type #9848' is null` |
| E12 | ProductionCostServiceTest::testJobInstallCostOfAStepWhoseProductIsMissingFromTheSdeHasAnUnknownProductNameAndAnUnchangedCost | rouge | `'Type #9848' is null` (total 22 270.0 déjà OK) |
| E13 | RegenerateProjectStepsCommandTest::testCommandNamesAProjectWhoseProductIsMissingFromTheSdeByItsTypeId | rouge | sortie `Regenerating steps for: Type #9848` au lieu de `type 9848 (name missing from the SDE)` |
| E13 | RegenerateProjectStepsCommandTest::testCommandNamesAProjectWhoseProductIsKnownByItsSdeName | vert (garde-fou) | — |
| E14 | ProjectCollectionProviderTest::testSabreProjectSumsJobMatchCostsAndComputesProfitFromRealSellPrice | rouge | `'Type #22456' is null` |
| E14 | ProjectCollectionProviderTest::testJackdawProjectAggregatesSplitRootStepsAndFallsBackToEstimates | rouge | rootProducts typeName `'Type #34828'` au lieu de `null` |

E14 : trois assertions `'Type #' . …` remplacées par `null`, et la phrase « type names resolve to "Type #<id>" » retirée du docblock. Aucune autre ligne n'a été modifiée.

Données distinctes par type : 4051 → 40 runs, ME 10, TE 20, achat ×400 à 15 000 ; 9848 → 5 ou 30 runs, ME 2/4, TE 4/8, achats ×10 à 1 000, ×12 à 1 100 et ×24 à 98 500 ; installation 12 400 contre 9 870.

## Suites complètes

- Unit : 2667 tests, 13 échecs, tous des rouges prévus. 1 incomplet préexistant (`InstallCostTest::testCopyingInstallCostWithSccSurcharge`, D11a). Les notices PHPUnit viennent des `createMock` sans attente dans les `setUp` existants (`IndustryCalculationTest`, `ProductionCostServiceTest`).
- Integration : 173 tests, 2 échecs, ceux de E14.
- Aucun test existant ne casse en dehors de E14.

## Points pour la phase verte

- `MatchProjectJobsCommand` (R5) n'a pas de test : seul E13 est spécifié.
- Assertions que la phase rouge ne peut pas atteindre, car placées après le premier échec : E10 (`linkedToStepId` = id de l'étape, `linkedToStepName` null, le job 4051 listé en second), E11 (prix), E12 (installCost par étape).

## Empreinte

sha256 du contenu concaténé des fichiers triés, tronqué à 12 (même méthode que les lignes DK et DH, vérifiée sur c6d90fa et a0bf579) : **53178c121543**

Par fichier :
cf06cb364142 tests/Integration/State/Provider/Industry/ProjectCollectionProviderTest.php
f9117dd774a3 tests/Unit/Command/RegenerateProjectStepsCommandTest.php
1eeab4faae49 tests/Unit/Service/Industry/IndustryCalculationTest.php
39c85fb59c6f tests/Unit/Service/Industry/ProductionCostServiceTest.php
7c132387fae6 tests/Unit/Service/Industry/ResolvesTypeNamesFromSde.php
98d670b6b011 tests/Unit/Service/TypeNameResolverTest.php
792ab2003718 tests/Unit/State/Provider/Industry/AvailableJobsProviderTest.php
c04f92332841 tests/Unit/State/Provider/Industry/IndustryResourceMapperTest.php
f8d815f8a4c4 tests/Unit/State/Provider/Industry/PurchaseSuggestionProviderTest.php

Commande : `cd <wt> && cat $( (git diff --name-only -- tests/; git ls-files --others --exclude-standard -- tests/) | sort) | sha256sum | cut -c1-12`

Commit proposé : `test(industry): specify that a type missing from the SDE has an unknown name instead of "Type #id"`

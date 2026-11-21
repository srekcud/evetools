# #1153 — Manufacturing level from the recipe, not from the first consumer

Issue: srekcud/evetools#1153 (circuit:m, group:production-tree). Related: #449 (levels), #418 (job list by level), #707.

## Problem

`ChainManufacturingLevels::byTypeId()` finds the children of a job through `IndustryProjectBomLine::$parentTypeId`, which holds the first consumer of the intermediate only (`IndustryProjectService::firstConsumerByMaterialOf()`, `??=`). An intermediate consumed by several jobs is a child of one of them only: Crystalline Carbonide (needs Crystallite Alloy and Carbon Polymers) falls to level 1 when Photonic Metamaterials, Fullerides or Pressurized Oxidizers come first in the plan. A bought intermediate has no job line, so it is never a child either.

## Rules

1. The level of a job type comes from the SDE recipe of its activity (manufacturing, activity 1, else reaction, activity 11, same preference as `IndustryActivityProductRepository::producerOf()`): level = 1 + the highest level among the recipe materials that are themselves produced by a manufacturing or reaction activity; a recipe without such material is level 1. Raw materials (no producer in the SDE) count as 0.
2. The level never reads `parentTypeId`, nor what the project builds, buys or stocks. Crystalline Carbonide is level 2 whether Crystallite Alloy and Carbon Polymers are built or bought.
3. The recursion follows the SDE producers whether or not the ingredient has a job line in the project; only the typeIds of job lines (component and final, copy and invention left out) are returned, as today.
4. Several lines of the same type share one level (unchanged). A manufacturing cycle in the SDE graph throws `UnexpectedValueException` (unchanged message).
5. `ChainManufacturingLevels::byTypeId(array $bomLines, array $producedIngredientsByTypeId)`: pure, the second argument maps a product typeId to the typeIds of its recipe materials that have a producer (`array<int, list<int>>`); a job typeId absent from the map is rejected with `UnexpectedValueException` (an unknown recipe is never level 1 by default).
6. New interface `ChainRecipeIngredients::producedIngredientsOf(list<int> $productTypeIds): array<int, list<int>>` and its SDE implementation `SdeChainRecipeIngredients`: breadth-first closure, one producer query and one materials query per depth step (no query per type). Every requested typeId is a key of the result (empty list for a raw material, or a type without producer); every producible ingredient met on the way is a key too.
7. `ProjectChainBuilder` and `IntermediateStockProposer` receive `ChainRecipeIngredients` by constructor and feed `byTypeId()` with the closure of the job typeIds of the BOM. `ChainMaterialSupply::allocate()` is unchanged (it takes the levels); its `firstConsumerLevel` and its job ordering follow the corrected levels.
8. Glossary entry « Niveau de fabrication » is rewritten (recipe composition, no `parentTypeId`); `docs/modules/industry.md` row follows.

## Examples

| Plan | Result |
|---|---|
| Crystalline Carbonide + Photonic Metamaterials, Crystallite Alloy and Carbon Polymers built, any job order | both composites 2, both intermediates 1 |
| Crystalline Carbonide, Crystallite Alloy and Carbon Polymers bought (no job line) | Crystalline Carbonide 2 |
| Product > component > reaction, all built | product 3, component 2, reaction 1 |
| Reaction whose recipe holds only raw materials | 1 |

## Edge cases

- A material with several possible producers: manufacturing wins over reaction; among blueprints of one activity the highest output quantity wins (as `findBlueprintForProduct`).
- Invention and copy lines keep no level (unchanged).

## Out of scope

- `parentTypeId` and `firstConsumerByMaterialOf()` stay as they are (the parent link keeps feeding the tree and the structure).
- No frontend change, no migration, no new endpoint.

## Open decisions

- None. Behaviour change to flag in the PR: levels of final and component jobs can rise when an ingredient is bought, by design of the issue.

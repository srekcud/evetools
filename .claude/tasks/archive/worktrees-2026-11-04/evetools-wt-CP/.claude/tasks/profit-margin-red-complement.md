# Profit Margins red phase, complement (plan steps 5 and 6, frontend contract)

Date: 2026-10-22. Only `tests/` and `frontend/tests/` were touched. Nothing in `src/`, nothing committed.

Commands:
- PHP: same `docker run` as `.claude/scripts/wt-php.sh unit` (worktree mounted on `/app`, main `vendor/` read-only, image `evetools-app-dev`), followed by `vendor/bin/phpunit --no-coverage <path>`. The script itself still refuses the worktree (SIGPIPE, rebase report Q7).
- Frontend: `docker run node:22-alpine npx vitest run <paths>`, with the worktree `frontend/` on `/app`, the `evetools_frontend_node_modules` volume read-only and a tmpfs on `node_modules/.vite-temp`.

Before writing anything here, every new PHP test and every migrated spec was run against throwaway implementations in `/tmp` (deleted afterwards). Result: `tests/Unit/Industry` 336 green and `frontend/tests/components/industry` 94 green. The expected values can be reached, and the stubs accept a reasonable implementation.

## A. Application (step 5)

### `tests/Unit/Industry/Application/Double/InMemoryRigCategories.php` (new)
Double of the `RigCategories` port, keyed by activity: `manufacturing: [productTypeId => category]`, `reaction: [...]`. An absent product, or any other activity, returns `null` (a group that no rig targets).

### `tests/Unit/Industry/Application/StructureMaterialModifiersTest.php`
| Test | Change | Red because |
|---|---|---|
| 5 golden cases, assigned structure, role bonus on a reaction, no structure, Fernite Carbide golden plan | 2nd constructor argument is now `InMemoryRigCategories` instead of the array | `TypeError: Argument #2 ($productCategories) must be of type array` |
| `testProductMissingFromTheCategoriesIsAnExplicitError` | **Removed**: with the port, `null` means "no rig targets the group", so there is nothing missing left to report | — |
| `testProductWhoseGroupNoRigTargetsGetsTheRoleBonusOnly` | Added: categories are empty, so Hail L gets 0.99 (Raitaru role bonus only, ammunition rig not applied) and Fernite Carbide gets 1.0 | same TypeError |
| `testCategoryIsLookedUpForTheActivityOfTheJob` | Added: Fernite Carbide is declared only under `manufacturing`, so its reaction job must get 1.0 (catches a lookup that passes the wrong activity) | same TypeError |

### `tests/Unit/Industry/Application/ProductionCostCalculatorTest.php`
| Test | Change | Red because |
|---|---|---|
| The 9 existing tests | `InMemoryRigCategories` passed as 6th constructor argument (Hail L: `HAIL_L => 'ammunition'`; Hobgoblin: empty); `productCategories:` removed from the requests | `ArgumentCountError: ProductionCostRequest::__construct(): Argument #7 ($productCategories) not passed` |
| `testMissingInventionProbabilityMakesTheT2CostUnknown` | Adds: `invention->chance` is null, `outcome` is known (ME 2, TE 4, 10 runs) | same |
| `testInventedTargetIsBuiltAtTheMaterialEfficiencyOfTheInventedBlueprintCopyNotOfTheRequest` (#143) | New. Process decryptor (x1.1, runs +0, ME +3, TE +6), request at ME 2. Expected ME 5: Tritanium 19 000 (not 19 600), Morphite 95 (not 98), materials 931 000, installation 70 950, decryptor 50 000, attempts 1.956436677, invention 645 990.30, total 1 647 940.30, 164 794.029933 per unit | same; with the constructor fixed: `19600.0 vs 19000.0` |
| `testInventedTargetWithoutDecryptorIsBuiltAtMaterialEfficiencyTwoEvenWhenTheRequestAsksTen` | New. Request at ME 10, no decryptor, so ME 2: Tritanium 19 600, Morphite 98, materials 960 400 (882 000 at ME 10), total 1 634 335.31 | same; with the constructor fixed: `18000.0 vs 19600.0` |
| `testInventionCostExposesItsRecipeChanceAndInventedBlueprintCopy` | New. `recipe` equals the catalog `InventionRecipe`; `chance->value` 0.511133333; `outcome`: 10 runs, ME 5, TE 10 | same |

Not added: `ProductionCost::$plan` (rank 2).

Note: the brief's audit demo ("213 at ME 5, not 220") uses a recipe this test does not have. The same rule is shown here on the synthetic Hobgoblin II (2 000 Tritanium per run).

## B. Adapters (step 6), `tests/Unit/Industry/Infrastructure/`

The constructor signatures are set by the tests (positional order):
- `SdeDecryptors(InvTypeRepository, DogmaAttributeValues)`
- `SdeRigCategories(IndustryRigCategoryRepository, IndustryActivityProductRepository, InvTypeRepository)`
- `ConfiguredProductionStructures(IndustryStructureConfigRepository, InvTypeRepository, SdeStructureBonusReader, SdeRigBonusReader)` (namespace `App\Industry\Infrastructure\Doctrine`)

The repositories are stubbed at the methods named in the plan, and each stub filters the way the database would:
- `InvTypeRepository::findBy(['group' => 1304], orderBy)`; any other criterion fails the test.
- `DgmTypeAttributeRepository::findBy(['typeId', 'attributeId'])`.
- `IndustryStructureConfigRepository::findBy` filters on `user` (string id, `Uuid` or `User`) and `isDeleted`; any other criterion throws `LogicException`.
- `InvTypeRepository::findByNames`, `InvTypeRepository::find`, `IndustryActivityProductRepository::findBlueprintForProduct($typeId, 11)`, `IndustryRigCategoryRepository::buildGroupCategoryMap()`.

Dogma values come from the dev database (2026-10-22), keeping the `value_int` / `value_float` split.

### `Sde/SdeDecryptorsTest.php` (new, 3 tests)
- `testAllAreTheEightDecryptorsOfTheSdeWithTheirModifiersSortedByTypeId`: the stub returns the decryptors in descending typeId order plus Tritanium (group 18). The test expects exactly 8 `Decryptor` objects, 34201 to 34208, built from 1112, 1124, 1113 and 1114. Symmetry's multiplier is stored as `value_int` 1.
- `testDecryptorWithoutItsRunModifierIsAnIncompleteSdeNeverZeroRuns`: 1124 removed from Parity, so `UnexpectedValueException` is expected.
- `testDecryptorWithoutItsProbabilityMultiplierIsAnIncompleteSde`: 1112 removed from Parity, so `UnexpectedValueException` is expected.

All 3 are red with `Class "App\Industry\Infrastructure\Sde\SdeDecryptors" not found`. The two exception tests fail with "exception of type Error".

### `Sde/SdeRigCategoriesTest.php` (new, 4 tests)
- Manufacturing: Hail L, group 372, gives `ammunition`.
- Reaction: Fernite Carbide gives the category of its formula's group (46206, group 1888), `composite_reaction`, never the one of its own group 429. Group 429 carries the decoy category `equipment`.
- R.A.M., group 332, is not mapped, so the result is `null`.
- `buildGroupCategoryMap()` is called once (a mock with `once()`) across three lookups.

All 4 are red with "class not found".

### `Doctrine/ConfiguredProductionStructuresTest.php` (new, 22 runs)
- Structure types: raitaru, azbel and sotiyo map to `EngineeringComplex` with (0.99, 0.97 / 0.96 / 0.95); athanor and tatara map to `Refinery` with `Multiplier::one()` for both role bonuses.
- Security: highsec, lowsec and nullsec map to `SecurityClass`.
- `id` is `toRfc4122()`; `solarSystemId` is kept; `configuredAt` equals `createdAt` (set by reflection).
- Tax: 3.5 becomes 0.035; null becomes 0.0 (D14).
- `station` is excluded.
- `engineering_complex` and `refinery` throw `UnexpectedValueException`; so does the security value `wormhole`.
- Rigs:
  - Ammunition ME II on a nullsec Raitaru: 0.940104 on ammunition, 0.99 on equipment.
  - Composite Reactor ME II on a Tatara: 0.9736.
  - Time rigs (Equipment TE I with 2594 = 0; Composite Reactor TE I without 2714) give no material bonus.
  - XL Thukker on a lowsec Sotiyo: 2 rigs; 0.920403 on basic_capital_component (never both bonuses), 0.95238 on structure, structure_component and advanced_component, 0.99 on capital_ship.
  - M-Set Thukker (no 2594): 1 rig.
- Scope: only the user's non-deleted configurations are read; a user with none gets `[]`.

All 22 are red with "class not found". The 3 exception tests fail with "exception of type Error".

## C. Frontend specs (`frontend/tests/components/industry/`)

New contract in the fixtures:
- `missingData: {reason, typeId}[]` at the top level, and `missingData` on each `jobInstallSteps[]` instead of `unknownReason`.
- `copyCost`, `missingPriceTypeIds` and `unknownInstallCostReasons` are gone.
- `invention.unknownReason` stays.
- `inventionCost` now includes the T1 copies (25 000 + 1 000 becomes 26 000, equal to the "No Decryptor" option).

The `ProfitMarginResult` / `ProfitMarginJobStep` types in `src/stores/industry/types.ts` are not updated yet, so `vue-tsc` will flag these fixtures until the green phase.

| Spec | Changes | Result |
|---|---|---|
| `profitMarginTabUnknownMaterialPrice.spec.ts` | Fixture only: `missingData` market_price for Robotics and Nitrogen Isotopes; no copyCost; inventionCost 26 000 | 8 red: `TypeError: result.value.unknownInstallCostReasons is not iterable` (the component reads a removed field) |
| `profitMarginTabUnknownJobInstallCost.spec.ts` | Steps carry `missingData: [{cost_index, null}]` or `[{adjusted_price, 4246}]` or `[]`; global missingData replaces the reasons; no copyCost | 9 red: `TypeError: Cannot read properties of undefined (reading 'length')`; 2 green (negative assertions: no reason shown when everything is figured) |
| `profitMarginTabSelectedDecryptorFees.spec.ts` | Fixture migrated; new test `shows the invention cost of the analysis as is, the T1 copies being part of each invention attempt` (cost summary "26.00 K" with no decryptor selected) | New test red: `expected 'NaN' to be '26.00 K'` (inventionCost + undefined copyCost); the 2 existing tests green |
| `inventionMissingMarketPrice.spec.ts` | Unchanged: it mounts `InventionOptionsPanel` with props only (`unknownReason` kept, nullable amounts already there) and reads no removed field | 6 green |

No other spec reads Profit Margins. The buyVsBuild, pivotAdvisor and bpcKit specs use the same field names but for other screens (rank 2), so they were left as they are.

## Findings for Sylvain

1. **Reaction rigs probably never apply (existing data issue, out of scope).** In the dev database, `industry_rig_categories` maps the `*_reaction` categories to groups 436 / 484 / 661 / 662 / 977 (reaction types, category 24). The formula groups 1888 / 1889 / 1890 (category 9) have no row. The plan's rule (category of the formula group, the same as `IndustryBonusService::getCategoryForProduct()`) therefore returns `null` for every reaction, and the old code does the same. The test uses a synthetic mapping 1888 → `composite_reaction`. To fix in `SeedIndustryRigCategoriesCommand` (data), or by changing the rule. [TO CONFIRM in prod]
2. In the real SDE, a time rig has 2594 = 0 (`value_int`), not an absent attribute, so `SdeRigBonusReader::materialBonusOf()` returns `RigBonus(0)`, not `null`. The test checks the neutral multiplier, not the absence of the rig.
3. Not specified here, left to the implementer or to a decision: a rig name unknown to the SDE; a reaction product with no formula; a product missing from `sde_inv_types` in `SdeRigCategories`.

## Fingerprints (`sha256sum | cut -c1-12`)

- `tests/Unit/Industry/Application/ProductionCostCalculatorTest.php`: 145d87e0be74
- `tests/Unit/Industry/Application/StructureMaterialModifiersTest.php`: 8bb0ee27e7ae
- `tests/Unit/Industry/Application/Double/InMemoryRigCategories.php`: c5f6732a3396
- `tests/Unit/Industry/Infrastructure/Sde/SdeDecryptorsTest.php`: 5934f2923992
- `tests/Unit/Industry/Infrastructure/Sde/SdeRigCategoriesTest.php`: a4bf186ec663
- `tests/Unit/Industry/Infrastructure/Doctrine/ConfiguredProductionStructuresTest.php`: a810ef40ab42
- `frontend/tests/components/industry/profitMarginTabUnknownMaterialPrice.spec.ts`: 108a65372d5a
- `frontend/tests/components/industry/profitMarginTabUnknownJobInstallCost.spec.ts`: 4eec62364872
- `frontend/tests/components/industry/profitMarginTabSelectedDecryptorFees.spec.ts`: 5370c62a14e7

# Industry Module - Code Quality Audit (4 Rules of Simple Design)

**Date**: 2026-02-24
**Scope**: Backend services, providers, processors, API resources, tests, frontend components/stores
**Status**: Read-only audit -- no modifications

---

## Summary

| Rule | Status | Issues Found |
|------|--------|--------------|
| Rule 1: Passes the Tests | WARNING | 6 services without any tests |
| Rule 2: Reveals Intention | GOOD | Minor magic number issues |
| Rule 3: No Duplication | CRITICAL | 5 major duplication patterns |
| Rule 4: Fewest Elements | GOOD | Minor dead code |

---

## 1. Rule 1: Passes the Tests (Highest Priority)

### Services WITH Tests (12/18)

| Service | Test File | Test Count | Coverage Quality |
|---------|-----------|------------|-----------------|
| IndustryBonusService | IndustryBonusServiceTest.php | 22 | Excellent |
| IndustryCalculationService | IndustryCalculationTest.php | 22 | Excellent |
| IndustryTreeService | IndustryTreeServiceTest.php | 11 | Good |
| InventionService | InventionServiceTest.php | 12 | Good |
| ProductionCostService | ProductionCostServiceTest.php | 11 | Good |
| ProfitMarginService | ProfitMarginServiceTest.php | 13 | Good |
| BatchProfitScannerService | BatchProfitScannerServiceTest.php | 11 | Good |
| BuyVsBuildService | BuyVsBuildServiceTest.php | 5 | Adequate |
| PivotAdvisorService | PivotAdvisorServiceTest.php | 6 | Adequate |
| StockpileService | StockpileServiceTest.php | 5 | Adequate |
| SlotTrackerService | SlotTrackerServiceTest.php | 21 | Excellent |
| (job split logic) | JobSplitCalculationTest.php | 12 | Good |

### Services WITHOUT Tests (6/18) -- Priority Order

#### P1 (CRITICAL -- complex business logic, high bug risk)

**1. IndustryProjectFactory** (`src/Service/Industry/IndustryProjectFactory.php`, 513 lines)
- Complexity: HIGH. Orchestrates project creation with tree traversal, step consolidation, reaction recalculation, time calculation, skill multipliers, and job splitting.
- Critical methods without tests:
  - `createProject()` -- full orchestration
  - `collectStepsFromTree()` -- step consolidation with ME/TE assignment
  - `recalculateReactionQuantities()` -- recalculates reaction needs based on consumer steps (lines 253-337, 84 lines of complex nested loops with material lookups and bonus calculations)
  - `addTimeDataToSteps()` -- time computation with skill multipliers
  - `findBestSkillMultiplierForBlueprint()` -- duplicates skill logic from IndustryCalculationService
- Risk: This is the most complex untested service. Bugs here silently corrupt project step data.

**2. IndustryStepCalculator** (`src/Service/Industry/IndustryStepCalculator.php`, 172 lines)
- Complexity: MEDIUM-HIGH. Recalculates step quantities depth-by-depth with split group redistribution.
- Critical methods without tests:
  - `recalculateStepQuantities()` -- the only public method; depth-by-depth propagation + proportional split redistribution
- Risk: Called by IndustryJobMatcher on every auto-match. Incorrect recalculation corrupts entire project.

**3. IndustryShoppingListBuilder** (`src/Service/Industry/IndustryShoppingListBuilder.php`, 223 lines)
- Complexity: MEDIUM-HIGH. Builds shopping lists with in-stock deduction, purchased item handling, suboptimal structure delta calculation.
- Critical methods without tests:
  - `getShoppingList()` -- primary method with two tree traversals (optimal vs actual) for delta computation
  - `collectRawMaterials()` -- recursive with mutable stock deduction
- Risk: Incorrect shopping list means players buy wrong quantities.

#### P2 (MODERATE -- simpler logic or lower change frequency)

**4. IndustryJobMatcher** (`src/Service/Industry/IndustryJobMatcher.php`, 224 lines)
- Complexity: MEDIUM. Greedy matching algorithm with ESI job lookup, facility auto-correction.
- Critical methods without tests:
  - `matchEsiJobs()` -- greedy matching with cross-project deduplication
  - `createJobMatch()` -- facility auto-correction with structure config swap
- Risk: Bad matching links wrong jobs to steps.

**5. IndustryBlacklistService** (`src/Service/Industry/IndustryBlacklistService.php`, 147 lines)
- Complexity: LOW. Simple group-to-type resolution and category management.
- Methods: `resolveBlacklistedTypeIds()`, `getCategories()`, `getBlacklistedItems()`
- Risk: Low -- straightforward queries. Used by many services as input.

**6. EsiCostIndexService** (`src/Service/Industry/EsiCostIndexService.php`, 273 lines)
- Complexity: LOW-MEDIUM. Cache read/write + EIV/install cost calculation.
- The calculation methods (`calculateEiv`, `calculateJobInstallCost`) are pure formulas -- good candidates for unit tests.
- `syncAdjustedPrices()` and `syncCostIndices()` involve HTTP calls -- would need integration tests or mocking.
- Risk: Formula errors silently affect cost estimates across all modules (ProductionCost, ProfitMargin, BatchScan, BuyVsBuild, PivotAdvisor).

**7. PublicContractPriceService** (`src/Service/Industry/PublicContractPriceService.php`, 66 lines)
- Complexity: VERY LOW. Simple cache reads. Lowest priority for testing.

---

## 2. Rule 2: Reveals Intention

### 2.1 Magic Numbers

**Skill Bonus Multipliers** -- duplicated across two files with unexplained constants:

File: `src/Service/Industry/IndustryProjectFactory.php`, lines 438-451
File: `src/Service/Industry/IndustryCalculationService.php`, lines 257-271

```php
$multiplier *= (1 - 0.04 * $reactionLevel);     // What is 0.04?
$multiplier *= (1 - 0.04 * $industryLevel);      // What is 0.04?
$multiplier *= (1 - 0.03 * $advancedLevel);      // What is 0.03?
$multiplier *= (1 - 0.01 * $level);              // What is 0.01?
```

These are EVE Online skill time reduction percentages. They should be named constants in `CachedCharacterSkill` or a dedicated constants class:
- `INDUSTRY_TIME_BONUS_PER_LEVEL = 0.04` (4% per level)
- `ADVANCED_INDUSTRY_TIME_BONUS_PER_LEVEL = 0.03` (3% per level)
- `REACTIONS_TIME_BONUS_PER_LEVEL = 0.04` (4% per level)
- `SCIENCE_SKILL_TIME_BONUS_PER_LEVEL = 0.01` (1% per level)

**ME/TE Defaults**:

File: `src/Service/Industry/IndustryProjectFactory.php`, line 166-167
```php
$step->setMeLevel($data['meLevel'] ?? ($data['depth'] === 0 ? $project->getMeLevel() : 10));
$step->setTeLevel($data['teLevel'] ?? ($data['depth'] === 0 ? $project->getTeLevel() : 20));
```

The values `10` and `20` are EVE conventions (max ME/TE for intermediate components) already defined as `DEFAULT_INTERMEDIATE_TE = 20` in `IndustryBonusService` but ME 10 has no named constant.

**Batch Scanner ME defaults** (file: `BatchProfitScannerService.php`, line 227):
```php
$me = $isT2 ? 2 : ($isReaction ? 0 : 10);
```
Same pattern repeated in `PivotAdvisorService.php` line 160 and `BuyVsBuildService.php` line 293. The `2` is the T2 base invention ME -- should be `InventionService::BASE_INVENTION_ME`.

**NPC Station Threshold** (file: `IndustryCalculationService.php`, line 45):
```php
if ($stationId < 1_000_000_000) {
```
The magic number `1_000_000_000` is the boundary between NPC station IDs and player structure IDs. Should be a named constant in `EveConstants`.

### 2.2 Functions Doing Multiple Things

**`IndustryProjectFactory::createProject()`** (lines 44-75): Creates a project AND generates all steps. The step generation (tree build, collect, recalculate, time, split, sort, persist) is correctly extracted to helper methods, but the orchestration could be clearer with a dedicated step-generation pipeline.

**`PivotAdvisorService::analyze()`** (lines 46-351, 305 lines): This is the longest method in the module. It does 12 numbered steps in sequence. While each step is logically distinct, the method is hard to follow. Consider extracting scoring logic (step 10) into a separate method.

### 2.3 Variable Naming Issues

Minor issues only:
- `$cp` / `$cpTypeId` / `$cpBpId` in PivotAdvisorService -- could be `$candidate` / `$candidateTypeId` etc.
- `$mat` used for both "material" objects and array items across the codebase -- acceptable but inconsistent between `$material` (full) and `$mat` (short).

---

## 3. Rule 3: No Duplication (CRITICAL)

### 3.1 `identifyT2Products()` -- EXACT DUPLICATE

**Files**:
- `src/Service/Industry/BatchProfitScannerService.php`, lines 327-369
- `src/Service/Industry/PivotAdvisorService.php`, lines 357-396

These two methods are **character-for-character identical**. Both:
1. Filter manufacturing blueprint IDs
2. Build a blueprint-to-product map
3. Execute the same SQL query against `sde_industry_activity_products` with `activity_id = 8`
4. Map results back to product type IDs

**Fix**: Extract to a shared service (e.g., `IndustryTypeClassifier::identifyT2Products()`), or add it to `IndustryCalculationService`.

### 3.2 `addToMaterialList()` -- EXACT DUPLICATE

**Files**:
- `src/Service/Industry/IndustryShoppingListBuilder.php`, lines 209-222
- `src/Service/Industry/ProfitMarginService.php`, lines 268-281

Identical logic: scan an array for a matching `typeId`, sum quantities if found, otherwise append.

**Fix**: Extract to a trait, utility class, or make it a static method on a shared helper.

### 3.3 Skill Time Multiplier Calculation -- SEMANTIC DUPLICATE

**Files**:
- `src/Service/Industry/IndustryProjectFactory.php`, `findBestSkillMultiplierForBlueprint()` lines 425-461
- `src/Service/Industry/IndustryCalculationService.php`, `calculateTimePerRun()` lines 241-277

Both compute the same skill-based time multiplier using Industry (4%), Advanced Industry (3%), Reactions (4%), and per-blueprint science skills (1%). The factory version iterates across all characters to find the best; the calculation service version takes a single character's skills. The core formula is identical.

**Fix**: Extract the per-character multiplier calculation into a shared method (e.g., `IndustryCalculationService::calculateSkillTimeMultiplier(array $skills, int $blueprintTypeId, string $activityType): float`), then the factory calls it in a loop.

### 3.4 Leaf Material Collection -- STRUCTURAL DUPLICATE

**Files**:
- `src/Service/Industry/ProfitMarginService.php`, `collectLeafMaterials()` lines 254-263
- `src/Service/Industry/BuyVsBuildService.php`, `collectLeafMaterialsFromTree()` lines 321-333
- `src/Service/Industry/IndustryShoppingListBuilder.php`, `collectRawMaterials()` lines 179-206

All three recursively traverse the production tree to collect non-buildable (leaf) materials. The accumulation differs slightly (array-of-arrays vs typeId-keyed map), but the tree traversal pattern and `isBuildable` check are the same.

**Fix**: Extract the tree-walking logic to `IndustryTreeService` with a callback/visitor pattern, or provide a `collectLeafMaterials()` method that returns a normalized format.

### 3.5 Structure Compatibility Guard -- REPEATED PATTERN (6 occurrences)

**File**: `src/Service/Industry/IndustryBonusService.php`

The pattern "skip refineries for manufacturing, skip ECs for reactions" appears 3 times with identical code:
```php
if ($isReaction && $structureCategory !== 'refinery') { continue; }
if (!$isReaction && $structureCategory === 'refinery') { continue; }
```

Lines: 163-166, 246-249, 410-413

**Fix**: Extract to a private method `isStructureCompatible(string $structureCategory, bool $isReaction): bool`.

### 3.6 `resolveSolarSystem` / `resolveFacilityTaxRate` -- SIMILAR LOGIC IN 3 SERVICES

**Files**:
- `src/Service/Industry/InventionService.php`: `resolveSolarSystemForProject()` (line 501), `resolveFacilityTaxRate()` (line 519)
- `src/Service/Industry/ProductionCostService.php`: `resolveSolarSystemForStep()` (line 166)
- `src/Service/Industry/ProfitMarginService.php`: `resolveFacilityTaxRate()` (line 472)

Each resolves solar system / tax rate from project steps or user structures with slightly different fallback chains. This is a natural candidate for a shared `ProjectContextResolver` service.

### 3.7 `resolveTypeName()` Wrapper -- TRIVIAL DUPLICATE (3 files)

**Files**:
- `src/Service/Industry/InventionService.php`, line 552
- `src/Service/Industry/ProfitMarginService.php`, line 503
- `src/Service/Industry/IndustryCalculationService.php`, line 55

All are one-line wrappers around `TypeNameResolver::resolve()`. While minor, each service independently injects `TypeNameResolver` just to delegate. Consider removing these wrappers and injecting `TypeNameResolver` directly where needed, or having one service expose it.

---

## 4. Rule 4: Fewest Elements

### 4.1 Massive Rig Options Array (IndustryBonusService)

`IndustryBonusService::getRigOptions()` (lines 617-733) is a 116-line static array embedded in the service class. This data should arguably live in a configuration file (YAML/JSON), a database seed, or a dedicated `RigOptionsProvider`. The service rebuilds lookup maps from this array in the constructor on every request.

However, this is also the **single source of truth** for rig data, and it changes rarely (EVE patches). Moving it would add complexity without clear benefit. **Borderline -- acceptable as-is for now.**

### 4.2 Ship Packaged Volumes (BatchProfitScannerService)

`SHIP_PACKAGED_VOLUMES` constant (lines 64-110) is a 46-line lookup table. Same consideration as rig options -- static game data, rarely changes. **Acceptable.**

### 4.3 Unused Parameter

`IndustryStepCalculator::__construct()` injects `IndustryActivityProductRepository` which is also used (via `findOneBy`), so this is NOT unused. No dead parameters found.

### 4.4 `PivotAdvisorService::isT2Product()` vs `InventionService::isT2()`

Both check if a product is T2, but via different mechanisms:
- `InventionService::isT2()` checks the full invention chain
- `PivotAdvisorService::isT2Product()` checks just the blueprint via direct SQL

The PivotAdvisor version takes a `$blueprintTypeId` parameter that is NOT used in the SQL (line 408: `WHERE activity_id = 8 AND product_type_id = ?` with `[$blueprintTypeId]`). This parameter seems misnamed -- it queries by blueprint type ID in the `product_type_id` column, which is actually correct because in the invention chain, the T2 blueprint IS the "product" of the invention activity. The parameter name `$blueprintTypeId` matches the intent. However, the `$productTypeId` parameter is declared but never referenced in the method body (it is in the signature on line 401 but the SQL on line 408 only uses `$blueprintTypeId`). This is a **dead parameter**.

### 4.5 Frontend Store Barrel (`index.ts`)

`frontend/src/stores/industry/index.ts` re-exports all stores and all types (83 lines). This is a good organizational pattern -- not an issue.

---

## 5. Plan d'Action Priorite (Top 10)

### Tier 1: Tests for High-Risk Untested Services

| # | Action | File(s) | Impact | Effort |
|---|--------|---------|--------|--------|
| 1 | **Write tests for IndustryProjectFactory** | `tests/Unit/Service/Industry/IndustryProjectFactoryTest.php` | CRITICAL -- most complex untested service, orchestrates project creation. Focus on: `collectStepsFromTree()`, `recalculateReactionQuantities()`, `findBestSkillMultiplierForBlueprint()` | HIGH (8-10 tests, heavy mocking) |
| 2 | **Write tests for IndustryStepCalculator** | `tests/Unit/Service/Industry/IndustryStepCalculatorTest.php` | HIGH -- called on every job match, silently corrupts projects if broken | MEDIUM (5-6 tests) |
| 3 | **Write tests for IndustryShoppingListBuilder** | `tests/Unit/Service/Industry/IndustryShoppingListBuilderTest.php` | HIGH -- incorrect shopping lists = players buy wrong quantities | MEDIUM (5-6 tests) |
| 4 | **Write tests for EsiCostIndexService** | `tests/Unit/Service/Industry/EsiCostIndexServiceTest.php` | MEDIUM -- pure formula tests for `calculateEiv()` and `calculateJobInstallCost()`. Quick wins. | LOW (3-4 tests) |

### Tier 2: Eliminate Critical Duplication

| # | Action | File(s) | Impact | Effort |
|---|--------|---------|--------|--------|
| 5 | **Extract `identifyT2Products()` to shared service** | Create utility in `IndustryCalculationService` or new `IndustryTypeClassifier`, update `BatchProfitScannerService` + `PivotAdvisorService` | Eliminates 40 lines of exact duplication | LOW |
| 6 | **Extract skill time multiplier to shared method** | Add `calculateSkillTimeMultiplier()` to `IndustryCalculationService`, update `IndustryProjectFactory` | Eliminates 30 lines of semantic duplication, centralizes EVE skill formula | LOW |
| 7 | **Extract `addToMaterialList()` to shared utility** | Create `MaterialListHelper::addOrMerge()` or trait, update `IndustryShoppingListBuilder` + `ProfitMarginService` | Eliminates 13 lines of exact duplication | VERY LOW |
| 8 | **Extract leaf material collection to `IndustryTreeService`** | Add `collectLeafMaterials()` to `IndustryTreeService`, update `ProfitMarginService` + `BuyVsBuildService` + `IndustryShoppingListBuilder` | Eliminates 40+ lines of structural duplication, centralizes tree traversal | MEDIUM |

### Tier 3: Minor Improvements

| # | Action | File(s) | Impact | Effort |
|---|--------|---------|--------|--------|
| 9 | **Extract magic numbers to named constants** | `CachedCharacterSkill` (skill bonuses), `EveConstants` (station ID threshold, default ME/TE), `InventionService` (use `BASE_INVENTION_ME` in batch/pivot) | Improves readability, prevents inconsistencies | VERY LOW |
| 10 | **Extract structure compatibility guard** | `IndustryBonusService::isStructureCompatible()` | Eliminates 6 duplicated condition blocks | VERY LOW |

---

## Appendix A: File-by-File Assessment

### Backend Services (18 files)

| File | Lines | Has Tests | Rule 2 | Rule 3 | Rule 4 |
|------|-------|-----------|--------|--------|--------|
| IndustryBonusService.php | 735 | YES (22) | OK | Structure guard x3 | Rig data 116 lines |
| IndustryCalculationService.php | 359 | YES (22) | Magic: 0.04/0.03/0.01 | Skill calc duplicate | OK |
| IndustryTreeService.php | 220 | YES (11) | OK | OK | OK |
| InventionService.php | 556 | YES (12) | OK | resolveSolarSystem dup | resolveTypeName wrapper |
| ProductionCostService.php | 216 | YES (11) | OK | resolveSolarSystem dup | OK |
| ProfitMarginService.php | 514 | YES (13) | OK | addToMaterialList, collectLeaf, resolveTax dup | resolveTypeName wrapper |
| BatchProfitScannerService.php | 561 | YES (11) | ME magic (2/0/10) | identifyT2Products dup | Ship volumes 46 lines |
| BuyVsBuildService.php | 354 | YES (5) | OK | collectLeafMaterials dup | OK |
| PivotAdvisorService.php | 613 | YES (6) | $cp naming | identifyT2Products dup, loadGroupNames | Dead param isT2Product |
| StockpileService.php | 399 | YES (5) | OK | OK | OK |
| SlotTrackerService.php | 338 | YES (21) | OK | OK | OK |
| IndustryProjectFactory.php | 513 | **NO** | Magic: 0.04/0.03/0.01, ME 10/TE 20 | Skill calc duplicate | OK |
| IndustryStepCalculator.php | 172 | **NO** | OK | OK | OK |
| IndustryShoppingListBuilder.php | 223 | **NO** | OK | addToMaterialList dup | OK |
| IndustryJobMatcher.php | 224 | **NO** | OK | OK | OK |
| IndustryBlacklistService.php | 147 | **NO** | OK | OK | OK |
| EsiCostIndexService.php | 273 | **NO** | OK | OK | OK |
| PublicContractPriceService.php | 66 | **NO** | OK | OK | OK |

### Frontend Components (25 .vue files, 13,972 total lines)

| Component | Lines | Notes |
|-----------|-------|-------|
| StructureConfig.vue | 968 | Largest component. Consider splitting rig picker into sub-component |
| StepTree.vue | 935 | Complex tree rendering. Acceptable for its purpose |
| ProfitMarginTab.vue | 782 | Large but well-structured single-purpose tab |
| BuyVsBuildTab.vue | 757 | Large but single-purpose |
| IndustrySlotsSection.vue | 698 | Large. Timeline could be extracted |
| IndustryDashboard.vue | 670 | Orchestrator component, acceptable size |
| Industry.vue (view) | 659 | Tab routing view, acceptable |
| ProjectTable.vue | 650 | Complex project list with filters |
| BatchScanTab.vue | 607 | Single-purpose scan results table |
| ShoppingTab.vue | 606 | Complex shopping list with comparisons |
| ProjectDetail.vue | 604 | Project detail view |
| CostEstimationTab.vue | 590 | Cost breakdown display |
| PivotAdvisorTab.vue | 570 | Pivot analysis results |

Frontend components are generally well-structured with clear single responsibilities. No major duplication detected between components. All use `useFormatters()` consistently for ISK/number formatting.

### Frontend Stores (11 .ts files)

The stores are well-split by domain concern (projects, steps, structures, blacklist, purchases, scanner, stockpile, slots) with a clean barrel export and comprehensive type definitions (745 lines in types.ts). The type definitions use `type` over `interface` for most new additions, following project conventions.

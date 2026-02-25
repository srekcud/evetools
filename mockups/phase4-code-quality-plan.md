# Phase 4 -- Code Quality Plan

## Audit Summary

### 4A. Test Coverage

**49 services total, 25 have tests** (via 25 test files in `tests/Unit/Service/`).

**24 services WITHOUT any tests:**

| # | Service | Lines | Criticality | Rationale |
|---|---------|-------|-------------|-----------|
| 1 | `Sync/MiningSyncService` | 333 | HIGH | User-facing sync, ESI integration, Mercure notifications |
| 2 | `Sync/WalletTransactionSyncService` | 139 | HIGH | User-facing sync, N+1 inside loop (findByTransactionId), pagination logic |
| 3 | `Sync/PlanetarySyncService` | 365 | HIGH | User-facing sync, complex colony data mapping |
| 4 | `Sync/AnsiblexSyncService` | 645 | MEDIUM | Long but used infrequently (12h interval) |
| 5 | `Mercure/MercurePublisherService` | 272 | HIGH | Central infrastructure, all modules depend on it |
| 6 | `Industry/IndustryShoppingListBuilder` | 223 | MEDIUM | Pure computation, easy to test |
| 7 | `Industry/IndustryJobMatcher` | 224 | MEDIUM | Business logic with step matching |
| 8 | `Industry/IndustryBlacklistService` | 147 | LOW | Simple CRUD wrapper |
| 9 | `Industry/PublicContractPriceService` | 66 | LOW | Thin service |
| 10 | `ESI/EsiClient` | 433 | MEDIUM | HTTP wrapper, hard to unit test (integration test candidate) |
| 11 | `ESI/AssetsService` | 439 | MEDIUM | ESI data transformation |
| 12 | `ESI/AuthenticationService` | 303 | MEDIUM | OAuth flow, security-critical |
| 13 | `ESI/MarketService` | 299 | MEDIUM | ESI market data fetching |
| 14 | `ESI/CharacterService` | 94 | LOW | Thin ESI wrapper |
| 15 | `ESI/CorporationService` | 73 | LOW | Thin ESI wrapper |
| 16 | `ESI/PlanetaryService` | 62 | LOW | Thin ESI wrapper |
| 17 | `ESI/OpenWindowService` | 30 | LOW | Trivial |
| 18 | `Sde/SdeImportService` | 1448 | LOW | Runs rarely (manual), low regression risk |
| 19 | `StructureMarketService` | 470 | MEDIUM | Market data for null-sec structures |
| 20 | `StructureMarketSnapshotService` | 74 | LOW | Simple snapshot logic |
| 21 | `OreValueService` | 291 | MEDIUM | Mining calculations, reprocessing |
| 22 | `MiningBestValueCalculator` | 187 | MEDIUM | Mining decision logic |
| 23 | `ItemParserService` | 156 | MEDIUM | Text parsing, excellent test candidate |
| 24 | `Notification/WebPushService` | 105 | LOW | External push API wrapper |

**Existing tests with coverage gaps:**

| Service | Tests | Missing edge cases |
|---------|-------|--------------------|
| `BatchProfitScannerService` | 10 tests | Structure sell price mode, T2 invention cost integration, empty material list, multiple output per run |
| `StockpileService` | 9 tests | `previewImport()` not tested, empty targets, zero quantity targets |
| `BuyVsBuildService` | 5 tests | Structure price lower than Jita, reaction components, blacklisted items |

---

### 4B. Oversized Services

| Service | Lines | Proposed split |
|---------|-------|----------------|
| `Sde/SdeImportService` | 1448 | 5 domain importers + orchestrator |
| `JitaMarketService` | 1148 | 3 focused services |

**SdeImportService breakdown** (by method groups):

- **SdeDownloader** (~90 lines): `downloadSde()`, `ensureTempDir()`, `cleanup()`, `readJsonlFile()`, `getName()`, `getString()`, `getDescription()`
- **SdeInventoryImporter** (~210 lines): `importCategories()`, `importGroups()`, `importMarketGroups()`, `importTypes()`, `importTypeMaterials()`
- **SdeMapImporter** (~190 lines): `importRegions()`, `importConstellations()`, `importSolarSystems()`, `importStations()`, `importStargates()`
- **SdeBlueprintImporter** (~100 lines): `importBlueprints()` (single method, but 100 lines of complex logic)
- **SdeDogmaImporter** (~175 lines): `importAttributeTypes()`, `importTypeAttributes()`, `importEffects()`, `importTypeEffects()`
- **SdeReferenceImporter** (~200 lines): `importRaces()`, `importFactions()`, `importFlags()`, `importIcons()`
- **SdePlanetaryImporter** (~55 lines): `importPlanetSchematics()`
- **SdeImportService** (orchestrator, ~140 lines): `downloadAndImport()` delegates to domain importers

**JitaMarketService breakdown** (by responsibility):

- **MarketCacheService** (~200 lines): `getPrice()`, `getPrices()`, `getBuyPrice()`, `getBuyPrices()`, `hasCachedData()`, `getLastSyncTime()`, `getOrderBook()`, `getSellOrders()`, `getBuyOrders()`
- **MarketPriceCalculator** (~150 lines): `getWeightedSellPrice()`, `getWeightedBuyPrice()`, `getWeightedSellPrices()`, `getWeightedBuyPrices()`, `calculateWeightedPrice()`, `collectOrderBooks()`
- **EsiMarketFetcher** (~250 lines): `syncJitaMarket()`, `refreshPricesForTypes()`, `fetchPricesInBatches()`, `fetchOnDemandOrderBooks()`
- **MarketVolumeService** (~150 lines): `getCachedDailyVolumes()`, `getCachedDailyVolumesForRegion()`, `getAverageDailyVolumes()`, `getAverageDailyVolumesForRegion()`, `fetchDailyVolumesForRegion()`, `computeAverageDailyVolume()`
- **JitaMarketService** (facade, ~200 lines): `getPricesWithFallback()`, `getBuyPricesWithFallback()`, `getWeightedSellPricesWithFallback()`, `getWeightedBuyPricesWithFallback()`, `getOrderBooksWithFallback()`, plus private helpers `getIndustryMaterialTypeIds()`, `getReprocessOutputTypeIds()`, `getOreTypeIds()`, `getPiCommodityTypeIds()`

---

### 4C. `any` Types in Frontend

**10 instances across 4 files:**

| File | Line(s) | Type of `any` | Fix |
|------|---------|---------------|-----|
| `components/industry/FavoriteSystemsConfig.vue` | 78, 87, 93, 99 | `as any` on partial settings update | The `updateUserSettings()` accepts `Partial<UserSettings>` but only `favoriteManufacturingSystemId` or `favoriteReactionSystemId` is passed. Fix: the cast is unnecessary since `Partial<UserSettings>` already accepts partial objects. Remove `as any`. |
| `components/pve/IncomeChart.vue` | 78, 96 | Chart.js callback params `(context: any)`, `(value: any)` | Replace with `TooltipItem<'bar'>` from `chart.js` and `number \| string` for tick callback |
| `components/pve/ProfitTrendChart.vue` | 60, 80 | Same Chart.js callback pattern | Same fix as IncomeChart |
| `components/pve/ExpenseBreakdownChart.vue` | 73 | Chart.js tooltip callback `(context: any)` | Replace with `TooltipItem<'pie'>` |
| `components/market/MarketHistoryChart.vue` | 206 | `] as any` on mixed chart datasets | Use `ChartDataset<'bar' \| 'line'>[]` or a union type. The chart mixes bar + line datasets which Chart.js types handle via discriminated unions. |

---

### 4D. N+1 Query Patterns

**Critical patterns identified (queries inside loops):**

| # | File | Line | Pattern | Impact | Fix |
|---|------|------|---------|--------|-----|
| 1 | `ItemParserService` | 80 | `findOneBy(['typeName' => $name])` in foreach loop | LOW (user input, small batches typically <50 items) | Batch: preload all type names with `WHERE typeName IN (...)` |
| 2 | `IndustryStepCalculator` | 74 | `materialRepository->findBy()` in nested loop (per depth, per step) | MEDIUM (bounded by tree depth x steps, typically <20 queries) | Batch: preload all materials for all blueprint type IDs in one query before the loop |
| 3 | `IndustryStepCalculator` | 114 | `productRepository->findOneBy()` in loop per typeId | MEDIUM (same scope as above) | Batch: preload all products for relevant blueprints |
| 4 | `IndustryProjectFactory` | 282 | `materialRepository->findBy()` in nested loop (per reaction step, per consumer) | MEDIUM (bounded, typically <30 queries) | Already has `findMaterialsForBlueprints()` batch method available in repository |
| 5 | `IndustryProjectFactory` | 353 | `activityRepository->findOneBy()` in foreach over all steps | HIGH (one query per step, can be 20-50 steps) | Batch: preload all activities for all blueprint type IDs |
| 6 | `IndustryJobMatcher` | 165 | `productRepository->findOneBy()` called per step | LOW (called only when adapting runs, infrequent) | Skip (low impact) |
| 7 | `WalletTransactionSyncService` | 70 | `findByTransactionId()` per transaction in loop | HIGH (can be 500+ transactions per page, 50 pages max) | Batch: preload existing transaction IDs with `WHERE transactionId IN (...)` |
| 8 | `OreValueService` | 276 | `findOneBy(['typeName' => $compressedName])` with local cache | LOW (has `compressedTypeCache` that mitigates repeated lookups) | Already mitigated by cache |
| 9 | `IndustryCalculationService` | 159 | `settingsRepository->findOneBy(['user' => $user])` | LOW (one query per call, not in a loop) | Not actually N+1 |

---

### 4E. UX Patterns

**Error dismissal patterns found:**

- **No auto-dismiss**: None of the error banners auto-dismiss. All require manual click.
- **Inconsistent close buttons**: Some have explicit close buttons (Assets, Characters, Industry, MarketBrowser, PlanetaryInteraction), others have no close button at all (Appraisal, ShoppingList).
- **Inconsistent styling**: At least 3 different error banner patterns:
  1. `bg-red-900/30 border-red-500/30` (Appraisal, ShoppingList)
  2. `bg-red-500/20 border-red-500/50` (Assets, Industry)
  3. `bg-red-500/10 border-red-500/30` (Ledger, Characters)

**Confirmation patterns found:**

- **ConfirmModal component**: Exists at `components/common/ConfirmModal.vue`. Used by EscalationsTab, ProjectTable.
- **Native `confirm()` still used**: StockpileColumn (line 72), StockpileDashboard (line 81) use browser `confirm()` instead of ConfirmModal.
- **Custom inline modals**: StepTree, StructureConfig implement their own confirmation patterns with inline divs instead of using ConfirmModal.

---

## Prioritized Implementation Plan

### Priority 1: Quick Wins (High value / Low effort)

#### 1a. Remove `as any` from FavoriteSystemsConfig.vue [Size: S]
- **File**: `frontend/src/components/industry/FavoriteSystemsConfig.vue`
- **Change**: Remove 4x `as any` casts. The `Partial<UserSettings>` type already accepts single-field objects.
- **Risk**: None.

#### 1b. Fix Chart.js `any` types [Size: S]
- **Files**:
  - `frontend/src/components/pve/IncomeChart.vue`
  - `frontend/src/components/pve/ProfitTrendChart.vue`
  - `frontend/src/components/pve/ExpenseBreakdownChart.vue`
  - `frontend/src/components/market/MarketHistoryChart.vue`
- **Change**: Import `TooltipItem` from `chart.js`, replace `(context: any)` with `(context: TooltipItem<'bar'>)` etc. For tick callback, use `(value: string | number)`. For MarketHistoryChart mixed datasets, use union type or explicit `as ChartDataset[]`.
- **Risk**: Minimal. Chart.js types are well-documented.

#### 1c. Standardize `confirm()` to ConfirmModal [Size: S]
- **Files**:
  - `frontend/src/components/industry/StockpileColumn.vue` (line 72)
  - `frontend/src/components/industry/StockpileDashboard.vue` (line 81)
- **Change**: Replace browser `confirm()` with `ConfirmModal` component, following the pattern from ProjectTable.vue.
- **Risk**: None.

---

### Priority 2: Critical Tests (High value / Medium effort)

#### 2a. Test MercurePublisherService [Size: S]
- **File to create**: `tests/Unit/Service/Mercure/MercurePublisherServiceTest.php`
- **What to test**:
  - `publishSyncProgress()` calls `HubInterface::publish()` with correct topic/payload
  - `syncStarted()`, `syncProgress()`, `syncCompleted()`, `syncError()` wrapper methods format correctly
  - `publishAlert()`, `publishNotification()`, `publishEscalationEvent()` publish correct topics
  - Exception in `hub->publish()` is caught and logged (no throw)
  - `getTopicsForUser()` returns expected topic list
  - `getGroupTopics()` handles null corp/alliance
- **Estimation**: 8-10 test cases
- **Risk**: None. Pure logic, easy to mock HubInterface.

#### 2b. Test MiningSyncService [Size: M]
- **File to create**: `tests/Unit/Service/Sync/MiningSyncServiceTest.php`
- **What to test**:
  - `shouldSync()`: null settings, auto-sync disabled, recently synced, interval elapsed
  - `canSync()`: no characters, no tokens, valid token
  - `syncAll()`: creates new entries, updates existing quantities, fixes unresolved type names, handles ESI errors per character, updates last sync time, publishes Mercure notifications
  - `refreshAllPrices()`: empty type IDs, price update counts
- **Estimation**: 12-15 test cases
- **Risk**: Many dependencies to mock (EsiClient, TokenManager, MarketService, etc.). Follow PveSyncServiceTest pattern.

#### 2c. Test WalletTransactionSyncService [Size: M]
- **File to create**: `tests/Unit/Service/Sync/WalletTransactionSyncServiceTest.php`
- **What to test**:
  - `syncCharacterTransactions()`: no token returns early, no scope returns early, creates new transactions, skips existing, pagination (from_id logic), stops when no new transactions, Mercure notifications, ESI error handling
- **Estimation**: 8-10 test cases
- **Risk**: Pagination logic needs careful mocking of multiple ESI calls.

#### 2d. Test ItemParserService [Size: S]
- **File to create**: `tests/Unit/Service/ItemParserServiceTest.php`
- **What to test**:
  - `parseItemList()`: EVE copy/paste format "10x Tritanium", "Tritanium x10", tab-separated, multiple spaces, empty lines, duplicate merging, bullet points
  - `resolveItemNames()`: exact match, case-insensitive fallback, unknown items returned in notFound, published/unpublished filtering
- **Estimation**: 15-20 test cases (many format variations)
- **Risk**: None. Pure text parsing logic, no external deps for parsing.

#### 2e. Expand BatchProfitScannerService tests [Size: S]
- **File**: `tests/Unit/Service/Industry/BatchProfitScannerServiceTest.php`
- **Tests to add**:
  - Structure sell price mode (use structure price instead of Jita)
  - Multiple output per run (e.g., ammo blueprints producing 100/run)
  - Empty material list for a blueprint
  - Category filtering (e.g., scan only 't2_ships')
- **Risk**: None.

#### 2f. Expand StockpileService tests [Size: S]
- **File**: `tests/Unit/Service/Industry/StockpileServiceTest.php`
- **Tests to add**:
  - `previewImport()` returns correct stages and estimated costs
  - Empty targets list returns zero KPIs
  - Zero quantity target handling
  - All targets met (100% pipeline health)
- **Risk**: None.

---

### Priority 3: N+1 Query Fixes (High performance impact / Medium effort)

#### 3a. Fix WalletTransactionSyncService N+1 [Size: M]
- **Files**:
  - `src/Service/Sync/WalletTransactionSyncService.php`
  - `src/Repository/CachedWalletTransactionRepository.php`
- **Change**: Before the inner foreach loop, collect all `transaction_id` values from the current page, then batch-check existence with `findByTransactionIds(array $ids): array` returning existing IDs as a set. Replace per-item `findByTransactionId()` with set lookup.
- **Repository method to add**:
  ```php
  public function findExistingTransactionIds(array $transactionIds): array
  {
      return $this->createQueryBuilder('t')
          ->select('t.transactionId')
          ->where('t.transactionId IN (:ids)')
          ->setParameter('ids', $transactionIds)
          ->getQuery()
          ->getSingleColumnResult();
  }
  ```
- **Impact**: Reduces N queries per page to 1. With 500 transactions/page, this is 500 queries -> 1.
- **Risk**: Low. Simple refactor with clear test coverage from 2c.

#### 3b. Fix IndustryProjectFactory N+1 [Size: M]
- **Files**:
  - `src/Service/Industry/IndustryProjectFactory.php`
  - `src/Repository/Sde/IndustryActivityRepository.php` (add batch method)
- **Change**:
  1. In `addTimeDataToSteps()` (line 353): Collect all `(blueprintTypeId, activityId)` pairs, then preload all activities in a single query before the loop.
  2. In reaction runs recalculation (line 282): Use existing `findMaterialsForBlueprints()` batch method instead of per-step `findBy()`.
- **Repository method to add**:
  ```php
  public function findByTypeIdsAndActivityIds(array $typeIds, array $activityIds): array
  ```
- **Impact**: Reduces 20-50 queries to 1-2 per project creation.
- **Risk**: Medium. Need to verify the factory logic still works correctly after refactoring. Existing `IndustryProjectFactoryTest` provides safety net.

#### 3c. Fix IndustryStepCalculator N+1 [Size: S]
- **Files**:
  - `src/Service/Industry/IndustryStepCalculator.php`
- **Change**: Before the depth loop, collect all blueprint type IDs from all steps, then preload all materials and products in batch queries.
- **Impact**: Reduces queries proportional to step count to 2 queries total.
- **Risk**: Low. Existing `IndustryStepCalculatorTest` covers recalculation logic.

---

### Priority 4: Service Decomposition (Medium value / Large effort)

#### 4a. Decompose SdeImportService [Size: L]
- **Files to create**:
  - `src/Service/Sde/SdeDownloader.php` (~90 lines) -- download, extract, cleanup, read JSONL
  - `src/Service/Sde/SdeInventoryImporter.php` (~210 lines) -- categories, groups, market_groups, types, type_materials
  - `src/Service/Sde/SdeMapImporter.php` (~190 lines) -- regions, constellations, solar_systems, stations, stargates
  - `src/Service/Sde/SdeBlueprintImporter.php` (~100 lines) -- blueprints + all activity sub-tables
  - `src/Service/Sde/SdeDogmaImporter.php` (~175 lines) -- attributes, effects
  - `src/Service/Sde/SdeReferenceImporter.php` (~200 lines) -- races, factions, flags, icons
  - `src/Service/Sde/SdePlanetaryImporter.php` (~55 lines) -- planet schematics
- **File to modify**:
  - `src/Service/Sde/SdeImportService.php` -- becomes orchestrator (~140 lines), injects all importers
- **Shared concerns**:
  - `SdeDownloader` provides `readJsonlFile()`, `getName()`, `getString()`, `getDescription()` as `BaseSdeImporter` trait or abstract class
  - Each importer gets the `EntityManagerInterface`, `LoggerInterface`, and `SdeDownloader` injected
  - The `truncateTable()` helper is shared via `BaseSdeImporter`
- **Risk**: Medium. Many file moves. Must verify `make sde-import` still works. No tests exist for this service, so no regression net. Manual testing required.
- **Mitigation**: Extract one importer at a time, test `make sde-import` after each extraction.

#### 4b. Decompose JitaMarketService [Size: L]
- **Files to create**:
  - `src/Service/Market/MarketCacheService.php` (~200 lines) -- read-only cache access
  - `src/Service/Market/MarketPriceCalculator.php` (~150 lines) -- weighted price calculations
  - `src/Service/Market/EsiMarketFetcher.php` (~250 lines) -- ESI sync + on-demand fetching
  - `src/Service/Market/MarketVolumeService.php` (~150 lines) -- daily volume calculations
- **File to modify**:
  - `src/Service/JitaMarketService.php` -- becomes facade (~200 lines), delegates to sub-services
- **Impact on consumers**: `JitaMarketService` is used by 15+ services. The facade pattern keeps the existing API intact. No consumer changes needed.
- **Risk**: High. JitaMarketService is the most-depended-upon service. The existing `JitaMarketServiceTest` covers `collectOrderBooks()` and price lookups but NOT sync or volume logic. Need to add tests for the new sub-services.
- **Mitigation**: Use facade pattern -- keep `JitaMarketService` as the public API, delegating internally. No consumer code changes. Extract one responsibility at a time.

---

### Priority 5: UX Standardization (Medium value / Medium effort)

#### 5a. Create ErrorBanner component [Size: M]
- **File to create**: `frontend/src/components/common/ErrorBanner.vue`
- **Behavior**:
  - Auto-dismiss after 5 seconds (configurable via prop `autoDismissMs`)
  - Close button always visible
  - Consistent styling: `bg-red-500/15 border border-red-500/30 rounded-xl`
  - Fade-out transition
  - Props: `message: string`, `autoDismissMs?: number` (default 5000, 0 = no auto-dismiss), `@dismiss` event
- **Files to modify** (replace inline error banners):
  - `frontend/src/views/Assets.vue`
  - `frontend/src/views/Characters.vue`
  - `frontend/src/views/Appraisal.vue`
  - `frontend/src/views/ShoppingList.vue`
  - `frontend/src/views/Industry.vue`
  - `frontend/src/views/Ledger.vue`
  - `frontend/src/views/MarketBrowser.vue`
  - `frontend/src/views/PlanetaryInteraction.vue`
  - `frontend/src/components/assets/ContractsTab.vue`
  - `frontend/src/components/ledger/PveTab.vue`
- **Risk**: Low. Pure UI change, no logic changes.

#### 5b. Migrate all confirmations to ConfirmModal [Size: S]
- **Files to modify**:
  - `frontend/src/components/industry/StockpileColumn.vue` -- replace `confirm()` with ConfirmModal
  - `frontend/src/components/industry/StockpileDashboard.vue` -- replace `confirm()` with ConfirmModal
  - `frontend/src/components/industry/StepTree.vue` -- replace inline modal with ConfirmModal import
  - `frontend/src/components/industry/StructureConfig.vue` -- replace inline modal with ConfirmModal import
- **Risk**: Low. ConfirmModal is battle-tested.

---

## Recommended Execution Order

```
Week 1: Quick Wins + Critical Tests
  Day 1: 1a + 1b + 1c (all frontend `any` + confirm fixes)      ~2h
  Day 2: 2a (MercurePublisherService tests)                      ~2h
  Day 3: 2d (ItemParserService tests)                             ~2h
  Day 4: 2e + 2f (expand existing test suites)                    ~2h
  Day 5: 2b (MiningSyncService tests)                             ~3h

Week 2: N+1 Fixes + More Tests
  Day 1: 2c (WalletTransactionSyncService tests)                  ~3h
  Day 2: 3a (WalletTransaction N+1 fix -- test already exists)    ~1h
  Day 3: 3b (IndustryProjectFactory N+1 fix)                      ~3h
  Day 4: 3c (IndustryStepCalculator N+1 fix)                      ~1h
  Day 5: 5a + 5b (UX: ErrorBanner + ConfirmModal migration)       ~4h

Week 3: Service Decomposition (if desired)
  Day 1-2: 4a (SdeImportService decomposition)                    ~6h
  Day 3-5: 4b (JitaMarketService decomposition)                   ~8h
```

**Total estimated effort: ~37 hours (3 weeks at moderate pace)**

---

## Risks and Trade-offs

### Service decomposition (4a, 4b)
- **Risk**: Highest effort items with moderate value. SdeImportService runs ~monthly, JitaMarketService's facade pattern means no external behavior change.
- **Trade-off**: Consider deferring 4a entirely (SDE import works, rarely changes). Prioritize 4b only if the team plans to modify market logic.
- **Alternative**: Instead of full decomposition, add `@internal` docblocks to group methods logically within the file, and extract only when a method group needs independent testing.

### N+1 fixes (3a, 3b, 3c)
- **Risk**: WalletTransaction N+1 is the only one with measurable user impact (500+ queries per sync page). The Industry ones are bounded by tree depth (<50 queries).
- **Trade-off**: Fix 3a first (largest impact). 3b and 3c are "nice to have" -- the query count is bounded by project complexity.

### Test prioritization
- **ESI services** (EsiClient, AssetsService, AuthenticationService) are HTTP wrappers that are better suited for integration tests. Unit testing them requires heavy mocking of HttpClient responses. Consider adding integration tests later with a recorded HTTP fixture approach.
- **Sync services** are the most valuable to test because they contain business logic (upsert strategies, dedup, progress reporting).

### UX standardization
- **ErrorBanner auto-dismiss** may frustrate users who want to read the error. Recommend: auto-dismiss for success/info messages only, keep errors persistent with close button.
- **Revised recommendation**: Auto-dismiss only for transient errors (sync failures that will auto-retry). Keep persistent for action errors (delete failed, etc.).

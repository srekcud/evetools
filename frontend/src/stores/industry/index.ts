/**
 * Industry module stores - barrel exports.
 */
export { useProjectsStore } from './projects'
export { useStepsStore } from './steps'
export { useStructuresStore } from './structures'
export { useBlacklistStore } from './blacklist'
export { usePurchasesStore } from './purchases'
export { useScannerStore } from './scanner'
export { useStockpileStore } from './stockpile'
export { useSlotsStore } from './slots'
export { enrichStep, enrichProject, formatErrorMessage } from './compat'

// Re-export all types
export type {
  RootProduct,
  JobMatch,
  StepPurchase,
  IndustryProjectStep,
  IndustryProject,
  ShoppingListItem,
  ShoppingListTotals,
  ShoppingListResponse,
  ProductionTreeNode,
  TreeMaterial,
  SearchResult,
  BlacklistCategory,
  BlacklistItem,
  BlacklistConfig,
  StructureConfig,
  StructureSearchResult,
  RigOption,
  RigOptions,
  CorporationStructureSharedConfig,
  CorporationStructure,
  CharacterSkill,
  UserSettings,
  PurchaseSuggestion,
  AvailableJob,
  CostEstimationMaterial,
  CostEstimationStep,
  CostEstimation,
  CopyCostEntry,
  CopyCosts,
  BpcKitDatacore,
  BpcKitDecryptorOption,
  BpcKitInvention,
  BpcKitSummary,
  BpcKit,
  ProfitMarginResult,
  ProfitMarginSellPrices,
  MarginEntry,
  ProfitMarginMaterial,
  ProfitMarginJobStep,
  ProfitMarginInventionOption,
  BatchScanItem,
  BuyVsBuildComponent,
  BuyVsBuildMaterial,
  BuyVsBuildResult,
  PivotAnalysisResult,
  PivotCandidate,
  PivotSourceProduct,
  PivotComponentCoverage,
  PivotMissingComponent,
  StockpileStage,
  StockpileTarget,
  StockpileItemStatus,
  StockpileStageStatus,
  StockpileBottleneck,
  StockpileEstOutput,
  StockpileKpis,
  StockpileStatus,
  StockpileShoppingItem,
  StockpileImportPreviewItem,
  StockpileImportPreview,
  SlotActivity,
  SlotUsage,
  SlotTrackerJob,
  FreeSlotEntry,
  SlotTrackerCharacter,
  TimelineJob,
  SlotTrackerData,
} from './types'

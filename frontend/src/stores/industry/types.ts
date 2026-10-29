/**
 * Shared type definitions for the industry module.
 */

export interface RootProduct {
  typeId: number
  typeName: string | null
  runs: number
  meLevel: number | null
  teLevel: number | null
  count?: number
}

export interface JobMatch {
  id: string
  esiJobId: number
  cost: number | null
  status: string
  endDate: string | null
  runs: number
  characterName: string
  matchedAt: string
  facilityId: number | null
  facilityName: string | null
}

export interface SimilarEsiJob {
  esiJobId: number
  runs: number
  status: string
  characterName: string
  facilityId: number | null
  facilityName: string | null
}

export interface StepPurchase {
  id: string
  typeId: number
  typeName: string | null
  quantity: number
  unitPrice: number
  totalPrice: number
  source: 'esi_wallet' | 'manual'
  transactionId: string | null
  createdAt: string
}

export interface IndustryProjectStep {
  id: string
  blueprintTypeId: number
  productTypeId: number
  productTypeName: string | null
  quantity: number
  runs: number
  runsFixed: boolean
  depth: number
  activityType: string
  sortOrder: number
  purchased: boolean
  inStockQuantity: number
  meLevel: number
  teLevel: number
  jobMatchMode: 'auto' | 'manual' | 'none'
  structureConfigId: string | null
  structureConfigName: string | null
  structureMaterialBonus: number | null
  structureTimeBonus: number | null
  favoriteSystemWithoutSuitableStructure: boolean
  timePerRun: number | null
  recommendedCharacterName: string | null
  splitGroupId: string | null
  splitIndex: number
  totalGroupRuns: number | null
  facilityInfoType: 'suboptimal' | 'unconfigured' | null
  actualFacilityName: string | null
  bestStructureName: string | null
  bestMaterialBonus: number | null
  jobMatches: JobMatch[]
  jobsCost: number | null
  purchases: StepPurchase[]
  purchasesCost: number | null
  purchasedQuantity: number

  // Backward-compatible computed fields (populated by enrichStep())
  inStock: boolean
  esiJobId: number | null
  esiJobCost: number | null
  esiJobStatus: string | null
  esiJobEndDate: string | null
  esiJobRuns: number | null
  esiJobCharacterName: string | null
  esiJobsCount: number | null
  esiJobsTotalRuns: number | null
  esiJobsActiveRuns: number | null
  esiJobsDeliveredRuns: number | null
  manualJobData: boolean
  recommendedStructureName: string | null
  structureBonus: number | null
  similarJobs: SimilarEsiJob[]
}

export interface IndustryProject {
  id: string
  productTypeName: string | null
  productTypeId: number
  name?: string | null
  displayName: string | null
  runs: number
  meLevel: number
  teLevel: number
  maxJobDurationDays: number
  status: string
  personalUse: boolean
  isT2: boolean
  bpoCost: number | null
  materialCost: number | null
  transportCost: number | null
  jobsCost: number | null
  taxAmount: number | null
  sellPrice: number | null
  totalCost: number | null
  estimatedJobCost: number | null
  estimatedSellPrice: number | null
  estimatedSellPriceSource: 'jita' | 'structure' | null
  estimatedTaxAmount: number | null
  estimatedMaterialCost: number | null
  profit: number | null
  profitPercent: number | null
  notes: string | null
  createdAt: string
  completedAt: string | null
  jobsStartDate: string | null
  rootProducts?: RootProduct[]
  steps?: IndustryProjectStep[]
  tree?: ProductionTreeNode | null
  failedProducts?: ShoppingListFailedProduct[] | null
}

export interface ShoppingListItem {
  typeId: number
  typeName: string
  quantity: number
  volume: number | null
  totalVolume: number | null
  jitaUnitPrice: number | null
  jitaTotal: number | null
  importCost: number | null
  jitaWithImport: number | null
  structureUnitPrice: number | null
  structureTotal: number | null
  bestLocation: 'jita' | 'structure' | null
  bestPrice: number | null
  savings: number | null
  purchasedQuantity: number
  extraQuantity: number
  jitaWeightedUnitPrice: number | null
  jitaWeightedTotal: number | null
  jitaCoverage: number | null
  isInventionMaterial?: boolean
}

export interface ShoppingListTotals {
  jita: number | null
  import: number | null
  jitaWithImport: number | null
  structure: number | null
  volume: number | null
  best: number | null
  savingsVsJitaWithImport: number | null
  savingsVsStructure: number | null
}

export interface ShoppingListFailedProduct {
  typeId: number
  reason: string
}

export interface ShoppingListResponse {
  materials: ShoppingListItem[]
  structureId: number
  structureName: string | null
  structureAccessible: boolean
  structureFromCache: boolean
  structureLastSync: string | null
  transportCostPerM3: number
  totals: ShoppingListTotals
  priceError: string | null
  missingVolumeTypeIds: number[]
  failedProducts: ShoppingListFailedProduct[]
  missingPriceTypeIds: number[]
}

export interface ProductionTreeNode {
  blueprintTypeId: number
  productTypeId: number
  productTypeName: string
  quantity: number
  runs: number
  depth: number
  activityType: string
  hasCopy: boolean
  materials: TreeMaterial[]
  structureBonus: number
  structureName: string | null
  productCategory: string | null
}

export interface TreeMaterial {
  typeId: number
  typeName: string
  quantity: number
  isBuildable: boolean
  blueprint?: ProductionTreeNode
}

export interface SearchResult {
  typeId: number
  typeName: string
  isT2: boolean
}

export interface BlacklistCategory {
  key: string
  label: string
  groupIds: number[]
  blacklisted: boolean
}

export interface BlacklistItem {
  typeId: number
  typeName: string
}

export interface BlacklistConfig {
  categories: BlacklistCategory[]
  items: BlacklistItem[]
}

export interface StructureConfig {
  id: string
  name: string
  locationId: number | null
  solarSystemId: number | null
  securityType: 'highsec' | 'lowsec' | 'nullsec'
  structureType: 'station' | 'raitaru' | 'azbel' | 'sotiyo' | 'athanor' | 'tatara' | 'engineering_complex' | 'refinery'
  rigs: string[]
  isDefault: boolean
  isCorporationStructure: boolean
  manufacturingMaterialBonus: number
  reactionMaterialBonus: number
  manufacturingTimeBonus: number
  reactionTimeBonus: number
  facilityTaxRate: number | null
  createdAt: string
}

export interface StructureSearchResult {
  locationId: number
  locationName: string
  solarSystemId: number | null
  solarSystemName: string | null
  structureType: string | null
  typeId: number | null
  isCorporationOwned?: boolean
}

export interface RigOption {
  name: string
  bonus: number
  timeBonus?: number
  categoryBonuses?: Record<string, number>
  category: string
  size: 'M' | 'L' | 'XL'
  targetCategories: string[]
}

export interface StructureBonusPreview {
  manufacturingMaterialBonus: number
  reactionMaterialBonus: number
  manufacturingTimeBonus: number
  reactionTimeBonus: number
}

export interface RigOptions {
  manufacturing: RigOption[]
  reaction: RigOption[]
}

export interface CorporationStructureSharedConfig {
  securityType: 'highsec' | 'lowsec' | 'nullsec'
  structureType: string
  rigs: string[]
  manufacturingMaterialBonus: number
  reactionMaterialBonus: number
  facilityTaxRate: number | null
}

export interface CorporationStructure {
  locationId: number
  locationName: string
  solarSystemId: number | null
  solarSystemName: string | null
  sharedConfig: CorporationStructureSharedConfig | null
  isCorporationOwned: boolean | null
  structureType: string | null
}

export interface CharacterSkill {
  characterId: number
  characterName: string
  industry: number
  advancedIndustry: number
  reactions: number
  source: 'esi' | 'manual'
  lastSyncAt: string | null
}

export type CharacterSyncFailureReason = 'missing_token' | 'missing_scope' | 'authentication_required' | 'esi_rate_limited' | 'esi_unavailable' | 'esi_error' | 'sync_failed'

export interface CharacterSyncFailure {
  characterName: string
  reason: CharacterSyncFailureReason
  statusCode: number | null
}

export interface UserSettings {
  favoriteManufacturingSystemId: number | null
  favoriteManufacturingSystemName: string | null
  favoriteReactionSystemId: number | null
  favoriteReactionSystemName: string | null
  brokerFeeRate: number
  salesTaxRate: number
  exportCostPerM3: number
}

// Profit Margin types

export type MissingDataReason = 'market_price' | 'adjusted_price' | 'cost_index' | 'invention_probability'

export interface MissingData {
  reason: MissingDataReason
  typeId: number | null
}

export interface PartialPriceWarning {
  reason: 'partial_market_depth'
  typeId: number
  coveragePercent: number
}

export interface ProfitMarginMaterial {
  typeId: number
  typeName: string
  quantity: number
  unitPrice: number | null
  totalPrice: number | null
}

export interface ProfitMarginJobStep {
  productTypeId: number
  productName: string
  activityType: string
  runs: number
  installCost: number | null
  missingData: MissingData[]
}

export interface ProfitMarginSellPrices {
  jitaSell: number | null
  structureSell: number | null
  structureBuy: number | null
  contractSell: number | null
  contractCount: number | null
  structureId: number
  structureName: string
}

export interface MarginEntry {
  revenue: number
  fees: number
  profit: number
  margin: number | null
}

export interface ProfitMarginInventionOption {
  decryptorTypeId: number | null
  decryptorName: string
  me: number
  te: number
  runs: number
  probability: number | null
  inventionCost: number | null
  totalProductionCost: number | null
  bestMargin: number | null
}

export type InventionUnknownReason = 'missing_invention_probability' | 'missing_market_price'

export type ProductionCostUnknownReason = InventionUnknownReason | 'missing_material_price' | JobInstallCostUnknownReason

export interface ProfitMarginResult {
  typeId: number
  typeName: string | null
  isT2: boolean
  runs: number
  outputQuantity: number
  outputPerRun: number
  materialCost: number | null
  materials: ProfitMarginMaterial[]
  jobInstallCost: number | null
  jobInstallSteps: ProfitMarginJobStep[]
  inventionCost: number | null
  totalCost: number | null
  costPerUnit: number | null
  invention: {
    baseProbability: number | null
    unknownReason: InventionUnknownReason | null
    datacores: (string | null)[]
    selectedDecryptorTypeId: number | null
    selectedDecryptorName: string
    options: ProfitMarginInventionOption[]
  } | null
  sellPrices: ProfitMarginSellPrices
  brokerFeeRate: number
  salesTaxRate: number
  margins: Record<string, MarginEntry | null>
  dailyVolume: number | null
  missingData: MissingData[]
  warnings: PartialPriceWarning[]
}

export interface AvailableJob {
  esiJobId: number
  blueprintTypeId: number
  productTypeId: number
  productTypeName: string | null
  runs: number
  cost: number | null
  status: string
  startDate: string
  endDate: string
  characterName: string
  linkedToStepId: string | null
  linkedToStepName: string | null
  matchId: string | null
}

export interface CostEstimationMaterial {
  typeId: number
  typeName: string
  quantity: number
  unitPrice: number
  totalPrice: number
}

export interface CostEstimationStep {
  stepId: string
  productTypeId: number
  productName: string | null
  solarSystemId: number
  systemName: string
  costIndex: number | null
  runs: number
  installCost: number | null
  unknownReason: JobInstallCostUnknownReason | null
  missingAdjustedPriceTypeIds: number[]
}

export interface CostEstimation {
  id: string
  materialCost: number | null
  jobInstallCost: number | null
  bpoCost: number
  totalCost: number | null
  perUnit: number | null
  outputQuantity: number
  materials: CostEstimationMaterial[]
  jobInstallSteps: CostEstimationStep[]
  failedProducts: ShoppingListFailedProduct[]
}

export interface PurchaseSuggestion {
  transactionId: number
  transactionUuid: string
  typeId: number
  typeName: string | null
  quantity: number
  unitPrice: number
  totalPrice: number
  date: string
  characterName: string
  locationId: number | null
  alreadyLinked: boolean
}

// Copy costs types (for CostEstimation tab)

export interface CopyCostEntry {
  blueprintTypeId: number
  blueprintName: string | null
  productTypeName: string | null
  runs: number
  cost: number
  depth: number
}

export interface CopyCosts {
  id: string
  copies: CopyCostEntry[]
  totalCopyCost: number
}

// BPC Kit types

export interface BpcKitDatacore {
  typeId: number
  typeName: string | null
  quantity: number
  unitPrice: number | null
  totalPrice: number | null
}

export interface BpcKitDecryptorOption {
  decryptorTypeId: number | null
  decryptorName: string
  me: number
  te: number
  runs: number
  probability: number | null
  costPerAttempt: number | null
  expectedAttempts: number | null
  totalCost: number | null
  costBreakdown: {
    datacores: number | null
    decryptor: number | null
    copyCost: number
    inventionInstall: number | null
  }
  missingPriceTypeIds: number[]
  missingAdjustedPriceTypeIds: number[]
  unknownReason: InventionUnknownReason | JobInstallCostUnknownReason | null
}

export interface BpcKitInvention {
  productTypeId: number
  productName: string | null
  baseProbability: number | null
  unknownReason: InventionUnknownReason | null
  desiredSuccesses: number
  datacores: BpcKitDatacore[]
  decryptorOptions: BpcKitDecryptorOption[]
}

export interface BpcKitSummary {
  totalInventionCost: number | null
  bestDecryptorTypeId: number | null
  totalBpcKitCost: number | null
}

export interface BpcKit {
  id: string
  isT2: boolean
  inventions: BpcKitInvention[]
  summary: BpcKitSummary
}

// Batch Scan types
export type BatchScanItem = {
  typeId: number
  typeName: string | null
  groupName: string
  categoryLabel: string
  marginPercent: number | null
  profitPerUnit: number | null
  dailyVolume: number
  iskPerDay: number | null
  materialCost: number | null
  exportCost: number | null
  importCost: number | null
  sellPrice: number | null
  meUsed: number
  activityType: string
  isFactionBlueprint: boolean
  bpcCostPerRun: number | null
  hasAllSkills: boolean
  missingSkillCount: number
  unknownReason: string | null
  missingPriceTypeIds: number[]
}

export type BpcPrice = {
  blueprintTypeId: number
  pricePerRun: number
  updatedAt: string
}

export type ScannerFavorite = {
  typeId: number
  createdAt: string
}

// Buy vs Build types
export type JobInstallCostUnknownReason = 'missing_cost_index' | 'missing_adjusted_price'

export type BuyVsBuildMaterial = {
  typeId: number
  typeName: string | null
  quantity: number
  unitPrice: number | null
  totalPrice: number | null
}

export type BuyVsBuildComponent = {
  typeId: number
  typeName: string | null
  quantity: number
  stage: string
  buildCost: number | null
  buildMaterials: BuyVsBuildMaterial[]
  buildJobInstallCost: number | null
  buyCostJita: number | null
  buyCostStructure: number | null
  buyCostJitaCoveragePercent: number | null
  verdict: 'build' | 'buy' | 'loss' | 'unknown'
  savings: number | null
  savingsPercent: number | null
  meUsed: number
  runs: number
  missingData: MissingData[]
  warnings: PartialPriceWarning[]
}

export type BuyVsBuildResult = {
  typeId: number
  typeName: string | null
  isT2: boolean
  runs: number
  totalProductionCost: number | null
  sellPrice: number | null
  marginPercent: number | null
  components: BuyVsBuildComponent[]
  buildAllCost: number | null
  buyAllCost: number | null
  optimalMixCost: number | null
  buildTypeIds: number[]
  buyTypeIds: number[]
  missingData: MissingData[]
  warnings: PartialPriceWarning[]
  sellStructureId: number | null
}

// Pivot Advisor types
export type PivotMissingComponent = {
  typeId: number
  typeName: string | null
  quantity: number
  cost: number | null
}

export type PivotComponentCoverage = {
  typeId: number
  typeName: string | null
  inStock: number
  candidates: Record<number, { needed: number; status: 'covered' | 'partial' | 'none' }>
}

export type PivotUnknownReason = 'missing_material_price' | JobInstallCostUnknownReason

export type PivotCandidate = {
  typeId: number
  typeName: string | null
  groupName: string
  marginPercent: number | null
  profitPerUnit: number | null
  dailyVolume: number
  coveragePercent: number | null
  missingComponents: PivotMissingComponent[]
  additionalCost: number | null
  estimatedProfit: number | null
  score: number | null
  unknownReason: PivotUnknownReason | null
  missingPriceTypeIds: number[]
}

export type PivotSourceProduct = {
  typeId: number
  typeName: string | null
  groupName: string
  marginPercent: number | null
  sellPrice: number
  dailyVolume: number
  keyComponents: { typeId: number; typeName: string | null; inStock: number }[]
  unknownReason?: PivotUnknownReason | null
  missingPriceTypeIds?: number[]
}

export type PivotAnalysisResult = {
  typeId: number
  sourceProduct: PivotSourceProduct
  matrix: PivotComponentCoverage[]
  candidates: PivotCandidate[]
  matrixProductIds: number[]
}

// Stockpile types
export type StockpileStage = 'raw_material' | 'intermediate' | 'component' | 'final_product'

export type StockpileTarget = {
  id: string
  typeId: number
  typeName: string
  targetQuantity: number
  stage: StockpileStage
  sourceProductTypeId: number | null
  createdAt: string
  updatedAt: string | null
}

export type StockpileLocation = {
  locationId: number
  locationName: string
  systemName: string | null
  quantity: number
}

export type StockpileItemStatus = {
  id: string
  typeId: number
  typeName: string
  targetQuantity: number
  remainingTargetQuantity: number
  stock: number
  percent: number
  status: 'met' | 'partial' | 'critical'
  unitPrice: number | null
  stockValue: number | null
  deficitCost: number | null
  sourceProductTypeId: number | null
  locations: StockpileLocation[]
}

export type StockpileStageStatus = {
  items: StockpileItemStatus[]
  totalValue: number | null
  healthPercent: number
}

export type StockpileBottleneck = {
  typeId: number
  typeName: string
  percent: number
  blocksProducts: number
} | null

export type StockpileEstOutput = {
  ready: number
  total: number
  readyNames: string[]
}

export type StockpileKpis = {
  pipelineHealth: number
  totalInvested: number | null
  bottleneck: StockpileBottleneck
  estOutput: StockpileEstOutput
}

export type StockpileStatus = {
  targetCount: number
  stages: Record<StockpileStage, StockpileStageStatus>
  kpis: StockpileKpis
  shoppingList: StockpileShoppingItem[]
  importsWithoutPlan: number[]
  failedProducts: StockpileFailedProduct[]
  missingPriceTypeIds: number[]
}

export type StockpileFailedProduct = {
  typeId: number
  reason: string
}

export type StockpileShoppingItem = {
  typeId: number
  typeName: string
  stage: StockpileStage
  stock: number
  targetQuantity: number
  deficit: number
  deficitCost: number | null
  percent: number
}

export type StockpileImportPreviewItem = {
  typeId: number
  typeName: string
  quantity: number
  unitPrice: number | null
}

export type StockpileImportPreview = {
  stages: Record<StockpileStage, StockpileImportPreviewItem[]>
  totalItems: number
  estimatedCost: number | null
  missingPriceTypeIds: number[]
}

// Slot Tracker types
export type SlotActivity = 'manufacturing' | 'reaction' | 'science'

export type SlotUsage = { used: number; max: number; percent?: number }

export type CharacterSlotUsage = { used: number; max: number | null }

export type SlotTrackerJob = {
  jobId: number; productTypeId: number; productTypeName: string | null
  activityType: string; runs: number; progress: number
  timeLeftSeconds: number; facilityName: string | null
  startDate: string; endDate: string
}

export type FreeSlotEntry = {
  activityType: string; count: number
  suggestion: { typeId: number; typeName: string; reason: string } | null
}

export type SlotTrackerCharacter = {
  characterId: number; characterName: string; isMain: boolean
  slots: Record<SlotActivity, CharacterSlotUsage>
  skillsUnknown: boolean; jobsStale: boolean
  jobs: SlotTrackerJob[]; freeSlots: FreeSlotEntry[]
}

export type TimelineJob = {
  jobId: number; characterName: string; productTypeId: number; productTypeName: string | null
  activityType: string; runs: number; timeLeftSeconds: number; endDate: string
}

export type SlotTrackerData = {
  globalKpis: Record<SlotActivity, SlotUsage & { percent: number }>
  characters: SlotTrackerCharacter[]; timeline: TimelineJob[]
  skillsMayBeStale: boolean
  unknownSkillsCharacterCount: number
}

// Cross-tab navigation intent for the Industry cockpit
export type NavigationIntent =
  | { target: 'projects'; openCreateModal?: boolean; prefill?: { typeId: number; typeName: string; runs?: number; me?: number; te?: number; isT2?: boolean; excludedTypeIds?: number[] } }
  | { target: 'margins'; typeId: number }
  | { target: 'batch' }
  | { target: 'buy-vs-build'; typeId: number }
  | { target: 'pivot'; typeId: number }
  | { target: 'slots' }
  | { target: 'stockpile' }
  | null

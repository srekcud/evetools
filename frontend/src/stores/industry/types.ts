/**
 * Shared type definitions for the industry module.
 */

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
  exportCostPerM3: number
}

// Profit Margin types

export type MissingDataReason = 'market_price' | 'adjusted_price' | 'cost_index' | 'invention_probability' | 'inventor_skills'

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
  jitaSellCoveragePercent: number | null
  structureSell: number | null
  structureBuy: number | null
  contractSell: number | null
  contractCount: number | null
  structureId: number
  structureName: string
}

export interface MarginEntry {
  revenue: number
  fees: number | null
  profit: number | null
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
  expectedAttempts: number | null
  manufacturingJobs: number
  decryptorCost: number | null
  costPerUnit: number | null
  costPerUnitDelta: number | null
}

export type InventionUnknownReason = 'missing_invention_probability' | 'missing_market_price' | 'missing_inventor_skills'

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
    datacoresCostPerAttempt: number | null
    inventor: { characterId: string, characterName: string, skillsKnown: boolean } | null
    selectedDecryptorTypeId: number | null
    selectedDecryptorName: string
    options: ProfitMarginInventionOption[]
  } | null
  sellPrices: ProfitMarginSellPrices
  brokerFeeRate: number | null
  salesTaxRate: number | null
  margins: Record<string, MarginEntry | null>
  dailyVolume: number | null
  missingData: MissingData[]
  warnings: PartialPriceWarning[]
}

// Batch Scan types
export type BatchScanItem = {
  typeId: number
  typeName: string | null
  groupName: string
  categoryLabel: string
  marginPercent: number | null
  profitPerUnit: number | null
  dailyVolume: number | null
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

export type PivotUnknownReason = 'missing_material_price' | InventionUnknownReason | JobInstallCostUnknownReason

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
  deficit: number
  deficitCost: number | null
  sourceProductTypeId: number | null
  locations: StockpileLocation[]
  suggestedProjectRuns: number | null
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
export type ProjectPrefill = { typeId: number; typeName: string; runs?: number; me?: number; te?: number; isT2?: boolean; excludedTypeIds?: number[] }

export type NavigationIntent =
  | { target: 'projects'; openCreateModal?: boolean; prefill?: ProjectPrefill }
  | { target: 'margins'; typeId: number }
  | { target: 'batch' }
  | { target: 'buy-vs-build'; typeId: number }
  | { target: 'pivot'; typeId: number }
  | { target: 'slots' }
  | { target: 'stockpile' }
  | null

export type SellMarketKey = 'jita' | 'capital'

export interface SellMarketLine {
  key: SellMarketKey
  name: string
  unitPrice: number | null
  coveragePercent: number | null
  dailyVolume: number | null
  volumeKind: 'region_history' | 'structure_estimate'
  feeRate: number | null
  netUnitPrice: number | null
  daysToSell: number | null
}

export interface SellMarketImmediateSale {
  averageUnitPrice: number | null
  coveragePercent: number | null
  netUnitPrice: number | null
}

export interface SellMarketResponse {
  typeId: number
  typeName: string | null
  quantity: number
  sellShareOfDailyVolume: number
  markets: SellMarketLine[]
  immediateSale: SellMarketImmediateSale
}

export type BlueprintKind = 'bpo' | 'bpc'
export type BlueprintScope = 'personal' | 'corporation'
export type CorporationBlueprintState = 'known' | 'no_director' | 'no_visibility'

export interface BlueprintLibraryEntry {
  typeId: number
  name: string
  kind: BlueprintKind
  me: number
  te: number
  runs: number | null
  scope: BlueprintScope
  ownerName: string | null
  locationId: number
  divisionNumber: number | null
  count: number
}

export interface BlueprintLibrary {
  corporationState: CorporationBlueprintState
  blueprints: BlueprintLibraryEntry[]
}

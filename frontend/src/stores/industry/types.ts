/**
 * Shared type definitions for the industry module.
 */

export interface RootProduct {
  typeId: number
  typeName: string
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
  typeName: string
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
  productTypeName: string
  quantity: number
  runs: number
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
  isSplit: boolean
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
  estimatedDurationDays: number | null
  similarJobs: SimilarEsiJob[]
}

export interface IndustryProject {
  id: string
  productTypeName: string
  productTypeId: number
  name?: string | null
  displayName: string
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
}

export interface ShoppingListItem {
  typeId: number
  typeName: string
  quantity: number
  volume: number
  totalVolume: number
  jitaUnitPrice: number | null
  jitaTotal: number | null
  importCost: number
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
  jita: number
  import: number
  jitaWithImport: number
  structure: number
  volume: number
  best: number
  savingsVsJitaWithImport: number
  savingsVsStructure: number
}

export interface ShoppingListResponse {
  materials: ShoppingListItem[]
  structureId: number
  structureName: string
  structureAccessible: boolean
  structureFromCache: boolean
  structureLastSync: string | null
  transportCostPerM3: number
  totals: ShoppingListTotals
  priceError: string | null
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
  category: string
  size: 'M' | 'L' | 'XL'
  targetCategories: string[]
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

export interface UserSettings {
  favoriteManufacturingSystemId: number | null
  favoriteManufacturingSystemName: string | null
  favoriteReactionSystemId: number | null
  favoriteReactionSystemName: string | null
  brokerFeeRate: number
  salesTaxRate: number
}

// Profit Margin types

export interface ProfitMarginMaterial {
  typeId: number
  typeName: string
  quantity: number
  unitPrice: number
  totalPrice: number
}

export interface ProfitMarginJobStep {
  productTypeId: number
  productName: string
  activityType: string
  runs: number
  installCost: number
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
  margin: number
}

export interface ProfitMarginInventionOption {
  decryptorTypeId: number | null
  decryptorName: string
  me: number
  te: number
  runs: number
  probability: number
  inventionCost: number
  totalProductionCost: number
  bestMargin: number
}

export interface ProfitMarginResult {
  typeId: number
  typeName: string
  isT2: boolean
  runs: number
  outputQuantity: number
  outputPerRun: number
  materialCost: number
  materials: ProfitMarginMaterial[]
  jobInstallCost: number
  jobInstallSteps: ProfitMarginJobStep[]
  inventionCost: number
  copyCost: number
  totalCost: number
  costPerUnit: number
  invention: {
    baseProbability: number
    datacores: string[]
    selectedDecryptorTypeId: number | null
    selectedDecryptorName: string
    options: ProfitMarginInventionOption[]
  } | null
  sellPrices: ProfitMarginSellPrices
  brokerFeeRate: number
  salesTaxRate: number
  margins: Record<string, MarginEntry | null>
  dailyVolume: number
}

export interface AvailableJob {
  esiJobId: number
  blueprintTypeId: number
  productTypeId: number
  productTypeName: string
  runs: number
  cost: number
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
  productName: string
  solarSystemId: number
  systemName: string
  costIndex: number
  runs: number
  installCost: number
}

export interface CostEstimation {
  id: string
  materialCost: number
  jobInstallCost: number
  bpoCost: number
  totalCost: number
  perUnit: number
  materials: CostEstimationMaterial[]
  jobInstallSteps: CostEstimationStep[]
}

export interface PurchaseSuggestion {
  transactionId: number
  transactionUuid: string
  typeId: number
  typeName: string
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
  blueprintName: string
  productTypeName: string
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
  typeName: string
  quantity: number
  unitPrice: number
  totalPrice: number
}

export interface BpcKitDecryptorOption {
  decryptorTypeId: number | null
  decryptorName: string
  me: number
  te: number
  runs: number
  probability: number
  costPerAttempt: number
  expectedAttempts: number
  totalCost: number
  costBreakdown: {
    datacores: number
    decryptor: number
    copyCost: number
    inventionInstall: number
  }
}

export interface BpcKitInvention {
  productTypeId: number
  productName: string
  baseProbability: number
  desiredSuccesses: number
  datacores: BpcKitDatacore[]
  decryptorOptions: BpcKitDecryptorOption[]
}

export interface BpcKitSummary {
  totalInventionCost: number
  bestDecryptorTypeId: number | null
  totalBpcKitCost: number
}

export interface BpcKit {
  id: string
  isT2: boolean
  inventions: BpcKitInvention[]
  summary: BpcKitSummary
}

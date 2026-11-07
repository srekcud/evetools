/**
 * Shared type definitions for the group industry module.
 * Types mirror the API resource DTOs from src/ApiResource/GroupIndustry/.
 */

export type GroupProjectStatus = 'draft' | 'published' | 'in_progress' | 'selling' | 'completed'

export type GroupMemberRole = 'owner' | 'admin' | 'member'

export type GroupMemberStatus = 'pending' | 'accepted' | 'removed'

export type ContributionType = 'material' | 'job_install' | 'bpc' | 'line_rental'

export type ContributionStatus = 'pending' | 'approved' | 'rejected'

export type ContainerVerificationStatus = 'verified' | 'partial' | 'missing' | 'container_not_found' | 'unknown'

export type ContainerCheckUnknownReason = 'owner_without_corporation' | 'corporation_assets_not_synced' | 'no_visible_division'

export type JobGroup = 'blueprint' | 'component' | 'final'

// --- Project ---

export type GroupProjectItem = {
  typeId: number
  typeName: string
  meLevel: number
  teLevel: number
  runs: number
}

export type ProjectTemplate = {
  id: string
  name: string
  items: GroupProjectItem[]
  blacklistGroupIds: number[]
  blacklistTypeIds: number[]
  acceptsOutsideFavoriteSystem: boolean
  minimumOutsideSaving: number
  sharedWithCorporation: boolean
  ownerName: string | null
  isOwn: boolean
  usageCount: number
  createdAt: string
}

export type SaveProjectTemplateInput = {
  projectId: string
  name: string
  sharedWithCorporation: boolean
}

export type GroupProject = {
  id: string
  name: string | null
  status: GroupProjectStatus
  shortLinkCode: string
  containerName: string | null
  private: boolean
  acceptsOutsideFavoriteSystem: boolean
  minimumOutsideSaving: number
  notes: string | null
  ownerCharacterName: string
  ownerCorporationId: number | null
  membersCount: number
  items: GroupProjectItem[]
  totalBomValue: number | null
  fulfillmentPercent: number
  approvedContributionValue: number | null
  brokerFeePercent: number | null
  salesTaxPercent: number | null
  lineRentalRatesOverride: Record<string, number> | null
  blacklistGroupIds: number[]
  blacklistTypeIds: number[]
  createdAt: string
  myRole: GroupMemberRole | null
  myStatus: 'accepted' | 'pending' | null
}

// --- BOM ---

export type JobMatch = {
  id: string
  bomLineId: string
  esiJobId: number
  cost: number | null
  status: string
  endDate: string | null
  runs: number
  characterName: string | null
  matchedAt: string
  facilityId: number | null
  facilityName: string | null
  manual: boolean
}

export type ProjectPurchase = {
  id: string
  bomLineId: string
  typeId: number
  quantity: number
  unitPrice: number
  totalPrice: number
  source: string
  transactionId: string | null
  createdAt: string
}

export type CharacterMatchFailure = {
  characterName: string
  reason: string
  statusCode: number | null
}

export type MatchJobsResult = {
  jobMatches: JobMatch[]
  jobsCost: number | null
  syncedCharacters: number
  failedCharacters: CharacterMatchFailure[]
}

export type BomItem = {
  id: string
  typeId: number
  typeName: string
  requiredQuantity: number
  fulfilledQuantity: number
  remainingQuantity: number
  fulfillmentPercent: number
  estimatedPrice: number | null
  estimatedTotal: number | null
  isJob: boolean
  jobGroup: JobGroup | null
  activityType: string | null
  parentTypeId: number | null
  meLevel: number | null
  teLevel: number | null
  runs: number | null
  isFulfilled: boolean
  jobMatches: JobMatch[]
  matchedRuns: number
  purchases: ProjectPurchase[]
  purchasedQuantity: number
}

// --- Contributions ---

export type GroupContribution = {
  id: string
  memberCharacterName: string | null
  memberId: string
  bomItemId: string | null
  bomItemTypeName: string | null
  type: ContributionType
  quantity: number
  estimatedValue: number | null
  status: ContributionStatus
  isAutoDetected: boolean
  isVerified: boolean
  reviewedByCharacterName: string | null
  reviewedAt: string | null
  note: string | null
  createdAt: string
}

// --- Members ---

export type GroupMember = {
  id: string
  characterName: string | null
  characterId: number | null
  corporationId: number | null
  corporationName: string | null
  role: GroupMemberRole
  status: GroupMemberStatus
  totalContributionValue: number | null
  contributionCount: number
  joinedAt: string
}

// --- Sales ---

export type GroupSale = {
  id: string
  typeId: number
  typeName: string
  quantity: number
  unitPrice: number
  totalPrice: number
  venue: string | null
  soldAt: string
  recordedByCharacterName: string | null
  sellerCharacterId: number | null
  sellerCharacterName: string | null
  linkedToWalletTransaction: boolean
  createdAt: string
}

export type GroupSaleSuggestion = {
  transactionId: string
  typeId: number
  typeName: string
  quantity: number
  unitPrice: number
  totalPrice: number
  date: string
  sellerCharacterId: number
  sellerCharacterName: string
  locationId: number
  possibleDuplicateSaleIds: string[]
}

// --- Distribution ---

export type MemberDistribution = {
  memberId: string
  characterName: string | null
  totalCostsEngaged: number | null
  materialCosts: number | null
  jobInstallCosts: number | null
  bpcCosts: number | null
  lineRentalCosts: number | null
  sharePercent: number | null
  profitPart: number | null
  payoutTotal: number | null
}

export type Distribution = {
  id: string
  totalRevenue: number
  brokerFee: number | null
  salesTax: number | null
  netRevenue: number | null
  totalProjectCost: number | null
  marginPercent: number | null
  members: MemberDistribution[]
}

// --- Container Verification ---

export type ContainerVerification = {
  bomItemId: string
  typeId: number
  typeName: string
  requiredQuantity: number
  containerQuantity: number | null
  status: ContainerVerificationStatus
  unknownReason: ContainerCheckUnknownReason | null
}

// --- Line Rental Rates ---

export type LineRentalRates = {
  id: string
  rates: Record<string, number>
}

// --- Input types for API calls ---

export type CreateGroupProjectInput = {
  name?: string
  private: boolean
  items: { typeId: number; typeName: string; meLevel: number; teLevel: number; runs: number }[]
  blacklistCategoryKeys?: string[]
  blacklistGroupIds?: number[]
  blacklistTypeIds?: number[]
  containerName?: string
  lineRentalRatesOverride?: Record<string, number>
  brokerFeePercent?: number
  salesTaxPercent?: number
  acceptsOutsideFavoriteSystem: boolean
  minimumOutsideSaving: number
  templateId?: string
}

export type AvailableJob = {
  esiJobId: number
  blueprintTypeId: number
  productTypeId: number
  productTypeName: string | null
  runs: number
  cost: number | null
  status: string
  startDate: string
  endDate: string | null
  characterName: string
  linkedToBomLineId: string | null
  linkedToBomLineName: string | null
  matchId: string | null
}

export type PurchaseSuggestion = {
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

export type PurchaseSuggestions = {
  suggestions: PurchaseSuggestion[]
  totalCount: number
}

export type LinkedTransactionPurchaseInput = {
  transactionId: string
}

export type ManualPurchaseInput = {
  typeId: number
  quantity: number
  unitPrice: number
}

export type CreatePurchaseInput = LinkedTransactionPurchaseInput | ManualPurchaseInput

export type SubmitContributionInput = {
  type: ContributionType
  bomItemId?: string
  quantity: number
  estimatedValue?: number
  note?: string
}

export type RecordSaleInput = {
  typeId: number
  typeName: string
  quantity: number
  unitPrice: number
  venue?: string
  soldAt?: string
  sellerCharacterId?: number
}

export type UpdateSaleInput = {
  typeId?: number
  typeName?: string
  quantity?: number
  unitPrice?: number
  venue?: string
  soldAt?: string
  sellerCharacterId?: number
}

export type RecordSaleFromWalletTransactionInput = {
  transactionId: string
  quantity?: number
}

// --- Cockpit ---

export type ProjectPhaseKey = 'prepare' | 'supply' | 'produce' | 'sell' | 'close'

export type ProjectPhaseState = 'done' | 'current' | 'blocked' | 'upcoming'

export type ProjectPhaseBlockedReasonCode = 'materials_missing' | 'jobs_unfinished'

export type ProjectNextActionCode =
  | 'project_completed'
  | 'review_contributions'
  | 'start_project'
  | 'source_material'
  | 'launch_job'
  | 'start_selling'
  | 'record_sale'
  | 'complete_project'
  | 'nothing_to_do'

export type ProjectNextActionTab = 'materials' | 'contributions' | 'sales' | 'finances' | 'members'

export type ProjectPhase = {
  key: ProjectPhaseKey
  state: ProjectPhaseState
  counter: { done: number; total: number }
  blockedReason: { code: ProjectPhaseBlockedReasonCode; count: number } | null
}

export type ProjectNextAction = {
  code: ProjectNextActionCode
  blocked: boolean
  tab: ProjectNextActionTab | null
  targetStatus: GroupProjectStatus | null
  bomLineId: string | null
  typeId: number | null
  typeName: string | null
  quantity: number | null
}

export type ProjectCockpit = {
  projectId: string
  status: GroupProjectStatus
  phases: ProjectPhase[]
  nextAction: ProjectNextAction
}

export type ChainStage = 'blueprint' | 'material' | 'component' | 'product' | 'sale'

export type ChainActivity = 'manufacturing' | 'reaction' | 'copy' | 'invention'

export type ChainStatus = 'done' | 'active' | 'todo' | 'blocked' | 'unknown'

export type ChainCostState = 'none' | 'unknown' | 'known'

export type PlannedStructureStatus =
  | 'assigned'
  | 'best_in_favorite_system'
  | 'best_configured'
  | 'best_outside_favorite_system'
  | 'best_by_total_cost'

export type ChainSupplier = {
  participantId: string
  characterName: string | null
  type: ContributionType
  quantity: number
}

export type ChainJob = {
  esiJobId: number
  status: string
  runs: number
  characterName: string | null
  endDate: string | null
  cost: number | null
  facilityName: string | null
}

export type ChainPlannedStructure = {
  name: string | null
  status: PlannedStructureStatus
}

export type ChainNode = {
  id: string
  stage: ChainStage
  parentId: string | null
  typeId: number | null
  typeName: string | null
  activity: ChainActivity | null
  runs: number | null
  meLevel: number | null
  teLevel: number | null
  requiredQuantity: number | null
  fulfilledQuantity: number | null
  status: ChainStatus
  costState: ChainCostState
  realCost: number | null
  suppliers: ChainSupplier[]
  jobs: ChainJob[]
  structures: string[]
  plannedStructure: ChainPlannedStructure | null
  soldQuantity: number | null
  grossRevenue: number | null
}

export type ProjectChain = {
  id: string
  nodes: ChainNode[]
}

export type BuyOrBuildChoice = 'build' | 'buy' | 'unknown'

export interface BuyOrBuildMissingData {
  reason: string
  typeId: number | null
}

export interface BuyOrBuildWarning {
  reason: string
  typeId: number
  coveragePercent: number
}

export interface BuyOrBuildRow {
  typeId: number
  typeName: string | null
  activity: 'manufacturing' | 'reaction'
  quantity: number
  runs: number
  producedQuantity: number
  surplus: number
  volumePerUnit: number | null
  jumps: number | null
  buildPerUnit: number | null
  buildPerUnitIfSurplusReused: number | null
  jitaPerUnit: number | null
  importPerUnit: number | null
  buyPerUnit: number | null
  choice: BuyOrBuildChoice
  choiceWithoutImport: BuyOrBuildChoice
  saving: number | null
  savingIfSurplusReused: number | null
  missingData: BuyOrBuildMissingData[]
  warnings: BuyOrBuildWarning[]
}

export interface ProjectBuyOrBuild {
  id: string
  importIskPerCubicMeterJump: number
  rows: BuyOrBuildRow[]
}

export type ReactionStepInput = {
  typeId: number
  typeName: string | null
  quantityPerRun: number
  totalQuantity: number
  isFuelBlock: boolean
}

export type ReactionStep = {
  typeId: number
  typeName: string
  runs: number
  producedQuantity: number
  surplusQuantity: number | null
  neededQuantity: number | null
  outputPerRun: number | null
  baseSecondsPerRun: number | null
  baseTotalSeconds: number | null
  inputs: ReactionStepInput[] | null
}

export type ProjectReactionSteps = {
  id: string
  steps: ReactionStep[]
}

export type PlanningActivity = 'manufacturing' | 'reaction'

export type PlanningWaitKind = 'waiting_job' | 'waiting_materials' | 'unknown'

export type PlanningUnscheduledReason = 'no_eligible_worker' | 'dependency_unscheduled' | 'duration_unknown'

export type PlanningWait = {
  kind: PlanningWaitKind
  names: string[]
  lateSeconds: number
}

export type PlanningJob = {
  lineId: string
  typeId: number
  typeName: string
  activity: PlanningActivity
  runs: number
  planned: { start: string; end: string } | null
  real: { start: string; end: string; status: 'done' | 'active' } | null
  wait: PlanningWait | null
}

export type PlanningCharacter = {
  characterName: string
  skillsUnknown: boolean
  slots: { manufacturing: number; reaction: number } | null
  skills: { industry: number; advancedIndustry: number; reactions: number } | null
  endsAt: string | null
  jobs: PlanningJob[]
}

export type PlanningUnscheduled = {
  lineId: string
  typeName: string
  reason: PlanningUnscheduledReason
}

export type ProjectPlanning = {
  id: string
  anchor: string
  structureTimeBonusIncluded: boolean
  plannedEnd: string | null
  expectedEnd: string | null
  delaySeconds: number | null
  delayCause: PlanningWait | null
  characters: PlanningCharacter[]
  unscheduled: PlanningUnscheduled[]
}

export type StructureChoiceStatus =
  | 'assigned'
  | 'best_in_favorite_system'
  | 'best_configured'
  | 'best_outside_favorite_system'
  | 'best_by_total_cost'
  | 'not_configured'

export interface StructureComparisonMissingData {
  reason: string
  typeId: number | null
}

export interface StructureComparisonRow {
  structureId: string
  name: string | null
  kind: string | null
  solarSystemId: number | null
  solarSystemName: string | null
  inFavoriteSystem: boolean
  jumps: number | null
  materialBonusPercent: number
  facilityTaxPercent: number
  materialsCost: number | null
  installationCost: number | null
  transportCost: number | null
  transportedCubicMeters: number | null
  totalCost: number | null
  delta: number | null
  missingData: StructureComparisonMissingData[]
}

export interface StructureComparisonGroup {
  typeId: number
  typeName: string | null
  activity: 'manufacturing' | 'reaction'
  jobs: number
  runs: number
  chosenStructureId: string | null
  choiceStatus: StructureChoiceStatus
  cheapestStructureId: string | null
  savingIfCheapest: number | null
  rows: StructureComparisonRow[]
}

export interface ProjectStructures {
  id: string
  acceptsOutsideFavoriteSystem: boolean
  minimumOutsideSaving: number
  transportIskPerCubicMeterJump: number
  groups: StructureComparisonGroup[]
}

export type CourierQuoteStatus = 'ready' | 'no_purchases' | 'volume_unknown' | 'workshop_unknown' | 'route_unknown'

export type CourierTransportState = 'not_shipped' | 'waiting' | 'in_transit' | 'delivered' | 'failed' | 'unknown'

export interface CourierLine {
  bomLineId: string
  typeId: number
  purchasedQuantity: number
  transportState: CourierTransportState
}

export interface CourierContractFigures {
  contractId: number
  characterId: number
  state: CourierTransportState
  volume: number | null
  reward: number | null
  collateral: number | null
  startLocationId: number | null
  endLocationId: number | null
  issuedAt: string
}

export interface CourierLinkedContract extends CourierContractFigures {
  unknownReason: string | null
  linkedAt: string
}

export interface CourierCandidate extends CourierContractFigures {
  matchesQuote: boolean
}

export interface ProjectCourier {
  id: string
  volumeCubicMeters: number | null
  collateral: number | null
  reward: number | null
  jumps: number | null
  quoteStatus: CourierQuoteStatus
  missingVolumeTypeIds: number[]
  daysToComplete: number
  expirationDays: number
  originSolarSystemId: number
  originSolarSystemName: string
  workshopSolarSystemId: number | null
  workshopSolarSystemName: string | null
  canLink: boolean
  contract: CourierLinkedContract | null
  lines: CourierLine[]
  candidates: CourierCandidate[]
}

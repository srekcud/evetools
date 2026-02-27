/**
 * Shared type definitions for the group industry module.
 * Types mirror the API resource DTOs from src/ApiResource/GroupIndustry/.
 */

export type GroupProjectStatus = 'draft' | 'published' | 'in_progress' | 'selling' | 'completed'

export type GroupMemberRole = 'owner' | 'admin' | 'member'

export type GroupMemberStatus = 'pending' | 'accepted'

export type ContributionType = 'material' | 'job_install' | 'bpc' | 'line_rental'

export type ContributionStatus = 'pending' | 'approved' | 'rejected'

export type ContainerVerificationStatus = 'verified' | 'partial' | 'unchecked'

export type JobGroup = 'blueprint' | 'component' | 'final'

// --- Project ---

export type GroupProjectItem = {
  typeId: number
  typeName: string
  meLevel: number
  teLevel: number
  runs: number
}

export type GroupProject = {
  id: string
  name: string | null
  status: GroupProjectStatus
  shortLinkCode: string
  containerName: string | null
  ownerCharacterName: string
  ownerCorporationId: number | null
  membersCount: number
  items: GroupProjectItem[]
  totalBomValue: number
  fulfillmentPercent: number
  brokerFeePercent: number
  salesTaxPercent: number
  lineRentalRatesOverride: Record<string, number> | null
  blacklistGroupIds: number[]
  blacklistTypeIds: number[]
  createdAt: string
  myRole: GroupMemberRole | null
}

// --- BOM ---

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
}

// --- Contributions ---

export type GroupContribution = {
  id: string
  memberCharacterName: string
  memberId: string
  bomItemId: string | null
  bomItemTypeName: string | null
  type: ContributionType
  quantity: number
  estimatedValue: number
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
  characterName: string
  characterId: number
  corporationId: number | null
  corporationName: string | null
  role: GroupMemberRole
  status: GroupMemberStatus
  totalContributionValue: number
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
  recordedByCharacterName: string
  createdAt: string
}

// --- Distribution ---

export type MemberDistribution = {
  memberId: string
  characterName: string
  totalCostsEngaged: number
  materialCosts: number
  jobInstallCosts: number
  bpcCosts: number
  lineRentalCosts: number
  sharePercent: number
  profitPart: number
  payoutTotal: number
}

export type Distribution = {
  id: string
  totalRevenue: number
  brokerFee: number
  salesTax: number
  netRevenue: number
  totalProjectCost: number
  marginPercent: number
  members: MemberDistribution[]
}

// --- Container Verification ---

export type ContainerVerification = {
  bomItemId: string
  typeId: number
  typeName: string
  requiredQuantity: number
  containerQuantity: number
  status: ContainerVerificationStatus
}

// --- Line Rental Rates ---

export type LineRentalRates = {
  id: string
  rates: Record<string, number>
}

// --- Input types for API calls ---

export type CreateGroupProjectInput = {
  name?: string
  items: { typeId: number; typeName: string; meLevel: number; teLevel: number; runs: number }[]
  blacklistCategoryKeys?: string[]
  blacklistGroupIds?: number[]
  blacklistTypeIds?: number[]
  containerName?: string
  lineRentalRatesOverride?: Record<string, number>
  brokerFeePercent?: number
  salesTaxPercent?: number
}

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
}

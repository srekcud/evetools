import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import type { BomItem, GroupMember, GroupProject, JobLocation, JobLocationStatus, JobLocations, JobMatch } from '@/stores/group-industry/types'

export const COMPANION_PROJECT_ID = 'project-ishtar-322'

export function bomLine(overrides: Partial<BomItem> & Pick<BomItem, 'id' | 'typeId' | 'typeName'>): BomItem {
  return {
    requiredQuantity: 1000,
    fulfilledQuantity: 0,
    remainingQuantity: 1000,
    fulfillmentPercent: 0,
    estimatedPrice: 5,
    estimatedTotal: 5000,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

export function jobMatch(overrides: Partial<JobMatch> & Pick<JobMatch, 'id' | 'status' | 'esiJobId'>): JobMatch {
  return {
    bomLineId: 'bom-job',
    cost: null,
    endDate: null,
    runs: 1,
    characterName: null,
    matchedAt: '2026-11-09T08:00:00+00:00',
    facilityId: null,
    facilityName: null,
    manual: false,
    ...overrides,
  }
}

export function companionProject(overrides: Partial<GroupProject> = {}): GroupProject {
  return {
    id: COMPANION_PROJECT_ID,
    name: 'Ishtar batch',
    status: 'in_progress',
    shortLinkCode: 'ishtar322',
    containerName: null,
    ownerCharacterName: 'Selene Vance',
    ownerCorporationId: 98_000_007,
    membersCount: 3,
    items: [{ typeId: 12_005, typeName: 'Ishtar', meLevel: 8, teLevel: 16, runs: 2 }],
    totalBomValue: 1_200_000_000,
    fulfillmentPercent: 0,
    approvedContributionValue: 0,
    pendingContributionCount: null,
    brokerFeePercent: 2.8,
    salesTaxPercent: 3.4,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-11-02T08:30:00+00:00',
    myRole: 'member',
    myStatus: 'accepted',
    private: false,
    notes: null,
    acceptsOutsideFavoriteSystem: false,
    excludesInvention: false,
    minimumOutsideSaving: 5,
    sellStructureId: null,
    sellStructureName: null,
    ...overrides,
  }
}

export function member(overrides: Partial<GroupMember> & Pick<GroupMember, 'id' | 'status'>): GroupMember {
  return {
    characterName: null,
    characterId: null,
    corporationId: null,
    corporationName: null,
    role: 'member',
    totalContributionValue: null,
    contributionCount: 0,
    joinedAt: '2026-11-03T10:00:00+00:00',
    ...overrides,
  }
}

export function companionTasksOf(overrides: Partial<CompanionTasks> = {}): CompanionTasks {
  return {
    free: [],
    mine: [],
    toReview: null,
    joinRequests: null,
    jobs: [],
    bomPercent: 0,
    finalJobsRunning: 0,
    alerts: [],
    moveProducts: { quantity: 0, jobCount: 0, unknownJobCount: 0 },
    priceAlert: null,
    undercutOrders: [],
    ...overrides,
  }
}

export function jobLocation(status: JobLocationStatus, unknownReason: string | null = null): JobLocation {
  return {
    locationId: status === 'unknown' ? null : 1_045_000_000_001,
    kind: status === 'unknown' ? null : 'container',
    name: status === 'unknown' ? null : 'Project container',
    divisionName: null,
    status,
    unknownReason,
  }
}

export function jobLocations(
  output: JobLocationStatus,
  options: { blueprint?: JobLocationStatus; unknownReason?: string } = {},
): JobLocations {
  return {
    blueprint: jobLocation(options.blueprint ?? 'inside'),
    output: jobLocation(output, options.unknownReason ?? null),
  }
}

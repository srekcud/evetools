import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import type {
  ActionPlace,
  BomItem,
  CharacterLocationRead,
  GroupMember,
  GroupProject,
  IntermediateStockProposal,
  IntermediateStockProposalLine,
  JobLocation,
  JobLocationStatus,
  JobLocations,
  JobMatch,
  PlacedAction,
  ProjectStationTransfer,
  StationTransferLine,
  StationTransferPlace,
} from '@/stores/group-industry/types'

export const COMPANION_PROJECT_ID = 'project-ishtar-322'
export const COMPANION_NOW = '2026-11-21T12:00:00+00:00'

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
    pendingContributions: [],
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
    containerNames: [],
    ownerCharacterName: 'Selene Vance',
    ownerCorporationId: 98_000_007,
    membersCount: 3,
    contributionsCount: 0,
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
    now: Date.parse(COMPANION_NOW),
    product: null,
    activityProgress: [],
    deliveredLines: 0,
    totalLines: 0,
    freeValue: { total: 0, unknownLines: 0 },
    readyJobCount: 0,
    free: [],
    mine: [],
    awaitingApproval: [],
    toReview: null,
    joinRequests: null,
    unassignedJobCount: null,
    jobs: [],
    bomPercent: 0,
    finalJobsRunning: 0,
    alerts: [],
    moveProducts: { quantity: 0, jobCount: 0, unknownJobCount: 0 },
    priceAlert: null,
    undercutOrders: [],
    waitingForComponents: [],
    hereSystems: ['Jita'],
    linePlaces: {},
    carries: [],
    carriesUnknown: false,
    supplies: [],
    reservationExpiries: {},
    joinRequestNames: [],
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

export const JITA_SYSTEM_ID = 30_000_142
export const JITA_STATION_ID = 60_003_760
export const PERIMETER_SYSTEM_ID = 30_000_144
export const MAIN_EVE_CHARACTER_ID = 2_112_000_001
export const ALT_EVE_CHARACTER_ID = 2_112_000_002

export function stationPlace(overrides: Partial<ActionPlace> = {}): ActionPlace {
  return {
    kind: 'station',
    locationId: JITA_STATION_ID,
    name: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    solarSystemId: JITA_SYSTEM_ID,
    solarSystemName: 'Jita',
    unknownReason: null,
    ...overrides,
  }
}

export function placedAction(overrides: Partial<PlacedAction> = {}): PlacedAction {
  return {
    id: 'action-1',
    projectId: COMPANION_PROJECT_ID,
    projectName: 'Ishtar batch',
    code: 'source_material',
    bomLineId: 'bom-line',
    typeId: 34,
    typeName: 'Tritanium',
    quantity: 1000,
    place: stationPlace(),
    here: true,
    ...overrides,
  }
}

export function characterLocation(overrides: Partial<CharacterLocationRead> = {}): CharacterLocationRead {
  return {
    id: 'character-main',
    eveCharacterId: MAIN_EVE_CHARACTER_ID,
    solarSystemId: JITA_SYSTEM_ID,
    solarSystemName: 'Jita',
    stationId: JITA_STATION_ID,
    structureId: null,
    online: true,
    lastLoginAt: null,
    ...overrides,
  }
}

export function transferPlace(overrides: Partial<StationTransferPlace> = {}): StationTransferPlace {
  return {
    solarSystemId: JITA_SYSTEM_ID,
    solarSystemName: 'Jita',
    locationId: JITA_STATION_ID,
    stationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    ...overrides,
  }
}

export function transferLine(overrides: Partial<StationTransferLine> = {}): StationTransferLine {
  return {
    typeId: 11_399,
    typeName: 'Morphite',
    quantity: 300,
    volume: 3,
    departed: null,
    arrived: null,
    inTransit: null,
    departureSource: 'declared',
    arrivalSource: 'pending',
    unknownReason: null,
    ...overrides,
  }
}

export function stationTransfer(overrides: Partial<ProjectStationTransfer> = {}): ProjectStationTransfer {
  return {
    id: 'transfer-1',
    routeId: 'route-1',
    legIndex: 0,
    legCount: 1,
    from: transferPlace(),
    to: transferPlace({
      solarSystemId: PERIMETER_SYSTEM_ID,
      solarSystemName: 'Perimeter',
      locationId: 1_040_000_000_001,
      stationName: 'Perimeter - Tranquility Trading Tower',
    }),
    carrier: { kind: 'pilot', characterId: MAIN_EVE_CHARACTER_ID, contractId: null },
    declaredBy: 'participant-alpha',
    declaredAt: '2026-11-21T10:00:00+00:00',
    expectedArrivalAt: '2026-11-21T14:00:00+00:00',
    arrivedAt: null,
    state: 'in_transit',
    volume: 900,
    unknownReason: null,
    lines: [transferLine()],
    ...overrides,
  }
}

export function stockProposalLine(overrides: Partial<IntermediateStockProposalLine> = {}): IntermediateStockProposalLine {
  return {
    bomItemId: 'bom-line',
    typeId: 11_399,
    typeName: 'Morphite',
    demandQuantity: 300,
    remainingQuantity: 300,
    personalQuantity: 300,
    containerQuantity: 0,
    stockQuantity: 300,
    proposedQuantity: 300,
    stockByStation: [{ locationId: JITA_STATION_ID, locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', systemName: 'Jita', quantity: 300, onSite: true }],
    plannedStationName: null,
    ...overrides,
  }
}

export function stockProposal(overrides: Partial<IntermediateStockProposal> = {}): IntermediateStockProposal {
  return {
    id: 'proposal-1',
    state: 'ready',
    unknownReason: null,
    lines: [stockProposalLine()],
    ...overrides,
  }
}

import type {
  CourierCandidate,
  CourierLine,
  CourierLinkedContract,
  CourierOtherOriginLine,
  CourierTransportState,
  CourierUnknownOriginLine,
  ProjectCourier,
} from '@/stores/group-industry/types'

export const TRITANIUM_TYPE_ID = 34
export const NOCXIUM_TYPE_ID = 38
export const OUTSTANDING_CONTRACT_ID = 351502
export const IN_TRANSIT_CONTRACT_ID = 351501
export const MORPHITE_TYPE_ID = 11399
export const AMARR_SOLAR_SYSTEM_ID = 30002187
export const RENS_SOLAR_SYSTEM_ID = 30002510
export const LONG_SYSTEM_NAME = 'Uttindar Prime Annex Outpost Constellation Gateway'

export const OTHER_ORIGIN_LINES: CourierOtherOriginLine[] = [
  { solarSystemId: AMARR_SOLAR_SYSTEM_ID, solarSystemName: 'Amarr', typeId: MORPHITE_TYPE_ID, typeName: 'Morphite', quantity: 120 },
  { solarSystemId: RENS_SOLAR_SYSTEM_ID, solarSystemName: LONG_SYSTEM_NAME, typeId: NOCXIUM_TYPE_ID, typeName: 'Nocxium', quantity: 40 },
]

export const UNKNOWN_ORIGIN_LINES: CourierUnknownOriginLine[] = [
  { typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 3400 },
  { typeId: NOCXIUM_TYPE_ID, typeName: 'Nocxium', quantity: 75 },
]

export function courierLines(transportState: CourierTransportState): CourierLine[] {
  return [
    { bomLineId: 'line-tritanium', typeId: TRITANIUM_TYPE_ID, purchasedQuantity: 8000, transportState },
    { bomLineId: 'line-nocxium', typeId: NOCXIUM_TYPE_ID, purchasedQuantity: 150, transportState },
  ]
}

export const MATCHING_CANDIDATE: CourierCandidate = {
  contractId: OUTSTANDING_CONTRACT_ID,
  characterId: 2112351001,
  state: 'waiting',
  volume: 953.5,
  reward: 1144200,
  collateral: 22632500,
  startLocationId: 60003760,
  startLocationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
  endLocationId: 1035466617946,
  endLocationName: 'Perimeter - Ishtar Workshop',
  issuedAt: '2026-11-05T10:00:00+00:00',
  matchesQuote: true,
}

export const OTHER_CANDIDATE: CourierCandidate = {
  contractId: IN_TRANSIT_CONTRACT_ID,
  characterId: 2112351001,
  state: 'in_transit',
  volume: 40,
  reward: 9000,
  collateral: 5000000,
  startLocationId: 60003760,
  startLocationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
  endLocationId: 1035466617946,
  endLocationName: null,
  issuedAt: '2026-11-03T10:00:00+00:00',
  matchesQuote: false,
}

export const LINKED_IN_TRANSIT: CourierLinkedContract = {
  ...OTHER_CANDIDATE,
  unknownReason: null,
  linkedAt: '2026-11-06T09:00:00+00:00',
}

export function readyCourier(projectId: string, overrides: Partial<ProjectCourier> = {}): ProjectCourier {
  return {
    id: projectId,
    volumeCubicMeters: 953.5,
    collateral: 22632500,
    reward: 1144200,
    jumps: 4,
    quoteStatus: 'ready',
    missingVolumeTypeIds: [],
    daysToComplete: 3,
    expirationDays: 7,
    originSolarSystemId: 30000142,
    originSolarSystemName: 'Jita',
    courierHubSolarSystemId: 30000772,
    courierHubSolarSystemName: 'C-J6MT',
    workshopSolarSystemId: 30000144,
    workshopSolarSystemName: 'Perimeter',
    canLink: true,
    contract: null,
    lines: courierLines('not_shipped'),
    candidates: [MATCHING_CANDIDATE, OTHER_CANDIDATE],
    otherOriginLines: [],
    unknownOriginLines: [],
    ...overrides,
  }
}

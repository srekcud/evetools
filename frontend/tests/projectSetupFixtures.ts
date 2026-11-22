import type { Mock } from 'vitest'
import type {
  ProjectWorkStations,
  SourcingDecisions,
  SourcingOption,
  SourcingRow,
  WorkStationRow,
  WorkStationType,
} from '@/stores/group-industry/types'

export const REACTION_RECOMMENDED_ID = '1001'
export const REACTION_ALTERNATIVE_ID = '1002'
export const COMPONENTS_RECOMMENDED_ID = '2001'
export const COMPONENTS_ALTERNATIVE_ID = '2002'
export const COPY_RECOMMENDED_ID = '3001'
export const COPY_CHOSEN_ID = '3002'

export const COMPONENT_TYPE_ID = 11
export const REACTION_TYPE_ID = 22
export const BASE_TYPE_ID = 33

export const STATION_NAMES: Record<string, string> = {
  [REACTION_RECOMMENDED_ID]: 'Tatara Isanamo Reactions',
  [REACTION_ALTERNATIVE_ID]: 'Athanor Kaimon',
  [COMPONENTS_RECOMMENDED_ID]: 'Raitaru Jita Yard',
  [COMPONENTS_ALTERNATIVE_ID]: 'Astrahus Perimeter',
}

export function stationRow(structureId: string, overrides: Partial<WorkStationRow> = {}): WorkStationRow {
  return {
    structureId,
    name: STATION_NAMES[structureId] ?? null,
    kind: 'Athanor',
    solarSystemId: 30_000_001,
    solarSystemName: 'Isanamo',
    inFavoriteSystem: false,
    jumps: null,
    materialBonusPercent: 2.4,
    facilityTaxPercent: 0.25,
    materialsCost: 800,
    installationCost: 160,
    transportCost: 0,
    transportLegs: [],
    totalCost: 1000,
    delta: 0,
    missingData: [],
    ...overrides,
  }
}

export function unknownAmountsRow(structureId: string, overrides: Partial<WorkStationRow> = {}): WorkStationRow {
  return stationRow(structureId, {
    materialsCost: null,
    installationCost: null,
    transportCost: null,
    totalCost: null,
    delta: null,
    missingData: [{ reason: 'no_market_price', typeId: 16_671 }],
    ...overrides,
  })
}

export function reactionType(overrides: Partial<WorkStationType> = {}): WorkStationType {
  return {
    workType: 'reaction',
    activity: 'reaction',
    jobs: 3,
    runs: 12,
    recommendedStructureId: REACTION_RECOMMENDED_ID,
    chosenStructureId: null,
    storedRecommendedStructureId: null,
    choiceStale: false,
    savingIfRecommended: null,
    locked: false,
    lockReason: null,
    costsCompared: true,
    rows: [
      stationRow(REACTION_RECOMMENDED_ID),
      stationRow(REACTION_ALTERNATIVE_ID, {
        solarSystemName: 'Kaimon',
        materialBonusPercent: 1.8,
        materialsCost: 960,
        installationCost: 200,
        transportCost: 40,
        transportLegs: [{ kind: 'courier', fromStructureId: REACTION_ALTERNATIVE_ID, toStructureId: COMPONENTS_RECOMMENDED_ID, cubicMeters: 5000, cost: 40 }],
        totalCost: 1200,
        delta: 200,
      }),
    ],
    ...overrides,
  }
}

export function componentsType(overrides: Partial<WorkStationType> = {}): WorkStationType {
  return {
    workType: 'components',
    activity: 'manufacturing',
    jobs: 5,
    runs: 20,
    recommendedStructureId: COMPONENTS_RECOMMENDED_ID,
    chosenStructureId: null,
    storedRecommendedStructureId: null,
    choiceStale: false,
    savingIfRecommended: null,
    locked: false,
    lockReason: null,
    costsCompared: true,
    rows: [
      stationRow(COMPONENTS_RECOMMENDED_ID, {
        solarSystemName: 'Jita',
        kind: 'Raitaru',
        materialBonusPercent: 1,
        materialsCost: 400,
        installationCost: 100,
        totalCost: 500,
        delta: 0,
      }),
      stationRow(COMPONENTS_ALTERNATIVE_ID, {
        solarSystemName: 'Perimeter',
        kind: 'Astrahus',
        materialBonusPercent: 0.5,
        materialsCost: 350,
        installationCost: 90,
        transportCost: 10,
        transportLegs: [{ kind: 'jump_bridge', fromStructureId: COMPONENTS_ALTERNATIVE_ID, toStructureId: COMPONENTS_RECOMMENDED_ID, cubicMeters: 1200, cost: 10 }],
        totalCost: 450,
        delta: -50,
      }),
    ],
    ...overrides,
  }
}

export function copyType(overrides: Partial<WorkStationType> = {}): WorkStationType {
  return {
    workType: 'blueprints',
    activity: 'copy',
    jobs: 2,
    runs: 4,
    recommendedStructureId: COPY_RECOMMENDED_ID,
    chosenStructureId: COPY_CHOSEN_ID,
    storedRecommendedStructureId: null,
    choiceStale: false,
    savingIfRecommended: null,
    locked: false,
    lockReason: null,
    costsCompared: false,
    rows: [
      unknownAmountsRow(COPY_RECOMMENDED_ID, { name: 'Raitaru Copy Lab', missingData: [] }),
      unknownAmountsRow(COPY_CHOSEN_ID, { name: 'Athanor Invention Bay', missingData: [] }),
    ],
    ...overrides,
  }
}

export function projectWorkStations(workTypes: WorkStationType[] = [reactionType(), componentsType()]): ProjectWorkStations {
  return {
    id: 'project-nyx-batch',
    courierHubSolarSystemId: 30_000_142,
    courierIskPerCubicMeter: 800,
    jumpBridgeIskPerCubicMeter: 150,
    workTypes,
  }
}

export function sourcingOption(overrides: Partial<SourcingOption> = {}): SourcingOption {
  return {
    total: 100,
    perUnit: 1,
    price: 100,
    installation: null,
    transport: null,
    eligible: true,
    availableQuantity: null,
    structureId: null,
    missingData: [],
    ...overrides,
  }
}

export function sourcingRow(typeId: number, overrides: Partial<SourcingRow> = {}): SourcingRow {
  return {
    typeId,
    typeName: `Type ${typeId}`,
    stage: 'component',
    activity: 'manufacturing',
    quantity: 10,
    runs: 1,
    surplus: 0,
    consumerStructureIds: [],
    options: { build: sourcingOption(), jita: sourcingOption() },
    recommended: 'build',
    chosen: 'build',
    bought: false,
    ...overrides,
  }
}

export const COMPONENT_ROW: SourcingRow = sourcingRow(COMPONENT_TYPE_ID, {
  typeName: 'Capital Propulsion Engine',
  stage: 'component',
  activity: 'manufacturing',
  quantity: 300,
  runs: 6,
  consumerStructureIds: [COMPONENTS_RECOMMENDED_ID],
  options: {
    build: sourcingOption({ total: 900, perUnit: 3, price: 600, installation: 200, transport: 100 }),
    jita: sourcingOption({ total: 1200, perUnit: 4, price: 1050, transport: 150 }),
    structure: sourcingOption({ total: 1050, perUnit: 3.5, price: 1050, eligible: false, availableQuantity: 140, structureId: '9001' }),
  },
  recommended: 'build',
  chosen: 'build',
})

export const REACTION_ROW: SourcingRow = sourcingRow(REACTION_TYPE_ID, {
  typeName: 'Fernite Carbide',
  stage: 'reaction',
  activity: 'reaction',
  quantity: 100,
  runs: 2,
  consumerStructureIds: [REACTION_RECOMMENDED_ID],
  options: {
    build: sourcingOption({ total: 250, perUnit: 2.5, price: 200, installation: 30, transport: 20 }),
    jita: sourcingOption({ total: 200, perUnit: 2, price: 170, transport: 30 }),
    structure: sourcingOption({ total: 150, perUnit: 1.5, price: 150, availableQuantity: 400, structureId: '9001' }),
  },
  recommended: 'jita',
  chosen: 'jita',
  bought: true,
})

export const BASE_ROW: SourcingRow = sourcingRow(BASE_TYPE_ID, {
  typeName: 'Tritanium',
  stage: 'base',
  activity: null,
  quantity: 5000,
  runs: null,
  options: {
    jita: sourcingOption({ total: 80, perUnit: 0.016, price: 75, transport: 5 }),
    structure: sourcingOption({ total: 90, perUnit: 0.018, price: 90, availableQuantity: 9000, structureId: '9001' }),
  },
  recommended: 'jita',
  chosen: 'jita',
  bought: true,
})

export function sourcingDecisions(total: number | null = 1000, rows: SourcingRow[] = [COMPONENT_ROW, REACTION_ROW, BASE_ROW]): SourcingDecisions {
  return {
    id: 'project-nyx-batch',
    rows,
    totals: { purchases: 400, installation: 230, transport: 120, total, missingData: [] },
    boughtTypeIds: rows.filter(row => row.bought).map(row => row.typeId),
  }
}

export type LoggedRequest = { method: string; path: string; body: unknown }

type RequestOptions = { method?: string; body?: string } | undefined

export function requestsOf(apiRequest: Mock): LoggedRequest[] {
  return apiRequest.mock.calls.map(call => {
    const path = call[0] as string
    const options = call[1] as RequestOptions
    return {
      method: options?.method ?? 'GET',
      path,
      body: options?.body === undefined ? undefined : JSON.parse(options.body),
    }
  })
}

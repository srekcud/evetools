import type {
  FactoryProjectStockLine,
  FactoryStation,
  PersonalStockContainer,
  SlotTrackerCharacter,
  StockpileItemStatus,
  StockpileStageStatus,
  StockpileStatus,
} from '@/stores/industry/types'

export const STATIONS_URL = '/api/industry/factory/stations'
export const PROJECT_STOCK_URL = '/api/industry/factory/project-stock'
export const CONTAINERS_URL = '/api/industry/settings/personal-stock-containers'

export const RAITARU_ALPHA: FactoryStation = {
  id: '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e01',
  name: 'Raitaru Alpha',
  structureType: 'raitaru',
  securityType: 'highsec',
  locationId: 1_031_400_000_100,
  solarSystemId: 30_000_142,
  solarSystemName: 'Jita',
  specialisations: ['manufacturing'],
  manufacturingCostIndex: 0.0412,
  reactionCostIndex: 0.0187,
  access: [
    { characterId: 2_131_400_001, characterName: 'Aria Solberg', status: 'granted' },
    { characterId: 2_131_400_002, characterName: 'Brann Holt', status: 'denied' },
    { characterId: 2_131_400_003, characterName: 'Cira Venn', status: 'unknown' },
  ],
  linesInUse: 3,
  projects: [
    { id: '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4f01', name: 'Rifter batch' },
    { id: '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4f02', name: 'Merlin batch' },
  ],
}

export const UNLOCATED_BETA: FactoryStation = {
  id: '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e02',
  name: 'Unlocated Beta',
  structureType: 'athanor',
  securityType: 'nullsec',
  locationId: null,
  solarSystemId: null,
  solarSystemName: null,
  specialisations: [],
  manufacturingCostIndex: null,
  reactionCostIndex: null,
  access: [
    { characterId: 2_131_400_001, characterName: 'Aria Solberg', status: 'unknown' },
  ],
  linesInUse: null,
  projects: [],
}

export const PROJECT_STOCK: FactoryProjectStockLine[] = [
  {
    id: 'rifter:34:reserved',
    projectId: 'rifter',
    projectName: 'Rifter batch',
    projectStatus: 'in_progress',
    typeId: 34,
    typeName: 'Tritanium',
    state: 'reserved',
    quantity: 60,
  },
  {
    id: 'ishtar:35:consumed',
    projectId: 'ishtar',
    projectName: 'Ishtar batch',
    projectStatus: 'completed',
    typeId: 35,
    typeName: 'Pyerite',
    state: 'consumed',
    quantity: 80,
  },
  {
    id: 'ishtar:35:returned',
    projectId: 'ishtar',
    projectName: 'Ishtar batch',
    projectStatus: 'completed',
    typeId: 35,
    typeName: 'Pyerite',
    state: 'returned',
    quantity: 15,
  },
]

export const KNOWN_CONTAINER: PersonalStockContainer = {
  id: '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d5001',
  itemId: 1_132_401_000_002,
  name: 'Perso A',
  divisionName: 'Reactions',
  stationId: 60_003_760,
  stationName: 'Jita IV - Moon 4',
  status: 'known',
  unknownReason: null,
  itemCount: 7,
}

export const UNKNOWN_CONTAINER: PersonalStockContainer = {
  id: '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d5002',
  itemId: 1_132_401_000_003,
  name: 'Perso B',
  divisionName: null,
  stationId: null,
  stationName: null,
  status: 'unknown',
  unknownReason: 'not_in_assets',
  itemCount: null,
}

export const STOCKPILE_STATUS_URL = '/api/industry/stockpile-status'
export const SLOTS_URL = '/api/industry/slots'

function stockpileItem(overrides: Partial<StockpileItemStatus> & Pick<StockpileItemStatus, 'id' | 'typeId' | 'typeName'>): StockpileItemStatus {
  return {
    targetQuantity: 200,
    remainingTargetQuantity: 200,
    stock: 120,
    percent: 60,
    status: 'partial',
    unitPrice: null,
    stockValue: null,
    deficit: 80,
    deficitCost: null,
    sourceProductTypeId: null,
    locations: [{ locationId: 60_003_760, locationName: 'Jita IV - Moon 4', systemName: 'Jita', quantity: 120 }],
    suggestedProjectRuns: null,
    ...overrides,
  }
}

function stage(items: StockpileItemStatus[]): StockpileStageStatus {
  return { items, totalValue: null, healthPercent: 0 }
}

export const ISHTAR_TARGET = stockpileItem({ id: 'target-ishtar', typeId: 12_005, typeName: 'Ishtar', targetQuantity: 50, stock: 12, deficit: 38, suggestedProjectRuns: 4 })
export const RIFTER_TARGET = stockpileItem({ id: 'target-rifter', typeId: 587, typeName: 'Rifter', targetQuantity: 30, stock: 30, deficit: 0, status: 'met', percent: 100, suggestedProjectRuns: null })
export const TRITANIUM_TARGET = stockpileItem({ id: 'target-tritanium', typeId: 34, typeName: 'Tritanium', targetQuantity: 900, stock: 100, deficit: 800 })

export const STOCKPILE_STATUS: StockpileStatus = {
  targetCount: 3,
  stages: {
    raw_material: stage([TRITANIUM_TARGET]),
    intermediate: stage([]),
    component: stage([]),
    final_product: stage([ISHTAR_TARGET, RIFTER_TARGET]),
  },
  kpis: { pipelineHealth: 0, totalInvested: null, bottleneck: null, estOutput: { ready: 0, total: 0, readyNames: [] } },
  shoppingList: [],
  importsWithoutPlan: [],
  failedProducts: [],
  missingPriceTypeIds: [],
}

export const SLOT_CHARACTERS: SlotTrackerCharacter[] = [
  {
    characterId: 2_131_400_001,
    characterName: 'Aria Solberg',
    isMain: true,
    slots: { manufacturing: { used: 3, max: 8 }, reaction: { used: 2, max: 5 }, science: { used: 1, max: 4 } },
    skillsUnknown: false,
    jobsStale: false,
    jobs: [],
    freeSlots: [],
  },
  {
    characterId: 2_131_400_002,
    characterName: 'Brann Holt',
    isMain: false,
    slots: { manufacturing: { used: 2, max: null }, reaction: { used: 1, max: null }, science: { used: 1, max: null } },
    skillsUnknown: true,
    jobsStale: false,
    jobs: [],
    freeSlots: [],
  },
]

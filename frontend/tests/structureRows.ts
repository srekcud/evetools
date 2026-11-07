import type { ProjectStructures, StructureComparisonGroup, StructureComparisonRow } from '@/stores/group-industry/types'

export function structureRow(overrides: Partial<StructureComparisonRow>): StructureComparisonRow {
  return {
    structureId: '',
    name: null,
    kind: null,
    solarSystemId: null,
    solarSystemName: null,
    inFavoriteSystem: false,
    jumps: null,
    materialBonusPercent: 0,
    facilityTaxPercent: 0,
    materialsCost: null,
    installationCost: null,
    transportCost: null,
    transportedCubicMeters: null,
    totalCost: null,
    delta: null,
    missingData: [],
    ...overrides,
  }
}

export function structureGroup(overrides: Partial<StructureComparisonGroup>): StructureComparisonGroup {
  return {
    typeId: 0,
    typeName: null,
    activity: 'manufacturing',
    jobs: 1,
    runs: 1,
    chosenStructureId: null,
    choiceStatus: 'not_configured',
    cheapestStructureId: null,
    savingIfCheapest: null,
    rows: [],
    ...overrides,
  }
}

export const ISHTAR_TYPE_ID = 12_005
export const FERNITE_COMPOSITE_TYPE_ID = 16_670
export const HOBGOBLIN_II_TYPE_ID = 2_456
export const MORPHITE_TYPE_ID = 11_399

export const WORKSHOP_STRUCTURE_ID = 'structure-perimeter-raitaru'
export const THREE_JUMPS_STRUCTURE_ID = 'structure-maurasi-azbel'
export const EIGHT_JUMPS_STRUCTURE_ID = 'structure-sivala-sotiyo'
export const REFINERY_STRUCTURE_ID = 'structure-urlen-athanor'

export const WORKSHOP_NAME = 'Perimeter Raitaru'
export const THREE_JUMPS_NAME = 'Maurasi Azbel'

function workshopRow(delta: number): StructureComparisonRow {
  return structureRow({
    structureId: WORKSHOP_STRUCTURE_ID,
    name: WORKSHOP_NAME,
    kind: 'raitaru',
    solarSystemId: 30_000_144,
    solarSystemName: 'Perimeter',
    inFavoriteSystem: true,
    jumps: 0,
    materialBonusPercent: 0,
    facilityTaxPercent: 1,
    materialsCost: 10_000_000,
    installationCost: 900_000,
    transportCost: 0,
    transportedCubicMeters: 2_000,
    totalCost: 10_900_000,
    delta,
  })
}

function threeJumpsRow(delta: number): StructureComparisonRow {
  return structureRow({
    structureId: THREE_JUMPS_STRUCTURE_ID,
    name: THREE_JUMPS_NAME,
    kind: 'azbel',
    solarSystemId: 30_000_139,
    solarSystemName: 'Maurasi',
    jumps: 3,
    materialBonusPercent: 10,
    facilityTaxPercent: 0.5,
    materialsCost: 9_000_000,
    installationCost: 850_000,
    transportCost: 900_000,
    transportedCubicMeters: 2_000,
    totalCost: 10_750_000,
    delta,
  })
}

function eightJumpsRow(delta: number): StructureComparisonRow {
  return structureRow({
    structureId: EIGHT_JUMPS_STRUCTURE_ID,
    name: 'Sivala Sotiyo',
    kind: 'sotiyo',
    solarSystemId: 30_000_153,
    solarSystemName: 'Sivala',
    jumps: 8,
    materialBonusPercent: 10,
    facilityTaxPercent: 0.75,
    materialsCost: 9_000_000,
    installationCost: 850_000,
    transportCost: 2_400_000,
    transportedCubicMeters: 2_000,
    totalCost: 12_250_000,
    delta,
  })
}

export const ISHTAR_FAVORITE_SYSTEM_RETAINED = structureGroup({
  typeId: ISHTAR_TYPE_ID,
  typeName: 'Ishtar',
  activity: 'manufacturing',
  jobs: 2,
  runs: 10,
  chosenStructureId: WORKSHOP_STRUCTURE_ID,
  choiceStatus: 'best_in_favorite_system',
  cheapestStructureId: THREE_JUMPS_STRUCTURE_ID,
  savingIfCheapest: 150_000,
  rows: [workshopRow(0), threeJumpsRow(-150_000), eightJumpsRow(1_350_000)],
})

export const ISHTAR_CHEAPEST_RETAINED = structureGroup({
  typeId: ISHTAR_TYPE_ID,
  typeName: 'Ishtar',
  activity: 'manufacturing',
  jobs: 2,
  runs: 10,
  chosenStructureId: THREE_JUMPS_STRUCTURE_ID,
  choiceStatus: 'best_by_total_cost',
  cheapestStructureId: THREE_JUMPS_STRUCTURE_ID,
  savingIfCheapest: null,
  rows: [workshopRow(150_000), threeJumpsRow(0), eightJumpsRow(1_500_000)],
})

export const FERNITE_COMPOSITE_UNKNOWN_PRICE = structureGroup({
  typeId: FERNITE_COMPOSITE_TYPE_ID,
  typeName: 'Fernite Composite',
  activity: 'reaction',
  jobs: 1,
  runs: 5,
  chosenStructureId: REFINERY_STRUCTURE_ID,
  choiceStatus: 'best_configured',
  cheapestStructureId: null,
  savingIfCheapest: null,
  rows: [
    structureRow({
      structureId: REFINERY_STRUCTURE_ID,
      name: 'Urlen Athanor',
      kind: 'athanor',
      solarSystemId: 30_000_138,
      solarSystemName: 'Urlen',
      jumps: 3,
      materialBonusPercent: 2.4,
      facilityTaxPercent: 0.25,
      materialsCost: null,
      installationCost: 425_000,
      transportCost: 450_000,
      transportedCubicMeters: 1_000,
      totalCost: null,
      delta: null,
      missingData: [{ reason: 'market_price', typeId: MORPHITE_TYPE_ID }],
    }),
  ],
})

export const HOBGOBLIN_II_WITHOUT_STRUCTURE = structureGroup({
  typeId: HOBGOBLIN_II_TYPE_ID,
  typeName: 'Hobgoblin II',
  activity: 'manufacturing',
  jobs: 1,
  runs: 5,
  chosenStructureId: null,
  choiceStatus: 'not_configured',
  rows: [],
})

export function projectStructures(
  projectId: string,
  groups: StructureComparisonGroup[],
  project: { acceptsOutsideFavoriteSystem?: boolean, minimumOutsideSaving?: number } = {},
): ProjectStructures {
  return {
    id: projectId,
    acceptsOutsideFavoriteSystem: project.acceptsOutsideFavoriteSystem ?? false,
    minimumOutsideSaving: project.minimumOutsideSaving ?? 100_000,
    transportIskPerCubicMeterJump: 150,
    groups,
  }
}

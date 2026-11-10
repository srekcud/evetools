import type { BuyOrBuildRow, ProjectBuyOrBuild } from '@/stores/group-industry/types'

export function buyOrBuildRow(overrides: Partial<BuyOrBuildRow>): BuyOrBuildRow {
  return {
    typeId: 0,
    typeName: null,
    activity: 'manufacturing',
    blacklisted: false,
    quantity: 0,
    runs: 1,
    producedQuantity: 0,
    surplus: 0,
    volumePerUnit: null,
    jumps: null,
    buildPerUnit: null,
    buildPerUnitIfSurplusReused: null,
    jitaPerUnit: null,
    importPerUnit: null,
    buyPerUnit: null,
    choice: 'unknown',
    choiceWithoutImport: 'unknown',
    saving: null,
    savingIfSurplusReused: null,
    missingData: [],
    warnings: [],
    ...overrides,
  }
}

export const HOBGOBLIN_II_TYPE_ID = 2_456
export const FERNITE_COMPOSITE_TYPE_ID = 16_670
export const OGRE_II_TYPE_ID = 2_446
export const WARRIOR_II_TYPE_ID = 2_488

export const HOBGOBLIN_II_BUILT = buyOrBuildRow({
  typeId: HOBGOBLIN_II_TYPE_ID,
  typeName: 'Hobgoblin II',
  activity: 'manufacturing',
  quantity: 50,
  runs: 5,
  producedQuantity: 50,
  surplus: 0,
  volumePerUnit: 10,
  jumps: 9,
  buildPerUnit: 33_740,
  jitaPerUnit: 31_900,
  importPerUnit: 13_500,
  buyPerUnit: 45_400,
  choice: 'build',
  choiceWithoutImport: 'buy',
  saving: 583_000,
})

export const FERNITE_COMPOSITE_BOUGHT = buyOrBuildRow({
  typeId: FERNITE_COMPOSITE_TYPE_ID,
  typeName: 'Fernite Composite',
  activity: 'reaction',
  quantity: 50,
  runs: 5,
  producedQuantity: 500,
  surplus: 450,
  volumePerUnit: 1,
  jumps: 9,
  buildPerUnit: 314_000,
  buildPerUnitIfSurplusReused: 31_400,
  jitaPerUnit: 30_900,
  importPerUnit: 1_350,
  buyPerUnit: 32_250,
  choice: 'buy',
  choiceWithoutImport: 'buy',
  saving: 14_087_500,
  savingIfSurplusReused: 42_500,
})

export const OGRE_II_WITHOUT_ROUTE = buyOrBuildRow({
  typeId: OGRE_II_TYPE_ID,
  typeName: 'Ogre II',
  activity: 'manufacturing',
  quantity: 40,
  runs: 4,
  producedQuantity: 40,
  surplus: 0,
  volumePerUnit: 25,
  jumps: null,
  buildPerUnit: 41_200,
  jitaPerUnit: 38_700,
  choice: 'unknown',
  choiceWithoutImport: 'buy',
  missingData: [{ reason: 'jump_route', typeId: null }],
})

export const WARRIOR_II_PARTIAL_PRICE = buyOrBuildRow({
  typeId: WARRIOR_II_TYPE_ID,
  typeName: 'Warrior II',
  activity: 'manufacturing',
  quantity: 30,
  runs: 3,
  producedQuantity: 30,
  volumePerUnit: 5,
  jumps: 4,
  buildPerUnit: 19_800,
  jitaPerUnit: 17_600,
  importPerUnit: 3_000,
  buyPerUnit: 20_600,
  choice: 'build',
  choiceWithoutImport: 'buy',
  saving: 24_000,
  warnings: [{ reason: 'partial_market_depth', typeId: WARRIOR_II_TYPE_ID, coveragePercent: 60 }],
})

export function projectBuyOrBuild(projectId: string, rows: BuyOrBuildRow[]): ProjectBuyOrBuild {
  return { id: projectId, importIskPerCubicMeterJump: 150, rows }
}

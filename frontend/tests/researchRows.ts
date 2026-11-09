import type { ProjectResearch, ResearchRow } from '@/stores/group-industry/types'

export function researchRow(overrides: Partial<ResearchRow>): ResearchRow {
  return {
    typeId: 0,
    typeName: null,
    currentLevel: 0,
    targetLevel: 1,
    runs: 1,
    materialSaving: null,
    researchCost: null,
    net: null,
    verdict: 'unknown',
    missingData: [],
    ...overrides,
  }
}

export const HOBGOBLIN_II_TYPE_ID = 2_456
export const ISHTAR_TYPE_ID = 12_005
export const OGRE_II_TYPE_ID = 2_446
export const WARRIOR_II_TYPE_ID = 2_488
export const FERNITE_COMPOSITE_TYPE_ID = 16_670
export const HECATE_TYPE_ID = 35_683

export const HOBGOBLIN_II_WORTH = researchRow({
  typeId: HOBGOBLIN_II_TYPE_ID,
  typeName: 'Hobgoblin II',
  currentLevel: 0,
  targetLevel: 1,
  runs: 10,
  materialSaving: 20_000,
  researchCost: 14_500,
  net: 5_500,
  verdict: 'worth',
})

export const ISHTAR_WORTH = researchRow({
  typeId: ISHTAR_TYPE_ID,
  typeName: 'Ishtar',
  currentLevel: 3,
  targetLevel: 4,
  runs: 24,
  materialSaving: 1_250_000,
  researchCost: 310_400,
  net: 939_600,
  verdict: 'worth',
})

export const OGRE_II_UNKNOWN_COST = researchRow({
  typeId: OGRE_II_TYPE_ID,
  typeName: 'Ogre II',
  currentLevel: 5,
  targetLevel: 6,
  runs: 8,
  materialSaving: 18_400,
  researchCost: null,
  net: null,
  verdict: 'unknown',
  missingData: [{ reason: 'research_cost_index', typeId: null }],
})

export const WARRIOR_II_NOT_WORTH = researchRow({
  typeId: WARRIOR_II_TYPE_ID,
  typeName: 'Warrior II',
  currentLevel: 1,
  targetLevel: 2,
  runs: 3,
  materialSaving: 10_000,
  researchCost: 14_500,
  net: -4_500,
  verdict: 'not_worth',
})

export const FERNITE_COMPOSITE_NOT_WORTH = researchRow({
  typeId: FERNITE_COMPOSITE_TYPE_ID,
  typeName: 'Fernite Composite',
  currentLevel: 8,
  targetLevel: 9,
  runs: 5,
  materialSaving: 14_500,
  researchCost: 14_500,
  net: 0,
  verdict: 'not_worth',
})

export const HECATE_NOT_WORTH = researchRow({
  typeId: HECATE_TYPE_ID,
  typeName: 'Hecate',
  currentLevel: 2,
  targetLevel: 3,
  runs: 2,
  materialSaving: 7_300,
  researchCost: 21_000,
  net: -13_700,
  verdict: 'not_worth',
})

export function projectResearch(projectId: string, rows: ResearchRow[]): ProjectResearch {
  return { id: projectId, rows }
}

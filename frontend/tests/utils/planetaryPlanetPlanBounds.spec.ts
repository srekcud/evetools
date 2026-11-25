import { DEFAULT_SETTINGS, type BuildChoice, type PlannedProduction, type PlannerCatalog } from '@/utils/planetaryPlanner'
import { buildPlanetPlan, type ExtractionPlanetRow, type FactoryPlanetRow, type PlannerAccount, type PlannerPlanetType } from '@/utils/planetaryPlanets'
import {
  ALMA_CHARACTER_ID,
  AQUEOUS_LIQUIDS,
  BASE_METALS,
  BRAN_CHARACTER_ID,
  BROADCAST_NODE,
  HEAVY_METALS,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  PLANET_TYPES,
  REACTIVE_METALS,
  ROBOTICS,
  TOXIC_METALS,
  WATER,
  withExtractionYield,
  withMaxPlanets,
  withPlanetTypes,
  withTypeVolume,
} from '../planetaryPlannerFixtures'

const MADE_P1: Record<number, BuildChoice> = { [WATER]: 'produce', [REACTIVE_METALS]: 'produce', [TOXIC_METALS]: 'produce' }
const BROADCAST_144: PlannedProduction[] = [{ typeId: BROADCAST_NODE, targetPerDay: 144 }]
const ROBOTICS_72: PlannedProduction[] = [{ typeId: ROBOTICS, targetPerDay: 72 }]

function plan(
  productions: PlannedProduction[],
  options: { catalog?: PlannerCatalog, choices?: Record<number, BuildChoice>, account?: PlannerAccount | null, overrides?: Record<number, number> } = {},
) {
  return buildPlanetPlan(
    productions,
    options.catalog ?? PLANNER_CATALOG,
    options.choices ?? {},
    DEFAULT_SETTINGS,
    options.account === undefined ? null : options.account,
    options.overrides ?? {},
  )
}

function factoryRow(result: ReturnType<typeof plan>, tier: string): FactoryPlanetRow {
  const row = result.rows.find((candidate): candidate is FactoryPlanetRow => candidate.role === 'factory' && candidate.tier === tier)
  if (!row) throw new Error(`No factory row for ${tier}`)
  return row
}

function extractionRow(result: ReturnType<typeof plan>, typeId: number): ExtractionPlanetRow {
  const row = result.rows.find((candidate): candidate is ExtractionPlanetRow => candidate.role === 'extraction' && candidate.typeId === typeId)
  if (!row) throw new Error(`No extraction row for ${typeId}`)
  return row
}

describe('buildPlanetPlan total completeness', () => {
  it.each([0, -50])('treats an account yield of %s per hour as unknown: no yield, no planet, never an infinite count, and an incomplete total', accountYield => {
    const result = plan(ROBOTICS_72, {
      choices: MADE_P1,
      account: withExtractionYield(PLANNER_ACCOUNT, AQUEOUS_LIQUIDS, accountYield),
    })

    const aqueous = extractionRow(result, AQUEOUS_LIQUIDS)
    expect([aqueous.yieldPerHour, aqueous.yieldSource, aqueous.planets]).toEqual([null, null, null])
    expect([extractionRow(result, BASE_METALS).planets, extractionRow(result, HEAVY_METALS).planets]).toEqual([3, null])
    expect([result.totalPlanets, result.incomplete]).toEqual([5, true])
  })

  it('keeps the yield the player typed when the yield of the account is zero', () => {
    const result = plan(ROBOTICS_72, {
      choices: MADE_P1,
      account: withExtractionYield(PLANNER_ACCOUNT, AQUEOUS_LIQUIDS, 0),
      overrides: { [AQUEOUS_LIQUIDS]: 9_000 },
    })

    const aqueous = extractionRow(result, AQUEOUS_LIQUIDS)
    expect([aqueous.yieldPerHour, aqueous.yieldSource, aqueous.planets]).toEqual([9_000, 'override', 3])
  })
})

describe('buildPlanetPlan layout statuses', () => {
  it('flags every tier as not fitting when the command centers cannot power a launchpad: 3,000 CPU, no factory, no planet counted', () => {
    const weakPlanetTypes: PlannerPlanetType[] = PLANET_TYPES.map(planetType => ({ ...planetType, commandCenter: { cpu: 3_000, pg: 19_000 } }))

    const result = plan(BROADCAST_144, { catalog: withPlanetTypes(PLANNER_CATALOG, weakPlanetTypes) })

    expect(['P4', 'P3', 'P2'].map(tier => [factoryRow(result, tier).status, factoryRow(result, tier).layout?.factories, factoryRow(result, tier).planets])).toEqual([
      ['doesNotFit', 0, null],
      ['doesNotFit', 0, null],
      ['doesNotFit', 0, null],
    ])
    expect(result.totalPlanets).toBe(0)
  })

  it('gives an explicit unknown volume status to a tier when the catalog has no planet type at all', () => {
    const result = plan(BROADCAST_144, { catalog: withPlanetTypes(PLANNER_CATALOG, []) })

    expect(['P4', 'P3', 'P2'].map(tier => [factoryRow(result, tier).status, factoryRow(result, tier).planetTypes, factoryRow(result, tier).layout, factoryRow(result, tier).planets])).toEqual([
      ['unknownVolume', [], null, null],
      ['unknownVolume', [], null, null],
      ['unknownVolume', [], null, null],
    ])
    expect([result.totalPlanets, result.incomplete]).toEqual([0, true])
  })

  it('leaves the P2 tier unknown when only the second input of its recipe has no volume, and keeps the other tiers known', () => {
    const result = plan(BROADCAST_144, { catalog: withTypeVolume(PLANNER_CATALOG, REACTIVE_METALS, null) })

    expect([factoryRow(result, 'P2').status, factoryRow(result, 'P2').planets]).toEqual(['unknownVolume', null])
    expect([factoryRow(result, 'P4').status, factoryRow(result, 'P3').status]).toEqual(['ok', 'ok'])
    expect(result.incomplete).toBe(true)
  })
})

describe('buildPlanetPlan slot bounds', () => {
  it('has no free planet and no shortfall when the plan needs exactly the maximum of the account: 7 planets for a maximum of 7', () => {
    const result = plan(BROADCAST_144, { account: withMaxPlanets(PLANNER_ACCOUNT, { [ALMA_CHARACTER_ID]: 4, [BRAN_CHARACTER_ID]: 3 }) })

    expect([result.totalPlanets, result.accountMaxPlanets, result.freePlanets]).toEqual([7, 7, 0])
  })

  it('stops the free planets at zero when the plan needs more than the maximum: 7 planets for a maximum of 4', () => {
    const result = plan(BROADCAST_144, { account: withMaxPlanets(PLANNER_ACCOUNT, { [ALMA_CHARACTER_ID]: 2, [BRAN_CHARACTER_ID]: 2 }) })

    expect([result.totalPlanets, result.accountMaxPlanets, result.freePlanets]).toEqual([7, 4, 0])
  })
})

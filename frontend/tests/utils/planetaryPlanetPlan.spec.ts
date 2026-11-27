import { DEFAULT_SETTINGS, type BuildChoice, type PlannedProduction, type PlannerCatalog } from '@/utils/planetaryPlanner'
import { buildPlanetPlan, type ExtractionPlanetRow, type FactoryPlanetRow, type PlannerAccount } from '@/utils/planetaryPlanets'
import {
  AQUEOUS_LIQUIDS,
  BASE_METALS,
  BROADCAST_NODE,
  HEAVY_METALS,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  REACTIVE_METALS,
  ROBOTICS,
  TOXIC_METALS,
  WATER,
  withMaxPlanets,
  withTypeVolume,
  BRAN_CHARACTER_ID,
} from '../planetaryPlannerFixtures'

const MADE_P1: Record<number, BuildChoice> = { [WATER]: 'produce', [REACTIVE_METALS]: 'produce', [TOXIC_METALS]: 'produce' }
const NO_OVERRIDES: Record<number, number> = {}

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
    options.overrides ?? NO_OVERRIDES,
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

function layoutOf(row: FactoryPlanetRow) {
  if (!row.layout) throw new Error(`No layout for ${row.tier}`)
  return row.layout
}

const BROADCAST_144: PlannedProduction[] = [{ typeId: BROADCAST_NODE, targetPerDay: 144 }]
const ROBOTICS_72: PlannedProduction[] = [{ typeId: ROBOTICS, targetPerDay: 72 }]

describe('buildPlanetPlan factory rows', () => {
  it('sizes one row per factory tier from P4 down, with its planet types, its layout and its planet count', () => {
    const result = plan(BROADCAST_144)

    expect(result.rows.map(row => (row.role === 'factory' ? row.tier : row.role === 'extraction' ? row.typeId : row.role))).toEqual(['P4', 'P3', 'P2'])
    const [p4, p3, p2] = ['P4', 'P3', 'P2'].map(tier => factoryRow(result, tier))
    expect([p4, p3, p2].map(row => [row.factoriesNeeded, row.status, row.planets])).toEqual([[6, 'ok', 2], [12, 'ok', 1], [48, 'ok', 4]])
    expect([p4, p3, p2].map(row => row.planetTypes)).toEqual([['Barren', 'Temperate'], ['Barren', 'Lava', 'Temperate'], ['Barren', 'Lava', 'Temperate']])
    expect([p4, p3, p2].map(row => [layoutOf(row).launchpads, layoutOf(row).factories, layoutOf(row).capacity, layoutOf(row).days])).toEqual([[5, 5, 50_000, 4], [4, 13, 40_000, 4], [4, 13, 40_000, 4]])
    expect([p4, p3, p2].map(row => layoutOf(row).dailyVolume)).toEqual([expect.closeTo(2_400, 6), expect.closeTo(720, 6), expect.closeTo(729.6, 6)])
  })

  it('counts the planets of the plan, the characters they need and no missing figure when every line is known', () => {
    const result = plan(BROADCAST_144)

    expect([result.totalPlanets, result.incomplete, result.charactersNeeded]).toEqual([7, false, 2])
  })

  it('flags a tier that does not fit the days between rounds, counts no planet for it and marks the total incomplete', () => {
    const result = plan(BROADCAST_144, { catalog: withTypeVolume(PLANNER_CATALOG, BROADCAST_NODE, 10_000) })

    expect([factoryRow(result, 'P4').status, factoryRow(result, 'P4').planets]).toEqual(['doesNotFit', null])
    expect([factoryRow(result, 'P3').planets, factoryRow(result, 'P2').planets]).toEqual([1, 4])
    expect(result.totalPlanets).toBe(5)
    expect(result.incomplete).toBe(true)
  })

  it('leaves a tier unknown when a volume is missing from the SDE, counts nothing for it and marks the total incomplete', () => {
    const result = plan(ROBOTICS_72, { catalog: withTypeVolume(PLANNER_CATALOG, WATER, null) })

    const p2 = factoryRow(result, 'P2')
    expect([p2.status, p2.layout, p2.planets, p2.factoriesNeeded]).toEqual(['unknownVolume', null, null, 4])
    expect([factoryRow(result, 'P3').status, factoryRow(result, 'P3').planets]).toEqual(['ok', 1])
    expect([result.totalPlanets, result.incomplete]).toEqual([1, true])
  })
})

describe('buildPlanetPlan extraction rows', () => {
  const madeP1Plan = () => plan(ROBOTICS_72, { choices: MADE_P1, account: PLANNER_ACCOUNT, overrides: { [AQUEOUS_LIQUIDS]: 9_000 } })

  it('keeps one extraction row per raw resource after the factory rows, never merged, with the P1 factories that consume it', () => {
    const result = madeP1Plan()

    expect(result.rows.map(row => (row.role === 'factory' ? row.tier : row.role === 'extraction' ? row.typeId : row.role))).toEqual(['P3', 'P2', AQUEOUS_LIQUIDS, BASE_METALS, HEAVY_METALS])
    expect([AQUEOUS_LIQUIDS, BASE_METALS, HEAVY_METALS].map(typeId => extractionRow(result, typeId).perHour)).toEqual([24_000, 12_000, 12_000])
    expect([AQUEOUS_LIQUIDS, BASE_METALS, HEAVY_METALS].map(typeId => extractionRow(result, typeId).p1Factories)).toEqual([4, 2, 2])
    expect([AQUEOUS_LIQUIDS, BASE_METALS, HEAVY_METALS].map(typeId => extractionRow(result, typeId).planetTypes)).toEqual([['Barren', 'Temperate'], ['Barren', 'Lava', 'Temperate'], []])
  })

  it('divides by the yield the player typed before the yield of the account and by the account yield otherwise', () => {
    const result = madeP1Plan()

    const aqueous = extractionRow(result, AQUEOUS_LIQUIDS)
    const baseMetals = extractionRow(result, BASE_METALS)
    expect([aqueous.yieldPerHour, aqueous.yieldSource, aqueous.planets]).toEqual([9_000, 'override', 3])
    expect([baseMetals.yieldPerHour, baseMetals.yieldSource, baseMetals.planets]).toEqual([5_000, 'account', 3])
  })

  it('leaves a resource without any yield unknown, counts no planet for it and marks the total incomplete', () => {
    const result = madeP1Plan()

    const heavyMetals = extractionRow(result, HEAVY_METALS)
    expect([heavyMetals.yieldPerHour, heavyMetals.yieldSource, heavyMetals.planets]).toEqual([null, null, null])
    expect([result.totalPlanets, result.incomplete, result.charactersNeeded]).toEqual([8, true, 2])
  })

  it('knows no yield at all without an account and without a typed value', () => {
    const result = plan(ROBOTICS_72, { choices: MADE_P1 })

    expect([AQUEOUS_LIQUIDS, BASE_METALS, HEAVY_METALS].map(typeId => extractionRow(result, typeId).planets)).toEqual([null, null, null])
    expect([result.totalPlanets, result.incomplete]).toEqual([2, true])
  })
})

describe('buildPlanetPlan account figures', () => {
  it('shows the colonies in use without subtracting them and frees the maximum of the account minus the planets of the plan', () => {
    const result = plan(ROBOTICS_72, { account: PLANNER_ACCOUNT })

    expect([result.usedPlanets, result.accountMaxPlanets, result.totalPlanets, result.freePlanets]).toEqual([4, 10, 2, 8])
  })

  it('has no maximum and no free planet when a character maximum is unknown, nor any account figure without account', () => {
    const unknownMaximum = plan(ROBOTICS_72, { account: withMaxPlanets(PLANNER_ACCOUNT, { [BRAN_CHARACTER_ID]: null }) })
    const withoutAccount = plan(ROBOTICS_72)

    expect([unknownMaximum.usedPlanets, unknownMaximum.accountMaxPlanets, unknownMaximum.freePlanets]).toEqual([4, null, null])
    expect([withoutAccount.usedPlanets, withoutAccount.accountMaxPlanets, withoutAccount.freePlanets]).toEqual([null, null, null])
  })
})

describe('buildPlanetPlan productions', () => {
  const TWO_BROADCAST_NODES: PlannedProduction[] = [{ typeId: BROADCAST_NODE, targetPerDay: 120 }, { typeId: BROADCAST_NODE, targetPerDay: 60 }]

  it('adds to each production the planets its factories need beyond the productions before it', () => {
    const result = plan(TWO_BROADCAST_NODES)

    expect(result.addedByProduction).toEqual([6, 3])
    expect(result.totalPlanets).toBe(9)
  })

  it('splits the factories of each tier of a production between free slots and new planets and tells what stays free', () => {
    const result = plan(TWO_BROADCAST_NODES)

    expect(result.allocations).toEqual([
      [
        { tier: 'P4', onFreeSlots: 0, onNewPlanets: 5, freeRemaining: 0 },
        { tier: 'P3', onFreeSlots: 0, onNewPlanets: 10, freeRemaining: 3 },
        { tier: 'P2', onFreeSlots: 0, onNewPlanets: 40, freeRemaining: 12 },
      ],
      [
        { tier: 'P4', onFreeSlots: 0, onNewPlanets: 3, freeRemaining: 2 },
        { tier: 'P3', onFreeSlots: 3, onNewPlanets: 2, freeRemaining: 11 },
        { tier: 'P2', onFreeSlots: 12, onNewPlanets: 8, freeRemaining: 5 },
      ],
    ])
  })
})

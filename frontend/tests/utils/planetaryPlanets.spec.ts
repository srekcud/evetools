import {
  accountMaxPlanets,
  bestLayout,
  charactersNeeded,
  extractionPlanets,
  factoryDailyVolume,
  factoryLayout,
  isValidExtractionYield,
  planetsForFactories,
  type PlannerPlanetType,
} from '@/utils/planetaryPlanets'
import { DEFAULT_SETTINGS, buildPlan, type BuildChoice, type PlannedProduction, type PlannerCatalog } from '@/utils/planetaryPlanner'
import {
  BROADCAST_NODE,
  MECHANICAL_PARTS,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  PLANET_TYPES,
  WATER,
  withMaxPlanets,
  withTypeVolume,
  ALMA_CHARACTER_ID,
  BRAN_CHARACTER_ID,
} from '../planetaryPlannerFixtures'

const BARREN = PLANET_TYPES[0] as PlannerPlanetType
const ADVANCED = BARREN.facilities.advanced as { cpu: number, pg: number }
const HIGH_TECH = BARREN.facilities.highTech as { cpu: number, pg: number }
const DAYS = 4

const BROADCAST_NODE_PRODUCTION: PlannedProduction[] = [{ typeId: BROADCAST_NODE, targetPerDay: 24 }]

function trees(catalog: PlannerCatalog = PLANNER_CATALOG, choices: Record<number, BuildChoice> = {}) {
  return buildPlan(BROADCAST_NODE_PRODUCTION, catalog, choices, DEFAULT_SETTINGS).trees
}

describe('bestLayout', () => {
  it('keeps one launchpad and 26 factories when a second launchpad would only fit 25', () => {
    expect(bestLayout(BARREN, ADVANCED, 90, DAYS)).toEqual({ launchpads: 1, factories: 26, dailyVolume: 90, capacity: 10_000, days: DAYS })
  })

  it('takes the smallest launchpad count when several counts fit the same number of factories', () => {
    const cpuBound: PlannerPlanetType = {
      name: 'Plasma',
      commandCenter: { cpu: 5_990, pg: 1_000_000 },
      launchpad: { cpu: 100, pg: 1, capacity: 10_000 },
      facilities: { basic: null, advanced: { cpu: 1_000, pg: 1 }, highTech: null },
    }

    expect(bestLayout(cpuBound, { cpu: 1_000, pg: 1 }, 1, 1)).toEqual({ launchpads: 1, factories: 5, dailyVolume: 1, capacity: 10_000, days: 1 })
  })

  it('adds launchpads when the volume to hold is high: six launchpads and five factories for 3000 per day', () => {
    expect(bestLayout(BARREN, ADVANCED, 3_000, DAYS)).toEqual({ launchpads: 6, factories: 5, dailyVolume: 3_000, capacity: 60_000, days: DAYS })
  })

  it('fits no factory at all when the volume of one factory exceeds six launchpads for the days between rounds, and keeps the smallest launchpad count', () => {
    expect(bestLayout(BARREN, ADVANCED, 30_000, DAYS)).toEqual({ launchpads: 1, factories: 0, dailyVolume: 30_000, capacity: 10_000, days: DAYS })
  })

  it('limits a high-tech plant planet by the command center first: one launchpad and 19 plants', () => {
    expect(bestLayout(BARREN, HIGH_TECH, 1, 1)).toEqual({ launchpads: 1, factories: 19, dailyVolume: 1, capacity: 10_000, days: 1 })
  })
})

describe('factoryLayout', () => {
  it('offers a P4 only the planet types that have a high-tech plant and keeps the fewest factories among them', () => {
    expect(factoryLayout('P4', PLANNER_CATALOG, 1, 1)).toEqual({
      planetTypes: ['Barren', 'Temperate'],
      layout: { launchpads: 1, factories: 18, dailyVolume: 1, capacity: 10_000, days: 1 },
    })
  })

  it('keeps the fewest factories among the planet types of a P2: the smallest command center decides', () => {
    expect(factoryLayout('P2', PLANNER_CATALOG, 90, DAYS)).toEqual({
      planetTypes: ['Barren', 'Lava', 'Temperate'],
      layout: { launchpads: 1, factories: 20, dailyVolume: 90, capacity: 10_000, days: DAYS },
    })
  })
})

describe('planet counts', () => {
  it('rounds the planets of a factory tier up: 52 factories on 26 per planet is 2, 53 is 3', () => {
    expect([planetsForFactories(52, 26), planetsForFactories(53, 26)]).toEqual([2, 3])
  })

  it('rounds the extraction planets up: 28,100 per hour on 6,000 per planet is 5', () => {
    expect(extractionPlanets(28_100, 6_000)).toBe(5)
  })

  it('needs one character per six planets, rounded up', () => {
    expect([charactersNeeded(13), charactersNeeded(12)]).toEqual([3, 2])
  })
})

describe('account and yield rules', () => {
  it('adds the maximum of each character and gives no maximum when one of them is unknown or there is no account', () => {
    expect(accountMaxPlanets(PLANNER_ACCOUNT)).toBe(10)
    expect(accountMaxPlanets(withMaxPlanets(PLANNER_ACCOUNT, { [BRAN_CHARACTER_ID]: null }))).toBeNull()
    expect(accountMaxPlanets(withMaxPlanets(PLANNER_ACCOUNT, { [ALMA_CHARACTER_ID]: null }))).toBeNull()
    expect(accountMaxPlanets(null)).toBeNull()
  })

  it('accepts a positive yield and refuses zero, a negative and a value that is not a number', () => {
    expect([4_000, 0.5].map(isValidExtractionYield)).toEqual([true, true])
    expect([0, -1, Number.NaN].map(isValidExtractionYield)).toEqual([false, false, false])
  })
})

describe('factoryDailyVolume', () => {
  it('takes the larger of the inputs and the output of a cycle scaled to a day, for the factories of each tier', () => {
    expect(factoryDailyVolume('P2', trees(), PLANNER_CATALOG)).toBeCloseTo(729.6, 6)
    expect(factoryDailyVolume('P3', trees(), PLANNER_CATALOG)).toBeCloseTo(720, 6)
    expect(factoryDailyVolume('P4', trees(), PLANNER_CATALOG)).toBeCloseTo(2_400, 6)
  })

  it('keeps the largest product of the tier and ignores a product that is bought', () => {
    const heavyMechanicalParts = withTypeVolume(PLANNER_CATALOG, MECHANICAL_PARTS, 20)

    expect(factoryDailyVolume('P2', trees(heavyMechanicalParts), heavyMechanicalParts)).toBeCloseTo(2_400, 6)
    expect(factoryDailyVolume('P2', trees(heavyMechanicalParts, { [MECHANICAL_PARTS]: 'buy' }), heavyMechanicalParts)).toBeCloseTo(729.6, 6)
  })

  it('is unknown for a tier whose input has no volume in the SDE and stays known for the other tiers', () => {
    const waterWithoutVolume = withTypeVolume(PLANNER_CATALOG, WATER, null)

    expect(factoryDailyVolume('P2', trees(waterWithoutVolume), waterWithoutVolume)).toBeNull()
    expect(factoryDailyVolume('P3', trees(waterWithoutVolume), waterWithoutVolume)).toBeCloseTo(720, 6)
  })
})

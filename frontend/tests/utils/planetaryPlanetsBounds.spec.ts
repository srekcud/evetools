import {
  MAX_LAUNCHPADS,
  accountMaxPlanets,
  bestLayout,
  extractionPlanets,
  factoryDailyVolume,
  isValidExtractionYield,
  type PlannerPlanetType,
} from '@/utils/planetaryPlanets'
import { DEFAULT_SETTINGS, buildPlan, type PlannedProduction, type PlannerCatalog } from '@/utils/planetaryPlanner'
import {
  BROADCAST_NODE,
  COOLANT,
  DISTINCT_INPUTS_CATALOG,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  PLANET_TYPES,
  REACTIVE_METALS,
  withRecipe,
  withTypeVolume,
} from '../planetaryPlannerFixtures'

const BARREN = PLANET_TYPES[0] as PlannerPlanetType
const ADVANCED = BARREN.facilities.advanced as { cpu: number, pg: number }
const DAYS = 4

const BROADCAST_NODE_PRODUCTION: PlannedProduction[] = [{ typeId: BROADCAST_NODE, targetPerDay: 24 }]

function trees(catalog: PlannerCatalog) {
  return buildPlan(BROADCAST_NODE_PRODUCTION, catalog, {}, DEFAULT_SETTINGS).trees
}

describe('bestLayout bounds', () => {
  it('never uses more than six launchpads however strong the command center and however large the volume', () => {
    const unlimited: PlannerPlanetType = {
      name: 'Plasma',
      commandCenter: { cpu: 1_000_000, pg: 1_000_000 },
      launchpad: { cpu: 3_600, pg: 700, capacity: 10_000 },
      facilities: { basic: null, advanced: ADVANCED, highTech: null },
    }

    expect(MAX_LAUNCHPADS).toBe(6)
    expect(bestLayout(unlimited, ADVANCED, 100, DAYS)).toEqual({ launchpads: 6, factories: 150, dailyVolume: 100, capacity: 60_000, days: DAYS })
  })

  it('fits no factory when the command center cannot even power the launchpad: 3,000 CPU against 3,600 for one launchpad', () => {
    const weakCommandCenter: PlannerPlanetType = { ...BARREN, commandCenter: { cpu: 3_000, pg: 19_000 } }

    expect(bestLayout(weakCommandCenter, ADVANCED, 90, DAYS)).toEqual({ launchpads: 1, factories: 0, dailyVolume: 90, capacity: 10_000, days: DAYS })
  })
})

describe('account and yield bounds', () => {
  it('has no maximum for an account without any character', () => {
    expect(accountMaxPlanets({ ...PLANNER_ACCOUNT, characters: [] })).toBeNull()
  })

  it('refuses an infinite yield', () => {
    expect([Infinity, -Infinity].map(isValidExtractionYield)).toEqual([false, false])
  })

  it('rounds the extraction planets to the ceiling with a float tolerance: 0.1 times three on 0.1 per planet is 3, not 4', () => {
    expect(extractionPlanets(0.1 * 3, 0.1)).toBe(3)
  })
})

describe('factoryDailyVolume inputs', () => {
  it('scales the cycle of a P2 to a day with its own cycle length: 1,800 seconds doubles the volume of a 3,600 seconds cycle', () => {
    const fasterCoolant = withRecipe(PLANNER_CATALOG, COOLANT, { cycleSeconds: 1800 })

    expect(factoryDailyVolume('P2', trees(fasterCoolant), fasterCoolant)).toBeCloseTo(1_459.2, 6)
  })

  it('is unknown for a tier whose second input has no volume in the SDE, not only its first', () => {
    const reactiveMetalsWithoutVolume = withTypeVolume(PLANNER_CATALOG, REACTIVE_METALS, null)

    expect(factoryDailyVolume('P2', trees(reactiveMetalsWithoutVolume), reactiveMetalsWithoutVolume)).toBeNull()
  })

  it('weighs every input by its own quantity and volume: P2 888, P3 1,140 and P4 2,040 per day', () => {
    expect(factoryDailyVolume('P2', trees(DISTINCT_INPUTS_CATALOG), DISTINCT_INPUTS_CATALOG)).toBeCloseTo(888, 6)
    expect(factoryDailyVolume('P3', trees(DISTINCT_INPUTS_CATALOG), DISTINCT_INPUTS_CATALOG)).toBeCloseTo(1_140, 6)
    expect(factoryDailyVolume('P4', trees(DISTINCT_INPUTS_CATALOG), DISTINCT_INPUTS_CATALOG)).toBeCloseTo(2_040, 6)
  })
})

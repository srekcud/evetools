import { DEFAULT_SETTINGS, type PlannedProduction, type PlannerCatalog } from '@/utils/planetaryPlanner'
import { buildPlanetPlan, type FactoryPlanetRow, type PlanetPlan, type PlannerPlanetType } from '@/utils/planetaryPlanets'
import {
  BROADCAST_NODE,
  COOLANT,
  PLANET_TYPES,
  PLANNER_CATALOG,
  ROBOTICS,
  WATER,
  withPlanetTypes,
  withTypeVolume,
} from '../planetaryPlannerFixtures'

type PlanetMode = 'auto' | 'perTier' | 'wholeChain'
type ChainRow = Extract<PlanetPlan['rows'][number], { role: 'chain' }>

const ROBOTICS_72: PlannedProduction[] = [{ typeId: ROBOTICS, targetPerDay: 72 }]
const BROADCAST_24: PlannedProduction[] = [{ typeId: BROADCAST_NODE, targetPerDay: 24 }]
const BROADCAST_144: PlannedProduction[] = [{ typeId: BROADCAST_NODE, targetPerDay: 144 }]
const COOLANT_120: PlannedProduction[] = [{ typeId: COOLANT, targetPerDay: 120 }]

const BARREN = PLANET_TYPES[0]
const TEMPERATE = PLANET_TYPES[2]

function planetTypeWithCpu(base: PlannerPlanetType, cpu: number): PlannerPlanetType {
  return { ...base, commandCenter: { ...base.commandCenter, cpu } }
}

function plan(productions: PlannedProduction[], planetMode: PlanetMode, catalog: PlannerCatalog = PLANNER_CATALOG) {
  return buildPlanetPlan(productions, catalog, {}, DEFAULT_SETTINGS, null, {}, planetMode)
}

function chainRow(result: PlanetPlan): ChainRow {
  const row = result.rows.find((candidate): candidate is ChainRow => candidate.role === 'chain')
  expect(row?.role).toBe('chain')
  return row as ChainRow
}

function factoryRows(result: PlanetPlan): FactoryPlanetRow[] {
  return result.rows.filter((candidate): candidate is FactoryPlanetRow => candidate.role === 'factory')
}

function layoutOf(row: ChainRow) {
  expect(row.layout).not.toBeNull()
  return row.layout as NonNullable<ChainRow['layout']>
}

describe('buildPlanetPlan chain on one planet', () => {
  it('hosts 4 P2 and 1 P3 of Robotics 72 a day on 1 planet with 2 launchpads, the 3,134.4 m³ a day of P1 inputs and Robotics outputs held over 4 days', () => {
    const result = plan(ROBOTICS_72, 'wholeChain')

    const row = chainRow(result)
    expect(result.rows).toHaveLength(1)
    expect([row.status, row.planets]).toEqual(['ok', 1])
    expect([...row.tiers].sort()).toEqual(['P2', 'P3'])
    expect(row.factoriesByTier).toMatchObject({ P2: 4, P3: 1 })
    expect(row.factoriesByTier.P4).toBeFalsy()
    expect(row.planetTypes).toEqual(['Barren', 'Lava', 'Temperate'])
    expect([layoutOf(row).launchpads, layoutOf(row).capacity, layoutOf(row).days]).toEqual([2, 20_000, 4])
    expect(layoutOf(row).factoriesPerPlanet).toMatchObject({ P2: 4, P3: 1 })
    expect(layoutOf(row).dailyVolume).toBeCloseTo(3_134.4, 6)
    expect([result.planetMode, result.totalPlanets, result.incomplete, result.charactersNeeded]).toEqual(['wholeChain', 1, false, 1])
  })

  it('picks the chain in auto for Robotics 72 a day: 1 planet where each tier on its own needs 2', () => {
    const automatic = plan(ROBOTICS_72, 'auto')
    const perTier = plan(ROBOTICS_72, 'perTier')

    expect([automatic.planetMode, automatic.rows.map(row => row.role), automatic.totalPlanets]).toEqual(['wholeChain', ['chain'], 1])
    expect([perTier.planetMode, perTier.rows.map(row => row.role), perTier.totalPlanets]).toEqual(['perTier', ['factory', 'factory'], 2])
  })

  it('hosts 8 P2, 2 P3 and 1 P4 of Broadcast Node 24 a day on 1 planet of Barren or Temperate with 4 launchpads for 8,236.8 m³ a day, Lava being out for lack of a high technology installation', () => {
    const result = plan(BROADCAST_24, 'wholeChain')

    const row = chainRow(result)
    expect(row.factoriesByTier).toMatchObject({ P2: 8, P3: 2, P4: 1 })
    expect(row.planetTypes).toEqual(['Barren', 'Temperate'])
    expect([row.status, row.planets, layoutOf(row).launchpads]).toEqual(['ok', 1, 4])
    expect(layoutOf(row).factoriesPerPlanet).toEqual({ P2: 8, P3: 2, P4: 1 })
    expect(layoutOf(row).dailyVolume).toBeCloseTo(8_236.8, 6)
  })

  it('spreads 48 P2, 12 P3 and 6 P4 of Broadcast Node 144 a day over 5 identical planets of 10 P2, 3 P3 and 2 P4 with 4 launchpads each', () => {
    const result = plan(BROADCAST_144, 'wholeChain')

    const row = chainRow(result)
    expect(row.factoriesByTier).toMatchObject({ P2: 48, P3: 12, P4: 6 })
    expect([row.status, row.planets, layoutOf(row).launchpads]).toEqual(['ok', 5, 4])
    expect(layoutOf(row).factoriesPerPlanet).toEqual({ P2: 10, P3: 3, P4: 2 })
    expect([result.totalPlanets, result.incomplete, result.charactersNeeded]).toEqual([5, false, 1])
  })

  it('picks the chain in auto for Broadcast Node 144 a day with 5 planets against 7 for each tier on its own, and keeps no allocation for the chain row', () => {
    const automatic = plan(BROADCAST_144, 'auto')
    const perTier = plan(BROADCAST_144, 'perTier')

    expect([automatic.planetMode, automatic.rows.map(row => row.role), chainRow(automatic).planets]).toEqual(['wholeChain', ['chain'], 5])
    expect(perTier.totalPlanets).toBe(7)
    expect(automatic.allocations).toEqual([[]])
    expect(automatic.addedByProduction).toEqual([5])
  })

  it('keeps the largest planet count among the planet types and the layout of the type that gives it: 2 planets of 4 P2, 1 P3, 1 P4 with 2 launchpads on a small Temperate against 1 planet on Barren', () => {
    const smallTemperate = planetTypeWithCpu(TEMPERATE, 12_000)

    const result = plan(BROADCAST_24, 'wholeChain', withPlanetTypes(PLANNER_CATALOG, [BARREN, smallTemperate]))

    const row = chainRow(result)
    expect(row.planetTypes).toEqual(['Barren', 'Temperate'])
    expect([row.planets, layoutOf(row).launchpads]).toEqual([2, 2])
    expect(layoutOf(row).factoriesPerPlanet).toEqual({ P2: 4, P3: 1, P4: 1 })
  })

  it('keeps the factory rows of Coolant 120 a day in auto, a single tier having nothing to share with another', () => {
    const automatic = plan(COOLANT_120, 'auto')
    const perTier = plan(COOLANT_120, 'perTier')

    expect(automatic.planetMode).toBe('perTier')
    expect(automatic.rows.map(row => row.role)).toEqual(['factory'])
    expect(automatic.rows).toEqual(perTier.rows)
    expect(automatic.totalPlanets).toBe(perTier.totalPlanets)
  })

  it('still builds a chain row of 1 P2 factory on 1 planet with 1 launchpad for Coolant 120 a day when the whole chain is asked', () => {
    const result = plan(COOLANT_120, 'wholeChain')

    const row = chainRow(result)
    expect(result.rows).toHaveLength(1)
    expect(row.tiers).toEqual(['P2'])
    expect(row.factoriesByTier).toMatchObject({ P2: 1 })
    expect([row.status, row.planets, layoutOf(row).launchpads]).toEqual(['ok', 1, 1])
    expect(layoutOf(row).dailyVolume).toBeCloseTo(909.6, 6)
  })

  it('leaves the chain unknown when the SDE has no volume for a P1 input: no layout, no planet counted and an incomplete total', () => {
    const result = plan(ROBOTICS_72, 'wholeChain', withTypeVolume(PLANNER_CATALOG, WATER, null))

    const row = chainRow(result)
    expect([row.status, row.layout, row.planets]).toEqual(['unknownVolume', null, null])
    expect([result.totalPlanets, result.incomplete]).toEqual([0, true])
  })

  it('flags a chain that no planet type can host with one installation of each tier: no planet counted and an incomplete total', () => {
    const tooSmall = planetTypeWithCpu(BARREN, 4_000)

    const result = plan(ROBOTICS_72, 'wholeChain', withPlanetTypes(PLANNER_CATALOG, [tooSmall]))

    const row = chainRow(result)
    expect([row.status, row.planets]).toEqual(['doesNotFit', null])
    expect([result.totalPlanets, result.incomplete]).toEqual([0, true])
  })

  it('behaves as the per tier mode when no mode is given: factory rows, same planets and the mode reported as per tier', () => {
    const withoutMode = buildPlanetPlan(BROADCAST_144, PLANNER_CATALOG, {}, DEFAULT_SETTINGS, null, {})
    const perTier = plan(BROADCAST_144, 'perTier')

    expect(withoutMode.planetMode).toBe('perTier')
    expect(factoryRows(withoutMode).map(row => [row.tier, row.planets])).toEqual([['P4', 2], ['P3', 1], ['P2', 4]])
    expect(withoutMode).toEqual(perTier)
  })

  it('falls back to the per tier rows in auto when the chain needs more planets than the tiers on their own: 4 planets against 3 on a planet that hosts 2 installations next to 1 launchpad', () => {
    const tight = planetTypeWithCpu(BARREN, 4_600)
    const catalog = withPlanetTypes(PLANNER_CATALOG, [tight])

    const automatic = plan(ROBOTICS_72, 'auto', catalog)
    const wholeChain = plan(ROBOTICS_72, 'wholeChain', catalog)

    expect([wholeChain.planetMode, chainRow(wholeChain).status, chainRow(wholeChain).planets]).toEqual(['wholeChain', 'ok', 4])
    expect([automatic.planetMode, automatic.rows.map(row => row.role), automatic.totalPlanets]).toEqual(['perTier', ['factory', 'factory'], 3])
  })
})

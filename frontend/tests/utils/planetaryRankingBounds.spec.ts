import { buildPlanetPlan } from '@/utils/planetaryPlanets'
import { DEFAULT_SETTINGS } from '@/utils/planetaryPlanner'
import {
  isValidVolumeShare,
  planTargetOf,
  rankProduct,
  rankProducts,
  rankingBaseCount,
  type RankingOptions,
} from '@/utils/planetaryRanking'
import {
  BRAN_CHARACTER_ID,
  BROADCAST_NODE,
  COOLANT,
  MECHANICAL_PARTS,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  PLANNER_MARKET,
  RANKING_CATALOG,
  RANKING_MARKET,
  RANKING_PRODUCT_B,
  ROBOTICS,
  withMarketProduct,
  withMaxPlanets,
  withoutMarketProduct,
} from '../planetaryPlannerFixtures'

const ONE_PLANET: RankingOptions = { baseCount: 1, volumeSharePercent: 5, tier: 'all' }

function rankB(averageDailyVolume: number, options: RankingOptions = ONE_PLANET) {
  return rankProduct(RANKING_PRODUCT_B, RANKING_CATALOG, DEFAULT_SETTINGS, withMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B, { averageDailyVolume }), options)
}

function rankedTypeIds(options: RankingOptions, market = PLANNER_MARKET): number[] {
  return rankProducts(PLANNER_CATALOG, DEFAULT_SETTINGS, market, options).map(row => row.typeId)
}

describe('volume cap bounds', () => {
  it('does not flag as capped an output whose volume cap equals the maximum output of the base', () => {
    const maximum = rankB(1_000_000).maxOutputPerDay as number
    expect(maximum).toBeCloseTo(3_120, 6)

    const row = rankB(maximum, { ...ONE_PLANET, volumeSharePercent: 100 })

    expect(row.outputPerDay).toBe(maximum)
    expect(row.capped).toBe(false)
  })

  it('outputs nothing, uses no planet and offers no plan target at a share of 0 %', () => {
    const row = rankB(20_000, { ...ONE_PLANET, volumeSharePercent: 0 })

    expect(row.outputPerDay).toBe(0)
    expect(row.capped).toBe(true)
    expect(Math.abs(row.planetsUsed as number)).toBe(0)
    expect(row.profitPerDay).toBe(0)
    expect(row.planTarget).toBeNull()
  })

  it('takes the whole volume at 100 % when it is below the maximum output and the maximum output when it is above', () => {
    const below = rankB(2_000, { ...ONE_PLANET, volumeSharePercent: 100 })
    const above = rankB(5_000, { ...ONE_PLANET, volumeSharePercent: 100 })

    expect(below.outputPerDay).toBeCloseTo(2_000, 6)
    expect(below.capped).toBe(true)
    expect(above.outputPerDay).toBeCloseTo(3_120, 6)
    expect(above.capped).toBe(false)
  })

  it('rounds the planets used up without letting float noise add one: 3,120 a day on a base of 2 uses 1, 3,120.5 uses 2', () => {
    const base = { ...ONE_PLANET, baseCount: 2 }

    expect(rankB(62_400, base).planetsUsed).toBe(1)
    expect(rankB(62_410, base).planetsUsed).toBe(2)
  })

  it('offers round(q) as plan target: 1,000.4 gives 1,000, 0.5 gives 1, 0.4 gives none', () => {
    expect(rankB(20_008).planTarget).toBe(1_000)
    expect(rankB(10).planTarget).toBe(1)
    expect(rankB(8).planTarget).toBeNull()
  })
})

describe('planTargetOf', () => {
  it('rounds the output to the nearest unit and offers none below 1', () => {
    expect([planTargetOf(1_000.4, 1), planTargetOf(1_000.5, 1), planTargetOf(1, 1), planTargetOf(0.5, 1), planTargetOf(0.4, 1), planTargetOf(0, 1)]).toEqual([1_000, 1_001, 1, 1, null, null])
  })

  it('offers none without an output or with an empty or unknown base', () => {
    expect([planTargetOf(null, 1), planTargetOf(25, 0), planTargetOf(25, null)]).toEqual([null, null, null])
  })
})

describe('isValidVolumeShare', () => {
  it('accepts 0, 5 and 100 and refuses a value below 0, above 100 or not finite', () => {
    expect([0, 5, 100, 0.01, 99.99].map(isValidVolumeShare)).toEqual([true, true, true, true, true])
    expect([-0.01, 100.01, Number.NaN, Number.POSITIVE_INFINITY].map(isValidVolumeShare)).toEqual([false, false, false, false])
  })
})

describe('rankingBaseCount', () => {
  it('counts 1 planet and 6 for a character, with or without a plan', () => {
    const plan = buildPlanetPlan([], PLANNER_CATALOG, {}, DEFAULT_SETTINGS, PLANNER_ACCOUNT, {})

    expect([rankingBaseCount('planet', plan), rankingBaseCount('planet', null), rankingBaseCount('character', plan), rankingBaseCount('character', null)]).toEqual([1, 1, 6, 6])
  })

  it('counts the free planets of the plan: 10 of the account with an empty plan, 9 once a plan takes one', () => {
    const empty = buildPlanetPlan([], PLANNER_CATALOG, {}, DEFAULT_SETTINGS, PLANNER_ACCOUNT, {})
    const oneFactoryPlanet = buildPlanetPlan([{ typeId: MECHANICAL_PARTS, targetPerDay: 1_200 }], PLANNER_CATALOG, {}, DEFAULT_SETTINGS, PLANNER_ACCOUNT, {})

    expect(rankingBaseCount('free', empty)).toBe(10)
    expect(rankingBaseCount('free', oneFactoryPlanet)).toBe(9)
  })

  it('is unknown for the free planets without a plan, without an account or when a character maximum is unknown', () => {
    const unknownMaximum = buildPlanetPlan([], PLANNER_CATALOG, {}, DEFAULT_SETTINGS, withMaxPlanets(PLANNER_ACCOUNT, { [BRAN_CHARACTER_ID]: null }), {})
    const withoutAccount = buildPlanetPlan([], PLANNER_CATALOG, {}, DEFAULT_SETTINGS, null, {})

    expect([rankingBaseCount('free', null), rankingBaseCount('free', unknownMaximum), rankingBaseCount('free', withoutAccount)]).toEqual([null, null, null])
  })
})

describe('rankProducts', () => {
  it('lists the P2, P3 and P4 with a recipe by profit a day, a loss before the unknown profits', () => {
    expect(rankedTypeIds(ONE_PLANET)).toEqual([ROBOTICS, MECHANICAL_PARTS, COOLANT, BROADCAST_NODE])
  })

  it('breaks a tie of profit by type id: at a share of 0 % every known profit is zero', () => {
    expect(rankedTypeIds({ ...ONE_PLANET, volumeSharePercent: 0 })).toEqual([COOLANT, MECHANICAL_PARTS, ROBOTICS, BROADCAST_NODE])
  })

  it('puts the unknown profits last, ordered by type id', () => {
    const onlyMechanicalParts = withoutMarketProduct(withoutMarketProduct(PLANNER_MARKET, COOLANT), ROBOTICS)

    expect(rankedTypeIds(ONE_PLANET, onlyMechanicalParts)).toEqual([MECHANICAL_PARTS, COOLANT, ROBOTICS, BROADCAST_NODE])
  })

  it('keeps only the tier of the filter, and all four products for all', () => {
    expect(rankedTypeIds({ ...ONE_PLANET, tier: 'P2' })).toEqual([MECHANICAL_PARTS, COOLANT])
    expect(rankedTypeIds({ ...ONE_PLANET, tier: 'P3' })).toEqual([ROBOTICS])
    expect(rankedTypeIds({ ...ONE_PLANET, tier: 'P4' })).toEqual([BROADCAST_NODE])
    expect(rankedTypeIds({ ...ONE_PLANET, tier: 'all' })).toHaveLength(4)
  })

  it('ranks without market: every profit is unknown and the order falls back on the type id', () => {
    expect(rankProducts(PLANNER_CATALOG, DEFAULT_SETTINGS, null, ONE_PLANET).map(row => row.typeId)).toEqual([COOLANT, MECHANICAL_PARTS, ROBOTICS, BROADCAST_NODE])
  })
})

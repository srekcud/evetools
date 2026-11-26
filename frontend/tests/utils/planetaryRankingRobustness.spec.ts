import { DEFAULT_SETTINGS } from '@/utils/planetaryPlanner'
import { rankProduct, type PlannerMarket, type RankingOptions } from '@/utils/planetaryRanking'
import {
  BROADCAST_NODE,
  COOLANT,
  DISTINCT_OUTPUTS_CATALOG,
  PLANNER_CATALOG,
  PLANNER_MARKET,
  RANKING_CATALOG,
  RANKING_MARKET,
  RANKING_PRODUCT_B,
  ROBOTICS,
  THE_FORGE_REGION_ID,
  withMarketProduct,
  withoutMarketProduct,
  withSellPrices,
  withTypeVolume,
} from '../planetaryPlannerFixtures'

const WHOLE_VOLUME: RankingOptions = { baseCount: 1, volumeSharePercent: 100, tier: 'all' }
const UNCAPPED_B_MARKET = withMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B, { averageDailyVolume: 1_000_000 })
const UNCAPPED_BROADCAST_NODE_MARKET: PlannerMarket = {
  regionId: THE_FORGE_REGION_ID,
  products: [{ typeId: BROADCAST_NODE, averageDailyVolume: 1_000_000, averagePrice: 880_000, priceChange: null, lastDate: '2026-11-23' }],
}
const UNCAPPED_ROBOTICS_MARKET = withMarketProduct(PLANNER_MARKET, ROBOTICS, { averageDailyVolume: 1_000_000 })
const COOLANT_WORTH_MAKING = withSellPrices(PLANNER_CATALOG, { [COOLANT]: 2_000 })

function rankB(options: RankingOptions, market: PlannerMarket | null = UNCAPPED_B_MARKET, settings = DEFAULT_SETTINGS, catalog = RANKING_CATALOG) {
  return rankProduct(RANKING_PRODUCT_B, catalog, settings, market, options)
}

describe('rankProduct on a product whose volume is unknown', () => {
  it('offers no plan target, whether the market lacks the product or is unavailable', () => {
    for (const market of [withoutMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B), null]) {
      expect(rankB({ ...WHOLE_VOLUME }, market).planTarget).toBeNull()
    }
  })

  it('keeps the uncapped output of 3,120, the planets used and the purchases shown, with no profit', () => {
    for (const market of [withoutMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B), null]) {
      const row = rankB({ ...WHOLE_VOLUME }, market)

      expect(row.outputPerDay).toBeCloseTo(3_120, 6)
      expect(row.capped).toBe(false)
      expect(row.planetsUsed).toBe(1)
      expect(row.weeklyPurchasesIsk).toBeCloseTo(8_736_000, 2)
      expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth]).toEqual([null, null, null])
    }
  })

  it('still offers the plan target of 3,120 once the volume is known and the output is not capped', () => {
    expect(rankB({ ...WHOLE_VOLUME }).planTarget).toBe(3_120)
  })
})

describe('rankProduct planets used', () => {
  it('is a positive zero, not minus zero, at a share of 0 % and on an empty base', () => {
    expect(rankB({ ...WHOLE_VOLUME, volumeSharePercent: 0 }).planetsUsed).toBe(0)
    expect(rankB({ ...WHOLE_VOLUME, baseCount: 0 }).planetsUsed).toBe(0)
  })

  it('does not round up the float noise of output times planets per unit: 15, 30 and 60 planets of Broadcast Node use 15, 30 and 60', () => {
    const used = [15, 30, 60].map(baseCount => rankProduct(BROADCAST_NODE, PLANNER_CATALOG, DEFAULT_SETTINGS, UNCAPPED_BROADCAST_NODE_MARKET, { ...WHOLE_VOLUME, baseCount }).planetsUsed)

    expect(used).toEqual([15, 30, 60])
  })

  it('does not round up the float noise of a P3 whose two P2 are made: 23 and 46 planets of Robotics use 23 and 46', () => {
    const used = [23, 46].map(baseCount => rankProduct(ROBOTICS, COOLANT_WORTH_MAKING, DEFAULT_SETTINGS, UNCAPPED_ROBOTICS_MARKET, { ...WHOLE_VOLUME, baseCount }).planetsUsed)

    expect(used).toEqual([23, 46])
  })
})

describe('rankProduct planets per unit of a chain with two made P2', () => {
  it('adds the factories of Coolant, made 4 a cycle, and of Mechanical Parts, made 6 a cycle, each with its own output', () => {
    const row = rankProduct(ROBOTICS, DISTINCT_OUTPUTS_CATALOG, DEFAULT_SETTINGS, null, WHOLE_VOLUME)
    const robotics = 1 / 72 / 9
    const coolantAndMechanicalParts = (10 / 3 / 96 + 20 / 3 / 144) / 13
    const outputsSwapped = (10 / 3 / 144 + 20 / 3 / 96) / 13

    expect(row.chain).toEqual([
      { tier: 'P1', produced: 0, bought: 3 },
      { tier: 'P2', produced: 2, bought: 0 },
    ])
    expect(row.planetsPerUnit).toBeCloseTo(robotics + coolantAndMechanicalParts, 12)
    expect(row.planetsPerUnit).not.toBeCloseTo(robotics + outputsSwapped, 4)
    expect(row.maxOutputPerDay).toBeCloseTo(1 / (robotics + coolantAndMechanicalParts), 6)
  })
})

describe('rankProduct on an unknown or empty base', () => {
  it('has no output, planets used, profit, purchases nor plan target on an unknown base, but still gives the planets per unit and the cost', () => {
    const row = rankB({ ...WHOLE_VOLUME, baseCount: null })

    expect(row.fit).toBe('ok')
    expect(row.planetsPerUnit).toBeCloseTo(1 / 3_120, 12)
    expect(row.unitCost).toBeCloseTo(440, 6)
    expect(row.baseCount).toBeNull()
    expect([row.maxOutputPerDay, row.outputPerDay, row.planetsUsed, row.planTarget]).toEqual([null, null, null, null])
    expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth, row.weeklyPurchasesIsk]).toEqual([null, null, null, null])
    expect(row.capped).toBe(false)
  })

  it('outputs 0, uses 0 planets, earns 0 and buys 0 on a base of 0 planets, without capping and without plan target', () => {
    const row = rankB({ ...WHOLE_VOLUME, baseCount: 0 })

    expect(row.baseCount).toBe(0)
    expect([row.maxOutputPerDay, row.outputPerDay, row.planetsUsed]).toEqual([0, 0, 0])
    expect(row.capped).toBe(false)
    expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth, row.weeklyPurchasesIsk]).toEqual([0, 0, 0, 0])
    expect(row.planTarget).toBeNull()
  })

  it('keeps the profit unknown on a base of 0 planets when the volume is unknown, with an output of 0', () => {
    const row = rankB({ ...WHOLE_VOLUME, baseCount: 0 }, null)

    expect(row.outputPerDay).toBe(0)
    expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth]).toEqual([null, null, null])
  })
})

describe('rankProduct and the days between rounds', () => {
  const heavyCatalog = withTypeVolume(RANKING_CATALOG, RANKING_PRODUCT_B, 300)

  it('states the days of the settings when the product does not fit: 2 days then 3 days', () => {
    const atTwoDays = rankB(WHOLE_VOLUME, UNCAPPED_B_MARKET, { ...DEFAULT_SETTINGS, daysBetweenRounds: 2 }, heavyCatalog)
    const atThreeDays = rankB(WHOLE_VOLUME, UNCAPPED_B_MARKET, { ...DEFAULT_SETTINGS, daysBetweenRounds: 3 }, heavyCatalog)

    expect([atTwoDays.fit, atTwoDays.doesNotFitDays]).toEqual(['doesNotFit', 2])
    expect([atThreeDays.fit, atThreeDays.doesNotFitDays]).toEqual(['doesNotFit', 3])
  })

  it('fits the heavy product with one day between rounds: 1/120 planet per unit, 120 a day at most, no days stated', () => {
    const row = rankB(WHOLE_VOLUME, UNCAPPED_B_MARKET, { ...DEFAULT_SETTINGS, daysBetweenRounds: 1 }, heavyCatalog)

    expect(row.fit).toBe('ok')
    expect(row.doesNotFitDays).toBeNull()
    expect(row.planetsPerUnit).toBeCloseTo(1 / 120, 12)
    expect(row.maxOutputPerDay).toBeCloseTo(120, 6)
  })
})

describe('rankProduct on a P3 whose made P2 has no price', () => {
  it('has no cost, margin, profit nor purchases, while the fit, the output and the price of the product stay known', () => {
    const row = rankProduct(ROBOTICS, withSellPrices(PLANNER_CATALOG, { [COOLANT]: null }), DEFAULT_SETTINGS, UNCAPPED_ROBOTICS_MARKET, WHOLE_VOLUME)

    expect(row.choices[COOLANT]).toBe('produce')
    expect(row.fit).toBe('ok')
    expect(row.price).toBe(30_000)
    expect(row.outputPerDay).toBeCloseTo(187.2, 6)
    expect([row.unitCost, row.margin]).toEqual([null, null])
    expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth, row.weeklyPurchasesIsk]).toEqual([null, null, null, null])
  })
})

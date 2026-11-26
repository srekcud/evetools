import { DEFAULT_SETTINGS } from '@/utils/planetaryPlanner'
import { bestChainFor, rankProduct, type PlannerMarket, type RankingOptions } from '@/utils/planetaryRanking'
import {
  BROADCAST_NODE,
  COOLANT,
  MECHANICAL_PARTS,
  PLANNER_CATALOG,
  PLANNER_MARKET,
  RANKING_CATALOG,
  RANKING_INPUT_A,
  RANKING_MARKET,
  RANKING_PRODUCT_B,
  REACTIVE_METALS,
  ROBOTICS,
  TOXIC_METALS,
  WATER,
  withSellPrices,
  withTypeVolume,
} from '../planetaryPlannerFixtures'

const ONE_PLANET: RankingOptions = { baseCount: 1, volumeSharePercent: 5, tier: 'all' }
const MARKET_WITH_BROADCAST_NODE: PlannerMarket = {
  ...PLANNER_MARKET,
  products: [...PLANNER_MARKET.products, { typeId: BROADCAST_NODE, averageDailyVolume: 40, averagePrice: 880_000, priceChange: -2.5, lastDate: '2026-11-23' }],
}

describe('bestChainFor', () => {
  it('buys every P1 and the P2 that costs more to make than to buy, makes the others and leaves the target out', () => {
    const choices = bestChainFor(BROADCAST_NODE, PLANNER_CATALOG, DEFAULT_SETTINGS)

    expect([choices[WATER], choices[REACTIVE_METALS], choices[TOXIC_METALS]]).toEqual(['buy', 'buy', 'buy'])
    expect(choices[COOLANT]).toBe('buy')
    expect(choices[MECHANICAL_PARTS]).toBe('produce')
    expect(choices[ROBOTICS]).toBe('produce')
    expect(choices[BROADCAST_NODE]).toBeUndefined()
  })

  it('makes an intermediate whose make cost equals its price and buys it when the cost is a cent above', () => {
    const equal = withSellPrices(PLANNER_CATALOG, { [WATER]: 50, [REACTIVE_METALS]: 70 })
    const aboveByACent = withSellPrices(PLANNER_CATALOG, { [WATER]: 50, [REACTIVE_METALS]: 70.01 })

    expect(bestChainFor(ROBOTICS, equal, DEFAULT_SETTINGS)[COOLANT]).toBe('produce')
    expect(bestChainFor(ROBOTICS, aboveByACent, DEFAULT_SETTINGS)[COOLANT]).toBe('buy')
  })

  it('makes an intermediate whose price or whose make cost is unknown', () => {
    const withoutCoolantPrice = withSellPrices(PLANNER_CATALOG, { [COOLANT]: null })
    const withoutReactiveMetalsPrice = withSellPrices(PLANNER_CATALOG, { [REACTIVE_METALS]: null })

    expect(bestChainFor(ROBOTICS, withoutCoolantPrice, DEFAULT_SETTINGS)[COOLANT]).toBe('produce')
    expect(bestChainFor(ROBOTICS, withoutReactiveMetalsPrice, DEFAULT_SETTINGS)[COOLANT]).toBe('produce')
  })

  it('buys a P3 whose make cost is above its price and decides its P2 before it', () => {
    const cheapRobotics = withSellPrices(PLANNER_CATALOG, { [ROBOTICS]: 5_000 })
    const choices = bestChainFor(BROADCAST_NODE, cheapRobotics, DEFAULT_SETTINGS)

    expect(choices[ROBOTICS]).toBe('buy')
    expect(choices[COOLANT]).toBe('buy')
    expect(choices[MECHANICAL_PARTS]).toBe('produce')
  })
})

describe('rankProduct on a P4 whose chain crosses three tiers', () => {
  it('costs 86,560 a unit: Robotics made from the Coolant bought and the Mechanical Parts made from bought P1', () => {
    const row = rankProduct(BROADCAST_NODE, PLANNER_CATALOG, DEFAULT_SETTINGS, MARKET_WITH_BROADCAST_NODE, ONE_PLANET)

    expect(row.tier).toBe('P4')
    expect(row.price).toBe(900_000)
    expect(row.unitCost).toBeCloseTo(86_560, 4)
    expect(row.margin).toBeCloseTo(813_440 / 900_000, 9)
    expect(row.chain).toEqual([
      { tier: 'P1', produced: 0, bought: 2 },
      { tier: 'P2', produced: 1, bought: 1 },
      { tier: 'P3', produced: 1, bought: 0 },
    ])
    expect(row.choices[COOLANT]).toBe('buy')
    expect(row.choices[MECHANICAL_PARTS]).toBe('produce')
  })

  it('adds the planets of the three tiers: 43/1560 planet per unit a day, 1,560/43 a day at most, 2 a day under the volume cap, 1 planet used', () => {
    const row = rankProduct(BROADCAST_NODE, PLANNER_CATALOG, DEFAULT_SETTINGS, MARKET_WITH_BROADCAST_NODE, ONE_PLANET)

    expect(row.fit).toBe('ok')
    expect(row.planetsPerUnit).toBeCloseTo(43 / 1_560, 10)
    expect(row.maxOutputPerDay).toBeCloseTo(1_560 / 43, 8)
    expect(row.outputPerDay).toBeCloseTo(2, 8)
    expect(row.capped).toBe(true)
    expect(row.planetsUsed).toBe(1)
    expect(row.planTarget).toBe(2)
  })

  it('earns 1,626,880 a day, 11,388,160 a week and 48,806,400 a month and buys 582,400 of Coolant and P1 a week', () => {
    const row = rankProduct(BROADCAST_NODE, PLANNER_CATALOG, DEFAULT_SETTINGS, MARKET_WITH_BROADCAST_NODE, ONE_PLANET)

    expect(row.profitPerDay).toBeCloseTo(1_626_880, 3)
    expect(row.profitPerWeek).toBeCloseTo(11_388_160, 2)
    expect(row.profitPerMonth).toBeCloseTo(48_806_400, 1)
    expect(row.weeklyPurchasesIsk).toBeCloseTo(582_400, 3)
  })

  it('adds the purchases of a P1 shared by two made P2 and counts each distinct P1 once in the chain', () => {
    const coolantWorthMaking = withSellPrices(PLANNER_CATALOG, { [COOLANT]: 2_000 })

    const row = rankProduct(ROBOTICS, coolantWorthMaking, DEFAULT_SETTINGS, PLANNER_MARKET, ONE_PLANET)

    expect(row.chain).toEqual([
      { tier: 'P1', produced: 0, bought: 3 },
      { tier: 'P2', produced: 2, bought: 0 },
    ])
    expect(row.unitCost).toBeCloseTo(10_693.3333333, 4)
    expect(row.planetsPerUnit).toBeCloseTo(5 / 936, 10)
    expect(row.outputPerDay).toBeCloseTo(23, 8)
    expect(row.profitPerDay).toBeCloseTo(444_053.3333, 2)
    expect(row.weeklyPurchasesIsk).toBeCloseTo(1_438_266.6667, 2)
  })
})

describe('rankProduct on a product that cannot be placed', () => {
  it('does not fit when one factory volume exceeds six launchpads for the days between rounds: no output, no profit, the days stated', () => {
    const row = rankProduct(RANKING_PRODUCT_B, withTypeVolume(RANKING_CATALOG, RANKING_PRODUCT_B, 300), DEFAULT_SETTINGS, RANKING_MARKET, ONE_PLANET)

    expect(row.fit).toBe('doesNotFit')
    expect(row.doesNotFitDays).toBe(4)
    expect(row.unitCost).toBeCloseTo(440, 6)
    expect([row.planetsPerUnit, row.maxOutputPerDay, row.outputPerDay, row.planetsUsed]).toEqual([null, null, null, null])
    expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth, row.weeklyPurchasesIsk, row.planTarget]).toEqual([null, null, null, null, null])
    expect(row.capped).toBe(false)
  })

  it('is unknown when a P1 of the chain has no volume in the SDE: no output, no profit, no days stated', () => {
    const row = rankProduct(RANKING_PRODUCT_B, withTypeVolume(RANKING_CATALOG, RANKING_INPUT_A, null), DEFAULT_SETTINGS, RANKING_MARKET, ONE_PLANET)

    expect(row.fit).toBe('unknownVolume')
    expect(row.doesNotFitDays).toBeNull()
    expect([row.planetsPerUnit, row.maxOutputPerDay, row.outputPerDay, row.planetsUsed]).toEqual([null, null, null, null])
    expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth, row.weeklyPurchasesIsk, row.planTarget]).toEqual([null, null, null, null, null])
  })
})

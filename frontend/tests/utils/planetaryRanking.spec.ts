import { DEFAULT_SETTINGS } from '@/utils/planetaryPlanner'
import { rankProduct, type RankingOptions } from '@/utils/planetaryRanking'
import {
  RANKING_CATALOG,
  RANKING_INPUT_A,
  RANKING_INPUT_C,
  RANKING_MARKET,
  RANKING_PRODUCT_B,
  withMarketProduct,
  withoutMarketProduct,
  withSellPrices,
} from '../planetaryPlannerFixtures'

const ONE_PLANET: RankingOptions = { baseCount: 1, volumeSharePercent: 5, tier: 'all' }
const UNCAPPED_MARKET = withMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B, { averageDailyVolume: 100_000 })

function rankB(overrides: { catalog?: typeof RANKING_CATALOG, market?: typeof RANKING_MARKET | null, options?: RankingOptions, settings?: typeof DEFAULT_SETTINGS } = {}) {
  return rankProduct(
    RANKING_PRODUCT_B,
    overrides.catalog ?? RANKING_CATALOG,
    overrides.settings ?? DEFAULT_SETTINGS,
    overrides.market === undefined ? RANKING_MARKET : overrides.market,
    overrides.options ?? ONE_PLANET,
  )
}

describe('rankProduct on a P2 with two bought P1', () => {
  it('costs 440 a unit: the two P1 bought for 400 plus 4 % of the price of 1,000, a margin of 56 %', () => {
    const row = rankB()

    expect(row.typeId).toBe(RANKING_PRODUCT_B)
    expect(row.tier).toBe('P2')
    expect(row.price).toBe(1_000)
    expect(row.unitCost).toBeCloseTo(440, 6)
    expect(row.margin).toBeCloseTo(0.56, 9)
    expect(row.chain).toEqual([{ tier: 'P1', produced: 0, bought: 2 }])
  })

  it('needs 1/3120 planet per unit a day, so one planet outputs at most 3,120 a day', () => {
    const row = rankB()

    expect(row.fit).toBe('ok')
    expect(row.doesNotFitDays).toBeNull()
    expect(row.planetsPerUnit).toBeCloseTo(1 / 3_120, 12)
    expect(row.maxOutputPerDay).toBeCloseTo(3_120, 6)
  })

  it('caps the output at 5 % of the 20,000 average daily volume: 1,000 a day, flagged as capped', () => {
    const row = rankB()

    expect(row.outputPerDay).toBeCloseTo(1_000, 6)
    expect(row.capped).toBe(true)
    expect(row.averageDailyVolume).toBe(20_000)
  })

  it('earns 560,000 a day, 3.92 M a week and 16.8 M a month on the price of the planner, not on the average price of the market', () => {
    const row = rankB()

    expect(row.profitPerDay).toBeCloseTo(560_000, 4)
    expect(row.profitPerWeek).toBeCloseTo(3_920_000, 3)
    expect(row.profitPerMonth).toBeCloseTo(16_800_000, 2)
  })

  it('uses 1 planet of the 1 of the base and buys 2.8 M of P1 a week, and offers 1,000 a day as a plan target', () => {
    const row = rankB()

    expect(row.planetsUsed).toBe(1)
    expect(row.baseCount).toBe(1)
    expect(row.weeklyPurchasesIsk).toBeCloseTo(2_800_000, 3)
    expect(row.planTarget).toBe(1_000)
  })

  it('carries the trend and the last date of the market for the product', () => {
    const row = rankB()

    expect(row.priceChange).toBe(3.5)
    expect(row.lastDate).toBe('2026-11-25')
  })

  it('outputs the maximum of 3,120 a day, uncapped, on one planet when the volume cap is above it', () => {
    const row = rankB({ market: UNCAPPED_MARKET })

    expect(row.outputPerDay).toBeCloseTo(3_120, 6)
    expect(row.capped).toBe(false)
    expect(row.planetsUsed).toBe(1)
    expect(row.profitPerDay).toBeCloseTo(1_747_200, 3)
    expect(row.planTarget).toBe(3_120)
  })

  it('takes 4 free planets as the base: up to 12,480 a day on 4 planets', () => {
    const row = rankB({ market: withMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B, { averageDailyVolume: 1_000_000 }), options: { ...ONE_PLANET, baseCount: 4 } })

    expect(row.maxOutputPerDay).toBeCloseTo(12_480, 5)
    expect(row.outputPerDay).toBeCloseTo(12_480, 5)
    expect(row.capped).toBe(false)
    expect(row.planetsUsed).toBe(4)
    expect(row.baseCount).toBe(4)
  })

  it('gives an unknown profit, and no cap at zero, to a product absent from the market or when the market is unavailable', () => {
    for (const market of [withoutMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B), null]) {
      const row = rankB({ market })

      expect(row.averageDailyVolume).toBeNull()
      expect(row.priceChange).toBeNull()
      expect(row.lastDate).toBeNull()
      expect(row.capped).toBe(false)
      expect(row.outputPerDay).toBeCloseTo(3_120, 6)
      expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth]).toEqual([null, null, null])
    }
  })

  it('gives no cost, margin, profit nor purchase total when the price of a P1 is missing, and none either when the price of the product is missing', () => {
    for (const missing of [RANKING_INPUT_A, RANKING_INPUT_C, RANKING_PRODUCT_B]) {
      const row = rankB({ catalog: withSellPrices(RANKING_CATALOG, { [missing]: null }) })

      expect(row.unitCost).toBeNull()
      expect(row.margin).toBeNull()
      expect([row.profitPerDay, row.profitPerWeek, row.profitPerMonth]).toEqual([null, null, null])
      expect(row.weeklyPurchasesIsk).toBeNull()
    }
    expect(rankB({ catalog: withSellPrices(RANKING_CATALOG, { [RANKING_INPUT_A]: null }) }).price).toBe(1_000)
    expect(rankB({ catalog: withSellPrices(RANKING_CATALOG, { [RANKING_PRODUCT_B]: null }) }).price).toBeNull()
  })

  it('follows the price source and the tax of the settings: 396 a unit on the buy prices, 400 without tax on the sell prices', () => {
    const onBuyPrices = rankB({ settings: { ...DEFAULT_SETTINGS, purchaseSource: 'jita-buy', saleSource: 'jita-buy' } })
    const withoutTax = rankB({ settings: { ...DEFAULT_SETTINGS, taxPercent: 0 } })

    expect(onBuyPrices.price).toBe(900)
    expect(onBuyPrices.unitCost).toBeCloseTo(396, 6)
    expect(onBuyPrices.profitPerDay).toBeCloseTo(504_000, 3)
    expect(withoutTax.unitCost).toBeCloseTo(400, 6)
    expect(withoutTax.profitPerDay).toBeCloseTo(600_000, 3)
  })
})

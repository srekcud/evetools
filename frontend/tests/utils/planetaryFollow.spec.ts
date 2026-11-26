import { compareFactories, compareSales, followedTargets } from '@/utils/planetaryFollow'
import {
  BROADCAST_NODE,
  PLANNER_CATALOG,
  RANKING_INPUT_C,
  RANKING_PRODUCT_B,
  TOXIC_METALS,
  WATER,
  withSellPrices,
} from '../planetaryPlannerFixtures'
import { TEMPLATE_B_AND_M, TEMPLATE_B_LINE, TEMPLATE_CATALOG, makeTemplate } from '../planetaryTemplatesFixtures'

describe('followedTargets on a P2 made from two bought P1', () => {
  it('targets one P2 factory and sells 120 x 1,000 = 120,000 a day for a profit of 67,200 a day', () => {
    expect(followedTargets(TEMPLATE_B_LINE, TEMPLATE_CATALOG)).toEqual({
      factoriesByTier: { P1: 0, P2: 1, P3: 0, P4: 0 },
      salesPerDay: 120_000,
      profitPerDay: 67_200,
      incomplete: false,
    })
  })

  it('sells at the price source saved in the template: 60 x 900 + 18 x 600 = 64,800 a day on Jita buy, with one P2 factory for 0.5 + 0.375 of a factory', () => {
    expect(followedTargets(TEMPLATE_B_AND_M, TEMPLATE_CATALOG)).toEqual({
      factoriesByTier: { P1: 0, P2: 1, P3: 0, P4: 0 },
      salesPerDay: 64_800,
      profitPerDay: 34_695,
      incomplete: false,
    })
  })

  it('has no sales and no profit, never 0, when the selling price of the product is missing, and flags the targets as incomplete', () => {
    const catalog = withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: null })

    expect(followedTargets(TEMPLATE_B_LINE, catalog)).toEqual({
      factoriesByTier: { P1: 0, P2: 1, P3: 0, P4: 0 },
      salesPerDay: null,
      profitPerDay: null,
      incomplete: true,
    })
  })

  it('keeps the sales of 120,000 a day but has no profit when only the price of a bought input is missing', () => {
    const catalog = withSellPrices(TEMPLATE_CATALOG, { [RANKING_INPUT_C]: null })

    expect(followedTargets(TEMPLATE_B_LINE, catalog)).toEqual({
      factoriesByTier: { P1: 0, P2: 1, P3: 0, P4: 0 },
      salesPerDay: 120_000,
      profitPerDay: null,
      incomplete: true,
    })
  })

})

describe('followedTargets on a chain through the four tiers', () => {
  const RING = makeTemplate('tpl-ring', 'Broadcast ring', [{ typeId: BROADCAST_NODE, targetPerDay: 36 }], { [WATER]: 'produce', [TOXIC_METALS]: 'produce' })

  it('targets 18 P1, 12 P2, 3 P3 and 2 P4 factories, the 1.5 P4 factories being rounded up, and sells 36 x 900,000 = 32,400,000 a day for a profit of 36 x (900,000 - 69,408) = 29,901,312 a day', () => {
    const targets = followedTargets(RING, PLANNER_CATALOG)

    expect(targets.factoriesByTier).toEqual({ P1: 18, P2: 12, P3: 3, P4: 2 })
    expect(targets.salesPerDay).toBe(32_400_000)
    expect(targets.profitPerDay).toBeCloseTo(29_901_312, 4)
    expect(targets.incomplete).toBe(false)
  })

})

describe('compareFactories', () => {
  it('compares each tier with the colonies: P4 ok, P3 missing 2, P2 free 2 and the unused P1 ok', () => {
    expect(compareFactories({ P1: 0, P2: 8, P3: 3, P4: 1 }, { P1: 0, P2: 10, P3: 1, P4: 1 })).toEqual([
      { tier: 'P1', planned: 0, real: 0, gap: 0, state: 'ok' },
      { tier: 'P2', planned: 8, real: 10, gap: 2, state: 'free' },
      { tier: 'P3', planned: 3, real: 1, gap: -2, state: 'missing' },
      { tier: 'P4', planned: 1, real: 1, gap: 0, state: 'ok' },
    ])
  })

  it('has an unknown state and no gap, never 0, on every tier while the account is not loaded', () => {
    expect(compareFactories({ P1: 0, P2: 0, P3: 3, P4: 0 }, null)).toEqual([
      { tier: 'P1', planned: 0, real: null, gap: null, state: 'unknown' },
      { tier: 'P2', planned: 0, real: null, gap: null, state: 'unknown' },
      { tier: 'P3', planned: 3, real: null, gap: null, state: 'unknown' },
      { tier: 'P4', planned: 0, real: null, gap: null, state: 'unknown' },
    ])
  })

  it('reports factories built in a tier the plan does not use as free and a planned tier without any factory as missing', () => {
    const gaps = compareFactories({ P1: 0, P2: 5, P3: 0, P4: 2 }, { P1: 4, P2: 0, P3: 0, P4: 2 })

    expect(gaps.map(gap => [gap.tier, gap.gap, gap.state])).toEqual([
      ['P1', 4, 'free'],
      ['P2', -5, 'missing'],
      ['P3', 0, 'ok'],
      ['P4', 0, 'ok'],
    ])
  })

})

describe('compareSales', () => {
  it('turns 900,000 ISK realised over 30 days into 30,000 a day against 120,000 planned: gap -90,000, ratio 0.25', () => {
    expect(compareSales(120_000, { realisedIsk: 900_000, days: 30 })).toEqual({ planned: 120_000, real: 30_000, gap: -90_000, ratio: 0.25 })
  })

  it('divides by the one day of a one day period: 48,000 realised is 48,000 a day, gap -72,000, ratio 0.4', () => {
    expect(compareSales(120_000, { realisedIsk: 48_000, days: 1 })).toEqual({ planned: 120_000, real: 48_000, gap: -72_000, ratio: 0.4 })
  })

  it('has a gap of exactly 0 and a ratio of 1 when the realised sales equal the planned ones', () => {
    expect(compareSales(30_000, { realisedIsk: 900_000, days: 30 })).toEqual({ planned: 30_000, real: 30_000, gap: 0, ratio: 1 })
  })

  it('has a real of 0, a gap of -120,000 and a ratio of 0 when nothing was realised against a plan of 120,000', () => {
    expect(compareSales(120_000, { realisedIsk: 0, days: 30 })).toEqual({ planned: 120_000, real: 0, gap: -120_000, ratio: 0 })
  })

  it('has no ratio, never a division by 0, when the planned sales are 0, and the gap is the realised sales', () => {
    expect(compareSales(0, { realisedIsk: 900_000, days: 30 })).toEqual({ planned: 0, real: 30_000, gap: 30_000, ratio: null })
  })

  it('has a gap of 0 and no ratio when nothing is planned and nothing was realised', () => {
    expect(compareSales(0, { realisedIsk: 0, days: 30 })).toEqual({ planned: 0, real: 0, gap: 0, ratio: null })
  })

  it('has no gap and no ratio, never 0, when the planned sales are unknown, and still shows the realised sales', () => {
    expect(compareSales(null, { realisedIsk: 900_000, days: 30 })).toEqual({ planned: null, real: 30_000, gap: null, ratio: null })
  })
})

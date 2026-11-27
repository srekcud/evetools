import { DEFAULT_SETTINGS, type PlannerSettings, type PurchaseSource, type SaleSource } from '@/utils/planetaryPlanner'
import { bestChainFor, rankProduct, type RankingOptions } from '@/utils/planetaryRanking'
import {
  COOLANT,
  PLANNER_CATALOG,
  RANKING_CATALOG,
  RANKING_INPUT_A,
  RANKING_INPUT_C,
  RANKING_MARKET,
  RANKING_PRODUCT_B,
  ROBOTICS,
  withPrices,
} from '../planetaryPlannerFixtures'

const ONE_PLANET: RankingOptions = { baseCount: 1, volumeSharePercent: 5, tier: 'all' }

function settingsOf(purchaseSource: PurchaseSource, saleSource: SaleSource): PlannerSettings {
  return { ...DEFAULT_SETTINGS, purchaseSource, saleSource }
}

function rankB(catalog: typeof RANKING_CATALOG, settings: PlannerSettings) {
  return rankProduct(RANKING_PRODUCT_B, catalog, settings, RANKING_MARKET, ONE_PLANET)
}

describe('planetaryRanking purchase and sale sources', () => {
  it('costs the bought P1 at the purchase source and prices the product, its margin and its profit at the sale source', () => {
    const withHubAndPaid = withPrices(RANKING_CATALOG, {
      [RANKING_INPUT_A]: { hub: 15, paid: 12 },
      [RANKING_INPUT_C]: { hub: 25 },
      [RANKING_PRODUCT_B]: { hub: 950 },
    })

    const jitaBuyToJitaSell = rankB(withHubAndPaid, settingsOf('jita-buy', 'jita-sell'))
    const jitaSellToJitaBuy = rankB(withHubAndPaid, settingsOf('jita-sell', 'jita-buy'))
    const bestToHub = rankB(withHubAndPaid, settingsOf('best', 'hub'))

    expect([jitaBuyToJitaSell.price, jitaBuyToJitaSell.unitCost, jitaBuyToJitaSell.margin]).toEqual([1_000, 400, 0.6])
    expect(jitaBuyToJitaSell.profitPerDay).toBeCloseTo(600_000, 3)
    expect([jitaSellToJitaBuy.price, jitaSellToJitaBuy.unitCost]).toEqual([900, 436])
    expect(jitaSellToJitaBuy.margin).toBeCloseTo(464 / 900, 9)
    expect(jitaSellToJitaBuy.profitPerDay).toBeCloseTo(464_000, 3)
    expect([bestToHub.price, bestToHub.unitCost]).toEqual([950, 334])
    expect(bestToHub.margin).toBeCloseTo(616 / 950, 9)
    expect(bestToHub.profitPerDay).toBeCloseTo(616_000, 3)
  })

  it('decides to buy or make an intermediate from the purchase price: Jita sell buys the Coolant, Jita buy or a hub sale price lets it be made', () => {
    const catalog = withPrices(PLANNER_CATALOG, { [COOLANT]: { sell: 1_630, buy: 1_500, hub: 500 } })

    expect(bestChainFor(ROBOTICS, catalog, settingsOf('jita-sell', 'jita-sell'))[COOLANT]).toBe('buy')
    expect(bestChainFor(ROBOTICS, catalog, settingsOf('jita-sell', 'hub'))[COOLANT]).toBe('produce')
    expect(bestChainFor(ROBOTICS, catalog, settingsOf('jita-buy', 'jita-sell'))[COOLANT]).toBe('produce')
  })
})

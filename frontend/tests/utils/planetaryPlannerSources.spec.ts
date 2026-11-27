import {
  DEFAULT_SETTINGS,
  buildPlan,
  buildTree,
  produceCost,
  purchasePrice,
  salePrice,
  starOf,
  unitCost,
  type PlannerCatalog,
  type PlannerSettings,
  type PurchaseSource,
  type SaleSource,
} from '@/utils/planetaryPlanner'
import { COOLANT, PLANNER_CATALOG, WATER, withPrices } from '../planetaryPlannerFixtures'

const SOURCE_INPUT = 94_001
const SOURCE_PRODUCT = 94_002
const UNKNOWN_TYPE = 94_999
const PRODUCT_PER_DAY = 24

const SOURCES_CATALOG: PlannerCatalog = {
  recipes: [{ outputTypeId: SOURCE_PRODUCT, outputQuantity: 1, cycleSeconds: 3_600, inputs: [{ typeId: SOURCE_INPUT, quantity: 10 }] }],
  types: [
    { typeId: SOURCE_INPUT, name: 'Source Input', tier: 'P1', volume: 0.38 },
    { typeId: SOURCE_PRODUCT, name: 'Source Product', tier: 'P2', volume: 1.5 },
  ],
  prices: [
    { typeId: SOURCE_INPUT, sell: 100, buy: 70, hub: 90, paid: 80 },
    { typeId: SOURCE_PRODUCT, sell: 1_000, buy: 900, hub: 950, paid: 880 },
  ],
  planetTypes: [],
  extractionPlanetTypes: [],
}

function settingsOf(purchaseSource: PurchaseSource, saleSource: SaleSource, taxPercent = 4): PlannerSettings {
  return { ...DEFAULT_SETTINGS, purchaseSource, saleSource, taxPercent }
}

function productPlan(catalog: PlannerCatalog, settings: PlannerSettings) {
  return buildPlan([{ typeId: SOURCE_PRODUCT, targetPerDay: PRODUCT_PER_DAY }], catalog, {}, settings)
}

describe('planetaryPlanner purchase and sale sources', () => {
  it('reads the purchase price of each of the five sources', () => {
    const sources: PurchaseSource[] = ['jita-sell', 'jita-buy', 'hub', 'paid', 'best']

    expect(sources.map(source => purchasePrice(SOURCE_INPUT, SOURCES_CATALOG, source))).toEqual([100, 70, 90, 80, 80])
  })

  it('takes the lowest known of paid, hub and Jita sell for best, ignores Jita buy, and gives unknown only when none is known', () => {
    const onlySell = withPrices(SOURCES_CATALOG, { [SOURCE_INPUT]: { hub: null, paid: null } })
    const sellAndHub = withPrices(SOURCES_CATALOG, { [SOURCE_INPUT]: { paid: null } })
    const paidAboveSell = withPrices(SOURCES_CATALOG, { [SOURCE_INPUT]: { hub: null, paid: 95 } })
    const noneKnown = withPrices(SOURCES_CATALOG, { [SOURCE_INPUT]: { sell: null, hub: null, paid: null } })
    const cheapBuy = withPrices(SOURCES_CATALOG, { [SOURCE_INPUT]: { buy: 10 } })

    expect([onlySell, sellAndHub, paidAboveSell, noneKnown, cheapBuy].map(catalog => purchasePrice(SOURCE_INPUT, catalog, 'best'))).toEqual([100, 90, 95, null, 80])
  })

  it('gives an unknown price, never another source, when the chosen price is missing or the type is not in the catalog', () => {
    const noHubNoPaid = withPrices(SOURCES_CATALOG, { [SOURCE_INPUT]: { hub: null, paid: null } })

    expect([purchasePrice(SOURCE_INPUT, noHubNoPaid, 'hub'), purchasePrice(SOURCE_INPUT, noHubNoPaid, 'paid'), salePrice(SOURCE_INPUT, noHubNoPaid, 'hub')]).toEqual([null, null, null])
    expect([purchasePrice(UNKNOWN_TYPE, SOURCES_CATALOG, 'best'), salePrice(UNKNOWN_TYPE, SOURCES_CATALOG, 'jita-sell')]).toEqual([null, null])
  })

  it('reads the sale price of each of the three sources', () => {
    const sources: SaleSource[] = ['jita-sell', 'jita-buy', 'hub']

    expect(sources.map(source => salePrice(SOURCE_PRODUCT, SOURCES_CATALOG, source))).toEqual([1_000, 900, 950])
  })

  it('costs 740 and earns 260 a unit on Jita buy purchases sold at Jita sell, costs 838 and earns 112 on the best purchase sold at the hub, and is unknown on paid with no purchase', () => {
    const jitaBuyToJitaSell = productPlan(SOURCES_CATALOG, settingsOf('jita-buy', 'jita-sell'))
    const bestToHub = productPlan(SOURCES_CATALOG, settingsOf('best', 'hub'))
    const noPurchase = productPlan(withPrices(SOURCES_CATALOG, { [SOURCE_INPUT]: { paid: null } }), settingsOf('paid', 'jita-sell'))

    expect(jitaBuyToJitaSell.summary.unitCost[0]).toBeCloseTo(740, 6)
    expect(jitaBuyToJitaSell.summary.profitPerDay[0]).toBeCloseTo(6_240, 6)
    expect(bestToHub.summary.unitCost[0]).toBeCloseTo(838, 6)
    expect(bestToHub.summary.profitPerDay[0]).toBeCloseTo(2_688, 6)
    expect(noPurchase.summary.unitCost[0]).toBeNull()
    expect(noPurchase.summary.profitPerDay[0]).toBeNull()
    expect(noPurchase.summary.profitIncomplete).toBe(true)
  })

  it('puts the purchase price on the node and its weekly purchase, and the sale price on the node beside it', () => {
    const result = productPlan(SOURCES_CATALOG, settingsOf('best', 'hub'))
    const product = result.trees[0]
    const input = product.children[0]

    expect([product.price, product.salePrice, product.produceCost]).toEqual([880, 950, 838])
    expect([input.price, input.salePrice]).toEqual([80, 90])
    expect(result.summary.weeklyPurchases).toEqual([{ typeId: SOURCE_INPUT, quantity: 1_680, isk: 134_400 }])
    expect(result.summary.weeklyPurchasesIsk).toBe(134_400)
  })

  it('taxes the sale price, stars against the purchase price and earns the target on the sale price minus the cost', () => {
    const hubCoolant = withPrices(PLANNER_CATALOG, { [COOLANT]: { sell: 1_650, hub: 5_000 } })
    const cheapHubCoolant = withPrices(PLANNER_CATALOG, { [COOLANT]: { sell: 1_650, hub: 1_000 } })
    const settings = settingsOf('jita-sell', 'hub')
    const tree = buildTree({ typeId: COOLANT, targetPerDay: 120 }, hubCoolant, {}, settings)

    expect(produceCost(COOLANT, hubCoolant, {}, settings)).toBeCloseTo(1_800, 6)
    expect(unitCost(COOLANT, hubCoolant, { [COOLANT]: 'buy' }, settings)).toBe(1_650)
    expect(starOf(COOLANT, false, hubCoolant, {}, settings)).toBe('buy')
    expect(starOf(COOLANT, false, cheapHubCoolant, {}, settings)).toBe('produce')
    expect(buildPlan([{ typeId: COOLANT, targetPerDay: 120 }], hubCoolant, {}, settings).summary.profitPerDay[0]).toBeCloseTo(384_000, 6)
    expect([tree.price, tree.salePrice]).toEqual([1_650, 5_000])
  })

  it('leaves the cost and the profit unknown when the sale price is unknown, takes the P1 tax from its sale price and never reads the price of a P0', () => {
    const noHubCoolant = withPrices(PLANNER_CATALOG, { [COOLANT]: { hub: null } })
    const hubWater = withPrices(PLANNER_CATALOG, { [WATER]: { hub: 100 } })
    const settings = settingsOf('jita-sell', 'hub')

    expect(produceCost(COOLANT, noHubCoolant, {}, settings)).toBeNull()
    expect(buildPlan([{ typeId: COOLANT, targetPerDay: 120 }], noHubCoolant, {}, settings).summary.profitPerDay[0]).toBeNull()
    expect(starOf(COOLANT, false, noHubCoolant, {}, settings)).toBeNull()
    expect(produceCost(WATER, hubWater, {}, settings)).toBeCloseTo(4, 9)
    expect(produceCost(WATER, hubWater, {}, DEFAULT_SETTINGS)).toBeCloseTo(2.4, 9)
  })
})

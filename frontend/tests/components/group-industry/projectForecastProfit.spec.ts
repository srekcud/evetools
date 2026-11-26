import { describe, expect, it } from 'vitest'
import { buildForecastProfit, hasProductsLeftToSell } from '@/components/group-industry/projectForecastProfit'
import type { Distribution, SellForecast } from '@/stores/group-industry/types'

const SELL_STRUCTURE = { id: 1_040_804_972_352, name: 'Perimeter - Tranquility Trade Center' }

function distributionOf(overrides: Partial<Distribution> = {}): Distribution {
  return {
    id: 'project-nyx',
    totalRevenue: 33_300_000,
    brokerFee: 0,
    salesTax: 0,
    netRevenue: 30_000_000,
    totalProjectCost: 100_000_000,
    marginPercent: null,
    awaitingSales: false,
    unsoldProducts: [],
    projectedMarginPercent: null,
    members: [],
    ...overrides,
  }
}

function forecastOf(totals: Partial<SellForecast['totals']> = {}, sellStructure: SellForecast['sellStructure'] = SELL_STRUCTURE): SellForecast {
  return {
    projectId: 'project-nyx',
    sellStructure,
    products: [{ typeId: 1, typeName: 'Barrage L', quantity: 10, structure: null, jita: { name: 'Jita', unitPrice: 1, coveragePercent: null, feeRate: 0, netUnitPrice: 1, netRevenue: 1, daysToSell: 1 } }],
    totals: {
      structureNetRevenue: 80_000_000,
      jitaNetRevenue: 70_000_000,
      structureUnknownProductCount: 0,
      jitaUnknownProductCount: 0,
      remainingCost: 20_000_000,
      remainingCostUnknownLineCount: 0,
      structureProfit: null,
      jitaProfit: null,
      ...totals,
    },
  }
}

describe('buildForecastProfit', () => {
  it('adds the revenue already sold to the revenue left to sell and subtracts the cost incurred and the cost left, once per market', () => {
    const profit = buildForecastProfit(distributionOf(), forecastOf())

    expect(profit.structure).toEqual({ netRevenueSold: 30_000_000, netRevenueLeft: 80_000_000, costIncurred: 100_000_000, costLeft: 20_000_000, profit: -10_000_000, marginPercent: -10_000_000 / 120_000_000 * 100 })
    expect(profit.jita).toEqual({ netRevenueSold: 30_000_000, netRevenueLeft: 70_000_000, costIncurred: 100_000_000, costLeft: 20_000_000, profit: -20_000_000, marginPercent: -20_000_000 / 120_000_000 * 100 })
  })

  it('has no structure column without a sell structure', () => {
    const profit = buildForecastProfit(distributionOf(), forecastOf({ structureNetRevenue: null }, null))

    expect(profit.structure).toBeNull()
    expect(profit.jita.profit).toBe(-20_000_000)
  })

  it.each([
    ['the net revenue already sold', distributionOf({ netRevenue: null }), forecastOf()],
    ['the cost incurred', distributionOf({ totalProjectCost: null }), forecastOf()],
    ['the cost left to produce', distributionOf(), forecastOf({ remainingCost: null })],
  ])('leaves the profit and the margin unknown, never 0, when %s is unknown', (_term, distribution, forecast) => {
    const profit = buildForecastProfit(distribution, forecast)

    expect(profit.jita.profit).toBeNull()
    expect(profit.jita.marginPercent).toBeNull()
    expect(profit.structure?.profit).toBeNull()
  })

  it('leaves only the market with an unknown net revenue left to sell unknown', () => {
    const profit = buildForecastProfit(distributionOf(), forecastOf({ structureNetRevenue: null }))

    expect(profit.structure?.profit).toBeNull()
    expect(profit.jita.profit).toBe(-20_000_000)
  })

  it('counts the revenue already sold as 0 while no sale is recorded, which is a known fact', () => {
    const profit = buildForecastProfit(distributionOf({ awaitingSales: true, netRevenue: null, totalRevenue: 0 }), forecastOf())

    expect(profit.jita.netRevenueSold).toBe(0)
    expect(profit.jita.profit).toBe(-50_000_000)
  })

  it('leaves the margin unknown when the whole cost is 0', () => {
    const profit = buildForecastProfit(distributionOf({ totalProjectCost: 0 }), forecastOf({ remainingCost: 0 }))

    expect(profit.jita.profit).toBe(100_000_000)
    expect(profit.jita.marginPercent).toBeNull()
  })
})

describe('hasProductsLeftToSell', () => {
  it('is false without a forecast and when the forecast lists no product', () => {
    expect(hasProductsLeftToSell(null)).toBe(false)
    expect(hasProductsLeftToSell({ ...forecastOf(), products: [] })).toBe(false)
  })

  it('is true when at least one product is left to sell', () => {
    expect(hasProductsLeftToSell(forecastOf())).toBe(true)
  })
})

import { buildIskBand, type IskBandCell, type IskBandInput } from '@/components/group-industry/projectIskBand'
import type { Distribution, SellForecast } from '@/stores/group-industry/types'
import { bomLineOf, stationQuantity, typeQuantities, TRITANIUM, ZYDRINE } from './projectFlowFixtures'

const STRUCTURE_NAME = 'Perimeter - Tranquility Trade Hub'

function distributionOf(fields: Partial<Distribution> = {}): Distribution {
  return {
    id: 'distribution',
    totalRevenue: 0,
    brokerFee: null,
    salesTax: null,
    netRevenue: null,
    totalProjectCost: 1_000,
    marginPercent: null,
    awaitingSales: true,
    unsoldProducts: [],
    projectedMarginPercent: null,
    members: [],
    ...fields,
  }
}

function forecastOf(fields: Partial<SellForecast['totals']> = {}, structure: boolean = false): SellForecast {
  return {
    projectId: 'project',
    sellStructure: structure ? { id: 1, name: STRUCTURE_NAME } : null,
    products: [],
    totals: {
      structureNetRevenue: 5_000,
      jitaNetRevenue: 4_000,
      structureUnknownProductCount: 0,
      jitaUnknownProductCount: 0,
      remainingCost: 500,
      remainingCostUnknownLineCount: 0,
      structureProfit: 4_500,
      jitaProfit: 3_500,
      ...fields,
    },
  }
}

function inputOf(fields: Partial<IskBandInput> = {}): IskBandInput {
  return {
    distribution: distributionOf(),
    quantities: [],
    bomItems: [],
    forecast: forecastOf(),
    personalUse: false,
    ...fields,
  }
}

function cellOf(input: IskBandInput, key: IskBandCell['key']): IskBandCell {
  const cell = buildIskBand(input).cells.find(candidate => candidate.key === key)
  if (cell === undefined) throw new Error(`cell ${key} missing`)
  return cell
}

function keysOf(input: IskBandInput): string[] {
  return buildIskBand(input).cells.map(cell => cell.key)
}

describe('project ISK band', () => {
  describe('committed', () => {
    it('is the cost the project engaged so far, as a known figure', () => {
      expect(cellOf(inputOf(), 'committed')).toMatchObject({ amount: 1_000, estimate: false })
    })

    it('is unknown when the engaged cost is unknown, never zero', () => {
      const input = inputOf({ distribution: distributionOf({ totalProjectCost: null }) })

      expect(cellOf(input, 'committed').amount).toBeNull()
    })

    it('is unknown while the distribution is not loaded', () => {
      expect(cellOf(inputOf({ distribution: null }), 'committed').amount).toBeNull()
    })

    it('is unknown when the answer is not a distribution, whatever else it carries', () => {
      const notADistribution = { totalProjectCost: 1_000 } as unknown as Distribution

      expect(cellOf(inputOf({ distribution: notADistribution }), 'committed').amount).toBeNull()
      expect(cellOf(inputOf({ distribution: [] as unknown as Distribution }), 'committed').amount).toBeNull()
    })
  })

  describe('on site', () => {
    const bomItems = [
      bomLineOf({ id: 'a', ...TRITANIUM, estimatedPrice: 5 }),
      bomLineOf({ id: 'b', ...ZYDRINE, estimatedPrice: 1_000 }),
    ]

    it('values what the stations hold at the estimated price of the lines', () => {
      const quantities = [
        typeQuantities(TRITANIUM, [stationQuantity({ locationId: 1, stationName: 'A', onSite: 100 }), stationQuantity({ locationId: 2, stationName: 'B', onSite: 50 })]),
        typeQuantities(ZYDRINE, [stationQuantity({ locationId: 1, stationName: 'A', onSite: 2 })]),
      ]

      expect(cellOf(inputOf({ quantities, bomItems }), 'onSite')).toMatchObject({ amount: 100 * 5 + 50 * 5 + 2 * 1_000, estimate: false })
    })

    it('is a known zero when nothing is on site', () => {
      const quantities = [typeQuantities(TRITANIUM, [stationQuantity({ locationId: 1, stationName: 'A', onSite: 0 })])]

      expect(cellOf(inputOf({ quantities, bomItems }), 'onSite').amount).toBe(0)
    })

    it('is unknown when a station quantity is unknown', () => {
      const quantities = [typeQuantities(TRITANIUM, [stationQuantity({ locationId: 1, stationName: 'A', onSite: null })])]

      expect(cellOf(inputOf({ quantities, bomItems }), 'onSite').amount).toBeNull()
    })

    it('is unknown when the stations of a type are unknown', () => {
      const quantities = [typeQuantities(TRITANIUM, null, 'plan_unavailable')]

      expect(cellOf(inputOf({ quantities, bomItems }), 'onSite').amount).toBeNull()
    })

    it('is unknown when what is on site has no estimated price', () => {
      const quantities = [typeQuantities(TRITANIUM, [stationQuantity({ locationId: 1, stationName: 'A', onSite: 10 })])]
      const unpriced = [bomLineOf({ id: 'a', ...TRITANIUM, estimatedPrice: null })]

      expect(cellOf(inputOf({ quantities, bomItems: unpriced }), 'onSite').amount).toBeNull()
    })

    it('is unknown when the quantities could not be read', () => {
      expect(cellOf(inputOf({ quantities: null, bomItems }), 'onSite').amount).toBeNull()
    })

    it('takes the price of the material line when a job line of the same type has none', () => {
      const quantities = [typeQuantities(TRITANIUM, [stationQuantity({ locationId: 1, stationName: 'A', onSite: 10 })])]
      const lines = [
        bomLineOf({ id: 'job', ...TRITANIUM, isJob: true, estimatedPrice: null }),
        bomLineOf({ id: 'material', ...TRITANIUM, estimatedPrice: 5 }),
      ]

      expect(cellOf(inputOf({ quantities, bomItems: lines }), 'onSite').amount).toBe(50)
    })
  })

  describe('expected or cashed', () => {
    it('shows the forecast net revenue as a dashed estimate on Jita while nothing is sold', () => {
      const band = buildIskBand(inputOf())

      expect(band.cells.map(cell => cell.key)).toContain('expected')
      expect(band.cells.map(cell => cell.key)).not.toContain('cashed')
      expect(cellOf(inputOf(), 'expected')).toMatchObject({ amount: 4_000, estimate: true })
    })

    it('uses the sell structure forecast when the project has one', () => {
      const input = inputOf({ forecast: forecastOf({}, true) })

      expect(cellOf(input, 'expected')).toMatchObject({ amount: 5_000, estimate: true, market: STRUCTURE_NAME })
    })

    it('names no market on the Jita forecast', () => {
      expect(cellOf(inputOf(), 'expected').market).toBeNull()
    })

    it('is unknown when the forecast revenue is unknown', () => {
      const input = inputOf({ forecast: forecastOf({ jitaNetRevenue: null }) })

      expect(cellOf(input, 'expected')).toMatchObject({ amount: null })
    })

    it('is unknown when the forecast is not available', () => {
      expect(cellOf(inputOf({ forecast: null }), 'expected').amount).toBeNull()
    })

    it('shows the net revenue as a known figure once a sale is recorded', () => {
      const input = inputOf({ distribution: distributionOf({ awaitingSales: false, netRevenue: 3_000, totalRevenue: 3_200 }) })

      expect(keysOf(input)).toContain('cashed')
      expect(keysOf(input)).not.toContain('expected')
      expect(cellOf(input, 'cashed')).toMatchObject({ amount: 3_000, estimate: false })
    })

    it('shows an unknown cashed figure when the net revenue is unknown', () => {
      const input = inputOf({ distribution: distributionOf({ awaitingSales: false, netRevenue: null, totalRevenue: 3_200 }) })

      expect(cellOf(input, 'cashed').amount).toBeNull()
    })

    it('counts the unsold products beside the cashed figure', () => {
      const input = inputOf({
        distribution: distributionOf({ awaitingSales: false, netRevenue: 3_000, unsoldProducts: [{ typeId: 1, typeName: 'Ishtar', unsoldQuantity: 4, lastUnitPrice: null }] }),
      })

      expect(cellOf(input, 'cashed').unsoldProductCount).toBe(1)
    })
  })

  describe('profit', () => {
    it('is an estimate built from the forecast profit and the committed cost while nothing is sold', () => {
      expect(cellOf(inputOf(), 'profit')).toMatchObject({ amount: 3_500 - 1_000, estimate: true })
    })

    it('follows the sell structure forecast when the project has one', () => {
      const input = inputOf({ forecast: forecastOf({}, true) })

      expect(cellOf(input, 'profit').amount).toBe(4_500 - 1_000)
    })

    it('is unknown when the committed cost is unknown', () => {
      const input = inputOf({ distribution: distributionOf({ totalProjectCost: null }) })

      expect(cellOf(input, 'profit').amount).toBeNull()
    })

    it('is unknown when the forecast profit is unknown', () => {
      const input = inputOf({ forecast: forecastOf({ jitaProfit: null }) })

      expect(cellOf(input, 'profit').amount).toBeNull()
    })

    it('is a provisional estimate while products remain unsold', () => {
      const input = inputOf({
        distribution: distributionOf({ awaitingSales: false, netRevenue: 3_000, unsoldProducts: [{ typeId: 1, typeName: 'Ishtar', unsoldQuantity: 4, lastUnitPrice: null }] }),
      })

      expect(cellOf(input, 'profit')).toMatchObject({ amount: 3_000 - 1_000, estimate: true, provisional: true })
    })

    it('is a known figure once everything is sold', () => {
      const input = inputOf({ distribution: distributionOf({ awaitingSales: false, netRevenue: 3_000 }) })

      expect(cellOf(input, 'profit')).toMatchObject({ amount: 2_000, estimate: false, provisional: false })
    })

    it('can be a known loss', () => {
      const input = inputOf({ distribution: distributionOf({ awaitingSales: false, netRevenue: 400 }) })

      expect(cellOf(input, 'profit').amount).toBe(-600)
    })

    it('is unknown once sold when the net revenue is unknown', () => {
      const input = inputOf({ distribution: distributionOf({ awaitingSales: false, netRevenue: null }) })

      expect(cellOf(input, 'profit').amount).toBeNull()
    })
  })

  describe('personal use', () => {
    it('has no sale: committed, on site and the cost per unit only', () => {
      const input = inputOf({ personalUse: true, distribution: distributionOf({ costPerUnit: 250 }) })

      expect(keysOf(input)).toEqual(['committed', 'onSite', 'costPerUnit'])
      expect(cellOf(input, 'costPerUnit')).toMatchObject({ amount: 250, estimate: true })
    })

    it('shows an unknown cost per unit when the distribution gives none', () => {
      const input = inputOf({ personalUse: true, distribution: distributionOf({ costPerUnit: null }) })

      expect(cellOf(input, 'costPerUnit').amount).toBeNull()
    })
  })

  it('lists committed, on site, expected and profit in that order for a project to sell', () => {
    expect(keysOf(inputOf())).toEqual(['committed', 'onSite', 'expected', 'profit'])
  })
})

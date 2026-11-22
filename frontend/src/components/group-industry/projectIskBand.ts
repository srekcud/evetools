import type { BomItem, Distribution, SellForecast, TypeStationQuantities } from '@/stores/group-industry/types'

export type IskBandCellKey = 'committed' | 'onSite' | 'expected' | 'cashed' | 'costPerUnit' | 'profit'

export interface IskBandCell {
  key: IskBandCellKey
  amount: number | null
  estimate: boolean
  provisional: boolean
  market: string | null
  unsoldProductCount: number
}

export interface IskBand {
  cells: IskBandCell[]
}

export interface IskBandInput {
  distribution: Distribution | null
  quantities: TypeStationQuantities[] | null
  bomItems: BomItem[]
  forecast: SellForecast | null
  personalUse: boolean
}

type Figure = Pick<IskBandCell, 'amount' | 'estimate'> & Partial<Omit<IskBandCell, 'key' | 'amount' | 'estimate'>>

function cellOf(key: IskBandCellKey, figure: Figure): IskBandCell {
  return { key, provisional: false, market: null, unsoldProductCount: 0, ...figure }
}

function differenceOrUnknown(minuend: number | null, subtrahend: number | null): number | null {
  return minuend === null || subtrahend === null ? null : minuend - subtrahend
}

function pricesOfTypes(bomItems: BomItem[]): Map<number, number> {
  const prices = new Map<number, number>()
  const pricedFirst = [...bomItems].sort((left, right) => Number(left.isJob) - Number(right.isJob))
  for (const line of pricedFirst) {
    if (line.estimatedPrice !== null && !prices.has(line.typeId)) prices.set(line.typeId, line.estimatedPrice)
  }
  return prices
}

function onSiteValueOf(quantities: TypeStationQuantities[] | null, bomItems: BomItem[]): number | null {
  if (quantities === null) return null
  const prices = pricesOfTypes(bomItems)
  let value = 0
  for (const type of quantities) {
    if (type.stations === null) return null
    for (const station of type.stations) {
      if (station.onSite === null) return null
      if (station.onSite === 0) continue
      const price = prices.get(type.typeId)
      if (price === undefined) return null
      value += station.onSite * price
    }
  }
  return value
}

function isDistribution(candidate: Distribution | null): candidate is Distribution {
  return typeof candidate === 'object' && candidate !== null && !Array.isArray(candidate) && typeof candidate.awaitingSales === 'boolean' && Array.isArray(candidate.unsoldProducts)
}

function committedOf(distribution: Distribution | null): number | null {
  return distribution === null ? null : distribution.totalProjectCost
}

function forecastNetRevenueOf(forecast: SellForecast | null): number | null {
  if (forecast === null) return null
  return forecast.sellStructure === null ? forecast.totals.jitaNetRevenue : forecast.totals.structureNetRevenue
}

function forecastProfitOf(forecast: SellForecast | null): number | null {
  if (forecast === null) return null
  return forecast.sellStructure === null ? forecast.totals.jitaProfit : forecast.totals.structureProfit
}

function profitCellOf(distribution: Distribution | null, forecast: SellForecast | null): IskBandCell {
  const committed = committedOf(distribution)
  if (distribution === null || distribution.awaitingSales) {
    return cellOf('profit', { amount: differenceOrUnknown(forecastProfitOf(forecast), committed), estimate: true })
  }
  const provisional = distribution.unsoldProducts.length > 0
  return cellOf('profit', { amount: differenceOrUnknown(distribution.netRevenue, committed), estimate: provisional, provisional })
}

function saleCellOf(distribution: Distribution | null, forecast: SellForecast | null): IskBandCell {
  if (distribution !== null && !distribution.awaitingSales) {
    return cellOf('cashed', { amount: distribution.netRevenue, estimate: false, unsoldProductCount: distribution.unsoldProducts.length })
  }
  return cellOf('expected', { amount: forecastNetRevenueOf(forecast), estimate: true, market: forecast?.sellStructure?.name ?? null })
}

function costPerUnitCellOf(distribution: Distribution | null): IskBandCell {
  const costPerUnit = distribution?.costPerUnit
  return cellOf('costPerUnit', { amount: costPerUnit === undefined ? null : costPerUnit, estimate: true })
}

export function buildIskBand(input: IskBandInput): IskBand {
  const distribution = isDistribution(input.distribution) ? input.distribution : null
  const committed = cellOf('committed', { amount: committedOf(distribution), estimate: false })
  const onSite = cellOf('onSite', { amount: onSiteValueOf(input.quantities, input.bomItems), estimate: false })
  if (input.personalUse) return { cells: [committed, onSite, costPerUnitCellOf(distribution)] }
  return { cells: [committed, onSite, saleCellOf(distribution, input.forecast), profitCellOf(distribution, input.forecast)] }
}

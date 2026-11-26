import type { Distribution, SellForecast } from '@/stores/group-industry/types'

export type ForecastProfitColumn = {
  netRevenueSold: number | null
  netRevenueLeft: number | null
  costIncurred: number | null
  costLeft: number | null
  profit: number | null
  marginPercent: number | null
}

export type ForecastProfit = {
  structure: ForecastProfitColumn | null
  jita: ForecastProfitColumn
}

export function buildForecastProfit(distribution: Distribution, forecast: SellForecast): ForecastProfit {
  return {
    structure: forecast.sellStructure === null ? null : columnOf(distribution, forecast, forecast.totals.structureNetRevenue),
    jita: columnOf(distribution, forecast, forecast.totals.jitaNetRevenue),
  }
}

export function hasProductsLeftToSell(forecast: SellForecast | null): forecast is SellForecast {
  return forecast !== null && forecast.products.length > 0
}

function columnOf(distribution: Distribution, forecast: SellForecast, netRevenueLeft: number | null): ForecastProfitColumn {
  const netRevenueSold = distribution.awaitingSales ? 0 : distribution.netRevenue
  const costIncurred = distribution.totalProjectCost
  const costLeft = forecast.totals.remainingCost

  if (netRevenueSold === null || netRevenueLeft === null || costIncurred === null || costLeft === null) {
    return { netRevenueSold, netRevenueLeft, costIncurred, costLeft, profit: null, marginPercent: null }
  }

  const totalCost = costIncurred + costLeft
  const profit = netRevenueSold + netRevenueLeft - totalCost

  return { netRevenueSold, netRevenueLeft, costIncurred, costLeft, profit, marginPercent: totalCost > 0 ? profit / totalCost * 100 : null }
}

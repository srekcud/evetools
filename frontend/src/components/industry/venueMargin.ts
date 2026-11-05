export interface VenueMargin {
  revenue: number
  fees: number | null
  profit: number | null
  margin: number | null
}

export type SellVenue = 'jitaSell' | 'structureSell' | 'structureBuy' | 'contractSell'

function feeRate(venue: SellVenue, brokerFeeRate: number, salesTaxRate: number): number {
  if (venue === 'structureBuy') return salesTaxRate
  return brokerFeeRate + salesTaxRate
}

function marginAfterFees(revenue: number, totalCost: number, fees: number): VenueMargin {
  const profit = revenue - totalCost - fees
  const margin = totalCost > 0 ? (profit / totalCost) * 100 : null
  return { revenue, fees, profit, margin }
}

export function venueMargin(
  unitPrice: number,
  outputQuantity: number,
  totalCost: number,
  brokerFeeRate: number | null,
  salesTaxRate: number | null,
  venue: SellVenue,
): VenueMargin {
  const revenue = unitPrice * outputQuantity
  if (venue === 'contractSell') return marginAfterFees(revenue, totalCost, 0)
  if (brokerFeeRate === null || salesTaxRate === null) return { revenue, fees: null, profit: null, margin: null }
  return marginAfterFees(revenue, totalCost, revenue * feeRate(venue, brokerFeeRate, salesTaxRate))
}

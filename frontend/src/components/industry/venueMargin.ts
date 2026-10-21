export interface VenueMargin {
  revenue: number
  fees: number
  profit: number
  margin: number | null
}

export type SellVenue = 'jitaSell' | 'structureSell' | 'structureBuy' | 'contractSell'

function feeRate(venue: SellVenue, brokerFeeRate: number, salesTaxRate: number): number {
  if (venue === 'contractSell') return 0
  if (venue === 'structureBuy') return salesTaxRate
  return brokerFeeRate + salesTaxRate
}

export function venueMargin(
  unitPrice: number,
  outputQuantity: number,
  totalCost: number,
  brokerFeeRate: number,
  salesTaxRate: number,
  venue: SellVenue,
): VenueMargin {
  const revenue = unitPrice * outputQuantity
  const fees = revenue * feeRate(venue, brokerFeeRate, salesTaxRate)
  const profit = revenue - totalCost - fees
  const margin = totalCost > 0 ? (profit / totalCost) * 100 : null
  return { revenue, fees, profit, margin }
}

export interface VenueMargin {
  revenue: number
  fees: number
  profit: number
  margin: number
}

export function venueMargin(
  unitPrice: number,
  outputQuantity: number,
  totalCost: number,
  brokerFeeRate: number,
  salesTaxRate: number,
  isContract: boolean = false,
): VenueMargin {
  const revenue = unitPrice * outputQuantity
  const fees = isContract ? 0 : revenue * (brokerFeeRate + salesTaxRate)
  const profit = revenue - totalCost - fees
  const margin = revenue > 0 ? (profit / revenue) * 100 : 0
  return { revenue, fees, profit, margin }
}

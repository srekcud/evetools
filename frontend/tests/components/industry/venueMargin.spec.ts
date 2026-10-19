import { venueMargin } from '@/components/industry/venueMargin'

const UNIT_PRICE = 1000
const OUTPUT_QUANTITY = 10
const TOTAL_COST = 8000
const BROKER_FEE_RATE = 0.01
const SALES_TAX_RATE = 0.02

describe('venueMargin', () => {
  it('expresses the margin as profit over total cost for a market sale', () => {
    const result = venueMargin(UNIT_PRICE, OUTPUT_QUANTITY, TOTAL_COST, BROKER_FEE_RATE, SALES_TAX_RATE)

    expect(result.revenue).toBe(10000)
    expect(result.fees).toBeCloseTo(300, 6)
    expect(result.profit).toBeCloseTo(1700, 6)
    expect(result.margin).toBeCloseTo(21.25, 6)
  })

  it('expresses the margin as profit over total cost for a contract sale without fees', () => {
    const result = venueMargin(UNIT_PRICE, OUTPUT_QUANTITY, TOTAL_COST, BROKER_FEE_RATE, SALES_TAX_RATE, true)

    expect(result.revenue).toBe(10000)
    expect(result.fees).toBe(0)
    expect(result.profit).toBe(2000)
    expect(result.margin).toBe(25)
  })

  it('returns a zero margin when the total cost is zero', () => {
    const result = venueMargin(UNIT_PRICE, OUTPUT_QUANTITY, 0, BROKER_FEE_RATE, SALES_TAX_RATE)

    expect(result.revenue).toBe(10000)
    expect(result.fees).toBeCloseTo(300, 6)
    expect(result.profit).toBeCloseTo(9700, 6)
    expect(result.margin).toBe(0)
  })
})

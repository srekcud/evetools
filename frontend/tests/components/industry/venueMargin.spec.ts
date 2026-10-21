import { venueMargin } from '@/components/industry/venueMargin'

const UNIT_PRICE = 1000
const OUTPUT_QUANTITY = 10
const TOTAL_COST = 8000
const BROKER_FEE_RATE = 0.01
const SALES_TAX_RATE = 0.02

describe('venueMargin', () => {
  it('expresses the margin as profit over total cost for a market sale', () => {
    const result = venueMargin(UNIT_PRICE, OUTPUT_QUANTITY, TOTAL_COST, BROKER_FEE_RATE, SALES_TAX_RATE, 'jitaSell')

    expect(result.revenue).toBe(10000)
    expect(result.fees).toBeCloseTo(300, 6)
    expect(result.profit).toBeCloseTo(1700, 6)
    expect(result.margin).toBeCloseTo(21.25, 6)
  })

  it('expresses the margin as profit over total cost for a contract sale without fees', () => {
    const result = venueMargin(UNIT_PRICE, OUTPUT_QUANTITY, TOTAL_COST, BROKER_FEE_RATE, SALES_TAX_RATE, 'contractSell')

    expect(result.revenue).toBe(10000)
    expect(result.fees).toBe(0)
    expect(result.profit).toBe(2000)
    expect(result.margin).toBe(25)
  })

  it('leaves the margin unknown when the total cost is zero', () => {
    const result = venueMargin(UNIT_PRICE, OUTPUT_QUANTITY, 0, BROKER_FEE_RATE, SALES_TAX_RATE, 'structureSell')

    expect(result.revenue).toBe(10000)
    expect(result.fees).toBeCloseTo(300, 6)
    expect(result.profit).toBeCloseTo(9700, 6)
    expect(result.margin).toBeNull()
  })

  it('charges only the sales tax when selling to a buy order, the broker fee too on a sell order, and nothing on a contract', () => {
    const buyOrderUnitPrice = 4000
    const buyOrderOutputQuantity = 40
    const buyOrderTotalCost = 150000
    const distinctBrokerFeeRate = 0.05
    const distinctSalesTaxRate = 0.02

    const buyOrder = venueMargin(buyOrderUnitPrice, buyOrderOutputQuantity, buyOrderTotalCost, distinctBrokerFeeRate, distinctSalesTaxRate, 'structureBuy')
    const sellOrder = venueMargin(buyOrderUnitPrice, buyOrderOutputQuantity, buyOrderTotalCost, distinctBrokerFeeRate, distinctSalesTaxRate, 'structureSell')
    const contract = venueMargin(buyOrderUnitPrice, buyOrderOutputQuantity, buyOrderTotalCost, distinctBrokerFeeRate, distinctSalesTaxRate, 'contractSell')

    expect(buyOrder.revenue).toBe(160000)
    expect(buyOrder.fees).toBeCloseTo(3200, 6)
    expect(buyOrder.profit).toBeCloseTo(6800, 6)
    expect(buyOrder.margin).toBeCloseTo(4.533333333, 6)
    expect(sellOrder.revenue).toBe(160000)
    expect(sellOrder.fees).toBeCloseTo(11200, 6)
    expect(sellOrder.profit).toBeCloseTo(-1200, 6)
    expect(contract.fees).toBe(0)
    expect(contract.profit).toBe(10000)
  })
})

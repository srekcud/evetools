import { DEFAULT_SUPPLY_DAYS, MAX_SUPPLY_DAYS, buildSupplyGrid, isValidSupplyDays, planLaunchpadRefill } from '@/utils/planetarySupply'
import {
  CONSUMER_ELECTRONICS,
  COOLANT,
  FERTILIZER,
  MECHANICAL_PARTS,
  PLASMOIDS,
  WATER,
  feedOf,
  launchpadOf,
  referenceLaunchpad,
  secondLaunchpad,
  unknownCapacityLaunchpad,
} from '../planetarySupplyFixtures'

const REFERENCE_CAPACITY_DAYS = 10_000 / 2188.8

function quantities(lines: { typeId: number; quantity: number }[]): number[] {
  return lines.map(line => line.quantity)
}

describe('supply days', () => {
  it('limits the days to 90 and proposes 4 by default', () => {
    expect([MAX_SUPPLY_DAYS, DEFAULT_SUPPLY_DAYS]).toEqual([90, 4])
  })

  it('accepts days above 0 up to 90', () => {
    expect([1, 4, 7, 90].map(isValidSupplyDays)).toEqual([true, true, true, true])
  })

  it('rejects 0, a negative number, more than 90 days, NaN and an infinite number', () => {
    expect([0, -1, 91, Number.NaN, Number.POSITIVE_INFINITY].map(isValidSupplyDays)).toEqual([false, false, false, false, false])
  })
})

describe('planLaunchpadRefill capacity', () => {
  it('limits 4 feeds at 2 880 a day over 7 days to the days the launchpad can hold and rounds each quantity up', () => {
    const refill = planLaunchpadRefill(referenceLaunchpad(), 7)

    expect(refill.status).toBe('known')
    expect(refill.capacityDays).toBeCloseTo(REFERENCE_CAPACITY_DAYS, 6)
    expect(refill.capacityDays).toBeCloseTo(4.5687, 4)
    expect(refill.balancedDays).toBeCloseTo(REFERENCE_CAPACITY_DAYS, 6)
    expect(refill.limited).toBe(true)
    expect(quantities(refill.lines)).toEqual([13_158, 13_158, 13_158, 13_158])
    expect(refill.bringM3).toBeCloseTo(10_000.08, 6)
  })

  it('keeps the autonomy after the refill at the days the launchpad can hold when it is limited', () => {
    const refill = planLaunchpadRefill(referenceLaunchpad(), 7)

    expect(refill.autonomyDays).toBeCloseTo(13_158 / 2880, 6)
  })

  it('limits 2 feeds at 9 600 a day over 7 days to 2.74 days', () => {
    const launchpad = launchpadOf({ feeds: [feedOf(COOLANT, { dailyRate: 9600 }), feedOf(WATER, { dailyRate: 9600 })] })

    const refill = planLaunchpadRefill(launchpad, 7)

    expect(refill.capacityDays).toBeCloseTo(10_000 / 3648, 6)
    expect(refill.capacityDays).toBeCloseTo(2.74, 2)
    expect(refill.limited).toBe(true)
  })

  it('is not limited when the launchpad holds the requested days', () => {
    const refill = planLaunchpadRefill(referenceLaunchpad(), 4)

    expect(refill.limited).toBe(false)
    expect(refill.balancedDays).toBe(4)
    expect(refill.capacityDays).toBeCloseTo(REFERENCE_CAPACITY_DAYS, 6)
  })
})

describe('planLaunchpadRefill quantities', () => {
  it('brings 11 520 of each of 4 feeds for 4 days with an empty launchpad', () => {
    const refill = planLaunchpadRefill(referenceLaunchpad(), 4)

    expect(quantities(refill.lines)).toEqual([11_520, 11_520, 11_520, 11_520])
    expect(refill.lines.map(line => line.typeId)).toEqual([COOLANT, MECHANICAL_PARTS, CONSUMER_ELECTRONICS, FERTILIZER])
    expect(refill.bringM3).toBeCloseTo(8755.2, 6)
    expect(refill.autonomyDays).toBeCloseTo(4, 6)
  })

  it('deducts a projected stock of 2 000 from the quantity to bring', () => {
    const launchpad = launchpadOf({ feeds: [feedOf(COOLANT, { projectedStock: 2000, stock: 2000 })] })

    const refill = planLaunchpadRefill(launchpad, 4)

    expect(quantities(refill.lines)).toEqual([9520])
    expect(refill.autonomyDays).toBeCloseTo(4, 6)
  })

  it('brings nothing for a feed whose projected stock is above the target', () => {
    const launchpad = launchpadOf({
      feeds: [feedOf(COOLANT, { projectedStock: 20_000, stock: 20_000 }), feedOf(WATER, { dailyRate: 1440, volumePerUnit: 0.38 })],
    })

    const refill = planLaunchpadRefill(launchpad, 4)

    expect(quantities(refill.lines)).toEqual([0, 5760])
    expect(refill.bringM3).toBeCloseTo(5760 * 0.38, 6)
  })

  it('starts from the projected stock of 3 000 and not from the measured stock of 5 000', () => {
    const launchpad = launchpadOf({ feeds: [feedOf(MECHANICAL_PARTS, { stock: 5000, projectedStock: 3000 })] })

    const refill = planLaunchpadRefill(launchpad, 4)

    expect(quantities(refill.lines)).toEqual([8520])
  })

  it('rounds up a fractional projected stock', () => {
    const launchpad = launchpadOf({ feeds: [feedOf(FERTILIZER, { dailyRate: 100, projectedStock: 10.5, stock: 12 })] })

    const refill = planLaunchpadRefill(launchpad, 2)

    expect(quantities(refill.lines)).toEqual([190])
  })

  it('gives the autonomy of the feed that runs out first', () => {
    const launchpad = launchpadOf({
      feeds: [feedOf(COOLANT, { dailyRate: 1000, projectedStock: 5000 }), feedOf(WATER, { dailyRate: 500, projectedStock: 0, volumePerUnit: 0.38 })],
    })

    const refill = planLaunchpadRefill(launchpad, 3)

    expect(quantities(refill.lines)).toEqual([0, 1500])
    expect(refill.autonomyDays).toBeCloseTo(3, 6)
  })
})

describe('planLaunchpadRefill with no consumption', () => {
  it('is not limited, brings nothing and has no autonomy when every rate is 0', () => {
    const launchpad = launchpadOf({
      feeds: [feedOf(COOLANT, { dailyRate: 0, projectedStock: 400 }), feedOf(WATER, { dailyRate: 0, volumePerUnit: 0.38 })],
    })

    const refill = planLaunchpadRefill(launchpad, 4)

    expect(refill.status).toBe('known')
    expect(refill.limited).toBe(false)
    expect(refill.capacityDays).toBe(Number.POSITIVE_INFINITY)
    expect(refill.balancedDays).toBe(4)
    expect(quantities(refill.lines)).toEqual([0, 0])
    expect(refill.bringM3).toBe(0)
    expect(refill.autonomyDays).toBeNull()
  })

  it('ignores the feed whose rate is 0 in the autonomy', () => {
    const launchpad = launchpadOf({
      feeds: [feedOf(COOLANT, { dailyRate: 0, projectedStock: 0 }), feedOf(WATER, { dailyRate: 1440, volumePerUnit: 0.38 })],
    })

    const refill = planLaunchpadRefill(launchpad, 2)

    expect(refill.autonomyDays).toBeCloseTo(2, 6)
  })
})

describe('planLaunchpadRefill unknown', () => {
  it('stays unknown without a quantity when the capacity is unknown', () => {
    const refill = planLaunchpadRefill(unknownCapacityLaunchpad(), 4)

    expect(refill).toEqual({ status: 'unknown', balancedDays: null, capacityDays: null, limited: false, lines: [], bringM3: null, autonomyDays: null })
  })

  it('stays unknown without a quantity when one feed has an unknown volume', () => {
    const launchpad = referenceLaunchpad({ feeds: [feedOf(COOLANT), feedOf(WATER, { volumePerUnit: null })] })

    const refill = planLaunchpadRefill(launchpad, 4)

    expect(refill).toEqual({ status: 'unknown', balancedDays: null, capacityDays: null, limited: false, lines: [], bringM3: null, autonomyDays: null })
  })
})

describe('buildSupplyGrid', () => {
  it('has no row for a launchpad without any fed input', () => {
    const grid = buildSupplyGrid([launchpadOf({ feeds: [] }), referenceLaunchpad()], 4)

    expect(grid.rows.map(row => row.planetName)).toEqual(['Jita IV'])
  })

  it('names each row after its planet and launchpad type', () => {
    const grid = buildSupplyGrid([referenceLaunchpad()], 4)

    expect(grid.rows).toHaveLength(1)
    expect(grid.rows[0].planetName).toBe('Jita IV')
    expect(grid.rows[0].launchpadTypeName).toBe('Launchpad')
    expect(grid.rows[0].refill).toEqual(planLaunchpadRefill(referenceLaunchpad(), 4))
  })

  it('adds the quantities of a shared input over two launchpads: 11 520 and 9 520 make 21 040', () => {
    const grid = buildSupplyGrid([referenceLaunchpad(), secondLaunchpad()], 4)

    expect(grid.totals).toEqual([
      { typeId: COOLANT, quantity: 21_040 },
      { typeId: MECHANICAL_PARTS, quantity: 11_520 },
      { typeId: CONSUMER_ELECTRONICS, quantity: 11_520 },
      { typeId: FERTILIZER, quantity: 11_520 },
      { typeId: WATER, quantity: 5760 },
    ])
    expect(grid.totalM3).toBeCloseTo(8755.2 + 9520 * 0.19 + 5760 * 0.38, 6)
    expect(grid.excludedCount).toBe(0)
  })

  it('sorts the columns by type id with the name of each type', () => {
    const grid = buildSupplyGrid([secondLaunchpad(), referenceLaunchpad()], 4)

    expect(grid.columns).toEqual([
      { typeId: COOLANT, typeName: 'Coolant' },
      { typeId: MECHANICAL_PARTS, typeName: 'Mechanical Parts' },
      { typeId: CONSUMER_ELECTRONICS, typeName: 'Consumer Electronics' },
      { typeId: FERTILIZER, typeName: 'Fertilizer' },
      { typeId: WATER, typeName: 'Water' },
    ])
  })

  it('keeps a null type name for a type the data does not name', () => {
    const grid = buildSupplyGrid([launchpadOf({ feeds: [feedOf(WATER, { typeName: null })] })], 4)

    expect(grid.columns).toEqual([{ typeId: WATER, typeName: null }])
  })

  it('counts an unknown row as excluded and keeps it out of the totals and the m3', () => {
    const grid = buildSupplyGrid([referenceLaunchpad(), unknownCapacityLaunchpad()], 4)

    expect(grid.excludedCount).toBe(1)
    expect(grid.rows.map(row => row.refill.status)).toEqual(['known', 'unknown'])
    expect(grid.totals).toEqual([
      { typeId: COOLANT, quantity: 11_520 },
      { typeId: MECHANICAL_PARTS, quantity: 11_520 },
      { typeId: CONSUMER_ELECTRONICS, quantity: 11_520 },
      { typeId: FERTILIZER, quantity: 11_520 },
    ])
    expect(grid.totalM3).toBeCloseTo(8755.2, 6)
  })

  it('keeps the column of an input only an unknown row consumes', () => {
    const grid = buildSupplyGrid([referenceLaunchpad(), unknownCapacityLaunchpad()], 4)

    expect(grid.columns.map(column => column.typeId)).toEqual([COOLANT, MECHANICAL_PARTS, CONSUMER_ELECTRONICS, FERTILIZER, PLASMOIDS])
  })

  it.each([0, -1, 91, Number.NaN])('computes no row for %s invalid days', days => {
    const grid = buildSupplyGrid([referenceLaunchpad(), secondLaunchpad()], days)

    expect(grid.rows).toEqual([])
    expect(grid.totals).toEqual([])
    expect(grid.totalM3).toBe(0)
    expect(grid.excludedCount).toBe(0)
  })
})

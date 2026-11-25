import {
  DEFAULT_SETTINGS,
  buildPlan,
  buildTree,
  cheapestChoices,
  defaultChoice,
  defaultTargetPerDay,
  effectiveChoice,
  isValidDaysBetweenRounds,
  isValidTarget,
  isValidTaxPercent,
  produceCost,
  starOf,
  unitCost,
  unitPrice,
  type BuildChoice,
  type PlannedProduction,
  type PlannerCatalog,
  type PlannerSettings,
  type TreeNode,
} from '@/utils/planetaryPlanner'
import {
  AQUEOUS_LIQUIDS,
  BROADCAST_NODE,
  COOLANT,
  MECHANICAL_PARTS,
  PLANNER_CATALOG,
  REACTIVE_METALS,
  ROBOTICS,
  WATER,
  withSellPrices,
} from '../planetaryPlannerFixtures'

const NO_CHOICES: Record<number, BuildChoice> = {}

function recipeOf(typeId: number) {
  const recipe = PLANNER_CATALOG.recipes.find(candidate => candidate.outputTypeId === typeId)
  if (!recipe) throw new Error(`No recipe for ${typeId}`)
  return recipe
}

function childOf(node: TreeNode, typeId: number): TreeNode {
  const child = node.children.find(candidate => candidate.typeId === typeId)
  if (!child) throw new Error(`No child ${typeId} under ${node.typeId}`)
  return child
}

function treeOf(plan: ReturnType<typeof buildPlan>, index: number): TreeNode {
  const tree = plan.trees[index]
  if (!tree) throw new Error(`No tree at ${index}`)
  return tree
}

function plan(productions: PlannedProduction[], choices: Record<number, BuildChoice> = NO_CHOICES, catalog: PlannerCatalog = PLANNER_CATALOG, settings: PlannerSettings = DEFAULT_SETTINGS) {
  return buildPlan(productions, catalog, choices, settings)
}

function byType<T extends { typeId: number }>(lines: T[]): T[] {
  return [...lines].sort((first, second) => first.typeId - second.typeId)
}

describe('planetaryPlanner defaults', () => {
  it('sizes a new production as one factory-day and buys a P1 while making a P2 to P4', () => {
    expect([BROADCAST_NODE, ROBOTICS, COOLANT, WATER].map(typeId => defaultTargetPerDay(recipeOf(typeId)))).toEqual([24, 72, 120, 960])
    expect((['P1', 'P2', 'P3', 'P4'] as const).map(defaultChoice)).toEqual(['buy', 'produce', 'produce', 'produce'])
    expect([
      effectiveChoice(WATER, NO_CHOICES, PLANNER_CATALOG),
      effectiveChoice(WATER, { [WATER]: 'produce' }, PLANNER_CATALOG),
      effectiveChoice(COOLANT, { [COOLANT]: 'buy' }, PLANNER_CATALOG),
    ]).toEqual(['buy', 'produce', 'buy'])
  })
})

describe('planetaryPlanner factories and quantities', () => {
  it('needs one factory for a P2 at 120 per day, half of one at 60, and asks each P1 input 960 per day', () => {
    const full = buildTree({ typeId: COOLANT, targetPerDay: 120 }, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)
    const half = buildTree({ typeId: COOLANT, targetPerDay: 60 }, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)

    expect([full.tier, full.perDay, full.factories, half.factories]).toEqual(['P2', 120, 1, 0.5])
    expect(full.children.map(child => [child.typeId, child.perDay])).toEqual([[WATER, 960], [REACTIVE_METALS, 960]])
    expect(plan([{ typeId: COOLANT, targetPerDay: 120 }]).summary.factoriesByTier).toEqual({ P1: 0, P2: 1, P3: 0, P4: 0 })
  })

  it('buys the P1 by default: no P1 factory, no raw extraction, a weekly purchase of each P1 and an inactive raw row below', () => {
    const result = plan([{ typeId: COOLANT, targetPerDay: 120 }])
    const water = childOf(treeOf(result, 0), WATER)

    expect(result.summary.factoriesByTier.P1).toBe(0)
    expect(result.summary.extractionPerHour).toEqual([])
    expect(byType(result.summary.weeklyPurchases)).toEqual([
      { typeId: WATER, quantity: 6720, isk: 403_200 },
      { typeId: REACTIVE_METALS, quantity: 6720, isk: 940_800 },
    ])
    expect(result.summary.weeklyPurchasesIsk).toBe(1_344_000)
    expect(result.summary.purchasesIncomplete).toBe(false)
    expect([water.choice, water.factories, water.active, childOf(water, AQUEOUS_LIQUIDS).active]).toEqual(['buy', null, true, false])
  })

  it('makes a P1 on request: one factory, 144 000 raw per day, 6 000 per hour, and no purchase of it', () => {
    const choices: Record<number, BuildChoice> = { [WATER]: 'produce' }
    const result = plan([{ typeId: COOLANT, targetPerDay: 120 }], choices)
    const water = childOf(treeOf(result, 0), WATER)

    expect(result.summary.factoriesByTier.P1).toBe(1)
    expect(result.summary.extractionPerHour).toEqual([{ typeId: AQUEOUS_LIQUIDS, perHour: 6000 }])
    expect(result.summary.weeklyPurchases.map(purchase => purchase.typeId)).toEqual([REACTIVE_METALS])
    expect([water.factories, childOf(water, AQUEOUS_LIQUIDS).perDay, childOf(water, AQUEOUS_LIQUIDS).active]).toEqual([1, 144_000, true])
    expect(unitCost(WATER, PLANNER_CATALOG, choices, DEFAULT_SETTINGS)).toBeCloseTo(2.4, 9)
  })

  it('adds the factories of a tier across productions before rounding up, and ignores a floating point residue', () => {
    const grouped = plan([{ typeId: COOLANT, targetPerDay: 120 }, { typeId: ROBOTICS, targetPerDay: 18 }], { [MECHANICAL_PARTS]: 'buy' })
    const residue = plan([{ typeId: COOLANT, targetPerDay: 24 }, { typeId: COOLANT, targetPerDay: 92 }, { typeId: COOLANT, targetPerDay: 4 }])
    const robotics = treeOf(grouped, 1)

    expect(grouped.summary.factoriesByTier).toEqual({ P1: 0, P2: 2, P3: 1, P4: 0 })
    expect([treeOf(grouped, 0).factories, robotics.factories, childOf(robotics, COOLANT).perDay, childOf(robotics, COOLANT).factories]).toEqual([1, 0.25, 60, 0.5])
    expect(residue.summary.factoriesByTier.P2).toBe(1)
  })

  it('applies one choice everywhere the product is an input while its own production stays made', () => {
    const result = plan([{ typeId: COOLANT, targetPerDay: 120 }, { typeId: ROBOTICS, targetPerDay: 18 }], { [COOLANT]: 'buy' })
    const target = treeOf(result, 0)
    const boughtCoolant = childOf(treeOf(result, 1), COOLANT)

    expect([target.isTarget, target.factories]).toEqual([true, 1])
    expect([boughtCoolant.choice, boughtCoolant.factories, boughtCoolant.perDay, boughtCoolant.price]).toEqual(['buy', null, 60, 1000])
    expect(boughtCoolant.produceCost).toBeCloseTo(1640, 6)
    expect(boughtCoolant.children.map(child => child.active)).toEqual([false, false])
    expect(childOf(treeOf(result, 1), MECHANICAL_PARTS).factories).toBe(0.5)
    expect(result.summary.factoriesByTier).toEqual({ P1: 0, P2: 2, P3: 1, P4: 0 })
    expect(result.summary.weeklyPurchases.find(purchase => purchase.typeId === COOLANT)).toEqual({ typeId: COOLANT, quantity: 420, isk: 420_000 })
  })

  it('adds the weekly purchases of a type across productions', () => {
    const result = plan([{ typeId: COOLANT, targetPerDay: 120 }, { typeId: ROBOTICS, targetPerDay: 18 }], { [MECHANICAL_PARTS]: 'buy' })

    expect(byType(result.summary.weeklyPurchases)).toEqual([
      { typeId: WATER, quantity: 10_080, isk: 604_800 },
      { typeId: REACTIVE_METALS, quantity: 10_080, isk: 1_411_200 },
      { typeId: MECHANICAL_PARTS, quantity: 420, isk: 924_000 },
    ])
    expect(result.summary.weeklyPurchasesIsk).toBe(2_940_000)
  })
})

describe('planetaryPlanner costs, prices and profit', () => {
  it('costs a made product from the cost of each input under its own choice plus the POCO tax on its price', () => {
    expect(produceCost(COOLANT, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)).toBeCloseTo(1640, 6)
    expect(unitCost(COOLANT, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)).toBeCloseTo(1640, 6)
    expect(unitCost(COOLANT, PLANNER_CATALOG, { [COOLANT]: 'buy' }, DEFAULT_SETTINGS)).toBe(1000)
    expect(unitCost(ROBOTICS, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)).toBeCloseTo(10_560, 6)
    expect(unitCost(ROBOTICS, PLANNER_CATALOG, { [MECHANICAL_PARTS]: 'buy' }, DEFAULT_SETTINGS)).toBeCloseTo(14_000, 6)
  })

  it('reads the price of the chosen source and recomputes the costs from it', () => {
    const buySource: PlannerSettings = { ...DEFAULT_SETTINGS, priceSource: 'jita-buy' }

    expect([unitPrice(COOLANT, PLANNER_CATALOG, 'jita-sell'), unitPrice(COOLANT, PLANNER_CATALOG, 'jita-buy'), unitPrice(999_999, PLANNER_CATALOG, 'jita-sell')]).toEqual([1000, 900, null])
    expect(unitCost(COOLANT, PLANNER_CATALOG, NO_CHOICES, buySource)).toBeCloseTo(1396, 6)
  })

  it('stars buy when making costs more than the price, make when it costs less or the same, and no star on a P1 or the targeted product', () => {
    const cheapInputs = withSellPrices(PLANNER_CATALOG, { [WATER]: 30, [REACTIVE_METALS]: 65 })
    const tie = withSellPrices(PLANNER_CATALOG, { [COOLANT]: 1600 })
    const noTax: PlannerSettings = { ...DEFAULT_SETTINGS, taxPercent: 0 }
    const robotics = buildTree({ typeId: ROBOTICS, targetPerDay: 18 }, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)

    expect(starOf(COOLANT, false, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)).toBe('buy')
    expect(starOf(COOLANT, false, cheapInputs, NO_CHOICES, DEFAULT_SETTINGS)).toBe('produce')
    expect(starOf(COOLANT, false, tie, NO_CHOICES, noTax)).toBe('produce')
    expect(starOf(WATER, false, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)).toBeNull()
    expect(starOf(COOLANT, true, PLANNER_CATALOG, NO_CHOICES, DEFAULT_SETTINGS)).toBeNull()
    expect([robotics.star, childOf(robotics, COOLANT).star, childOf(robotics, MECHANICAL_PARTS).star, childOf(childOf(robotics, COOLANT), WATER).star]).toEqual([null, 'buy', 'produce', null])
  })

  it('turns a missing price into an unknown cost, never a zero, up the whole chain and gives no star', () => {
    const missingInput = withSellPrices(PLANNER_CATALOG, { [REACTIVE_METALS]: null })
    const missingOwnPrice = withSellPrices(PLANNER_CATALOG, { [COOLANT]: null })
    const coolant = buildTree({ typeId: COOLANT, targetPerDay: 120 }, missingInput, NO_CHOICES, DEFAULT_SETTINGS)

    expect(unitPrice(REACTIVE_METALS, missingInput, 'jita-sell')).toBeNull()
    expect(unitCost(COOLANT, missingInput, NO_CHOICES, DEFAULT_SETTINGS)).toBeNull()
    expect(produceCost(COOLANT, missingInput, NO_CHOICES, DEFAULT_SETTINGS)).toBeNull()
    expect(unitCost(ROBOTICS, missingInput, NO_CHOICES, DEFAULT_SETTINGS)).toBeNull()
    expect(starOf(COOLANT, false, missingInput, NO_CHOICES, DEFAULT_SETTINGS)).toBeNull()
    expect(unitCost(COOLANT, missingOwnPrice, NO_CHOICES, DEFAULT_SETTINGS)).toBeNull()
    expect([coolant.produceCost, childOf(coolant, REACTIVE_METALS).price]).toEqual([null, null])
    expect(cheapestChoices([{ typeId: ROBOTICS, targetPerDay: 18 }], missingInput, NO_CHOICES, DEFAULT_SETTINGS)).toEqual({ [MECHANICAL_PARTS]: 'produce' })
  })

  it('leaves the profit unknown and the totals incomplete, keeping the known productions and the priced purchases', () => {
    const missingInput = withSellPrices(PLANNER_CATALOG, { [REACTIVE_METALS]: null })
    const result = plan([{ typeId: COOLANT, targetPerDay: 120 }, { typeId: WATER, targetPerDay: 960 }], NO_CHOICES, missingInput)

    expect(result.summary.unitCost[0]).toBeNull()
    expect(result.summary.unitCost[1]).toBeCloseTo(2.4, 9)
    expect(result.summary.profitPerDay[0]).toBeNull()
    expect(result.summary.profitPerDay[1]).toBeCloseTo(55_296, 6)
    expect(result.summary.totalProfitPerDay).toBeCloseTo(55_296, 6)
    expect(result.summary.profitIncomplete).toBe(true)
    expect(byType(result.summary.weeklyPurchases)).toEqual([
      { typeId: WATER, quantity: 6720, isk: 403_200 },
      { typeId: REACTIVE_METALS, quantity: 6720, isk: null },
    ])
    expect(result.summary.weeklyPurchasesIsk).toBe(403_200)
    expect(result.summary.purchasesIncomplete).toBe(true)
  })

  it('earns 120 times the 360 margin, 43 200 per day, for a P2 at 120 per day priced 1 000 and costing 640', () => {
    const result = plan([{ typeId: COOLANT, targetPerDay: 120 }], NO_CHOICES, withSellPrices(PLANNER_CATALOG, { [WATER]: 25, [REACTIVE_METALS]: 50 }))

    expect(result.summary.unitCost[0]).toBeCloseTo(640, 6)
    expect(result.summary.profitPerDay[0]).toBeCloseTo(43_200, 6)
    expect(result.summary.totalProfitPerDay).toBeCloseTo(43_200, 6)
    expect(result.summary.profitIncomplete).toBe(false)
  })
})

describe('planetaryPlanner cheapest choices and validation', () => {
  it('applies the stars only to the products that have one and keeps the other choices', () => {
    const choices = cheapestChoices([{ typeId: ROBOTICS, targetPerDay: 18 }], PLANNER_CATALOG, { [WATER]: 'produce' }, DEFAULT_SETTINGS)

    expect(choices).toEqual({ [WATER]: 'produce', [COOLANT]: 'buy', [MECHANICAL_PARTS]: 'produce' })
  })

  it('refuses a target at zero or below, a tax outside 0 to 100 and fewer than half a day between rounds', () => {
    expect([0, -5, Number.NaN, Number.POSITIVE_INFINITY, 0.5, 120].map(isValidTarget)).toEqual([false, false, false, false, true, true])
    expect([-1, 100.1, Number.NaN, 0, 4.5, 100].map(isValidTaxPercent)).toEqual([false, false, false, true, true, true])
    expect([0.4, Number.NaN, 0.5, 4].map(isValidDaysBetweenRounds)).toEqual([false, false, true, true])
  })
})

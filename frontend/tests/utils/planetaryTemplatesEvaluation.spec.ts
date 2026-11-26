import { evaluateTemplate } from '@/utils/planetaryTemplates'
import {
  AQUEOUS_LIQUIDS,
  COOLANT,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  RANKING_PRODUCT_B,
  WATER,
  withSellPrices,
} from '../planetaryPlannerFixtures'
import {
  TEMPLATE_B_AND_M,
  TEMPLATE_B_LINE,
  TEMPLATE_CATALOG,
  TEMPLATE_PRODUCT_M,
  TEMPLATE_VANISHED,
  VANISHED_PRODUCT,
  makeTemplate,
} from '../planetaryTemplatesFixtures'

const NO_OVERRIDES = {}

const UNKNOWN_EVALUATION = {
  planets: null,
  planetsIncomplete: true,
  profitPerDay: null,
  profitPerWeek: null,
  profitPerMonth: null,
  profitIncomplete: true,
  weeklyPurchasesIsk: null,
  purchasesIncomplete: true,
}

describe('evaluateTemplate with explicit choices and saved settings', () => {
  it('values B at 60 and M at 18 on Jita buy with a 7.5 % tax at 28,350 + 6,345 = 34,695 a day, 242,865 a week, 1,040,850 a month and 176,715 of purchases a week', () => {
    const evaluation = evaluateTemplate(TEMPLATE_B_AND_M, TEMPLATE_CATALOG, null, NO_OVERRIDES)

    expect(evaluation).toEqual({
      planets: 1,
      planetsIncomplete: false,
      profitPerDay: 34_695,
      profitPerWeek: 242_865,
      profitPerMonth: 1_040_850,
      profitIncomplete: false,
      weeklyPurchasesIsk: 176_715,
      purchasesIncomplete: false,
    })
  })

  it('values the same productions without choices on the default settings at 33,600 + 8,046 = 41,646 a day, 291,522 a week, 1,249,380 a month and 196,350 of purchases a week', () => {
    const plain = makeTemplate('tpl-plain', 'Plain B and M', TEMPLATE_B_AND_M.productions)

    const evaluation = evaluateTemplate(plain, TEMPLATE_CATALOG, null, NO_OVERRIDES)

    expect([evaluation.profitPerDay, evaluation.profitPerWeek, evaluation.profitPerMonth]).toEqual([41_646, 291_522, 1_249_380])
    expect([evaluation.weeklyPurchasesIsk, evaluation.planets]).toEqual([196_350, 1])
  })

  it('makes an explicit choice to produce Water change both the planets and the profit: 2 planets, -14,400 a day and 940,800 of purchases against 1 planet, -72,000 a day and 1,344,000 without the choice', () => {
    const settings = { priceSource: 'jita-sell' as const, taxPercent: 0, daysBetweenRounds: 4 }
    const productions = [{ typeId: COOLANT, targetPerDay: 120 }]
    const withChoice = makeTemplate('tpl-water-made', 'Water made', productions, { [WATER]: 'produce' }, settings)
    const withoutChoice = makeTemplate('tpl-water-bought', 'Water bought', productions, {}, settings)

    const made = evaluateTemplate(withChoice, PLANNER_CATALOG, PLANNER_ACCOUNT, { [AQUEOUS_LIQUIDS]: 6_000 })
    const bought = evaluateTemplate(withoutChoice, PLANNER_CATALOG, PLANNER_ACCOUNT, { [AQUEOUS_LIQUIDS]: 6_000 })

    expect([made.planets, made.profitPerDay, made.profitPerWeek, made.profitPerMonth, made.weeklyPurchasesIsk]).toEqual([2, -14_400, -100_800, -432_000, 940_800])
    expect([bought.planets, bought.profitPerDay, bought.profitPerWeek, bought.profitPerMonth, bought.weeklyPurchasesIsk]).toEqual([1, -72_000, -504_000, -2_160_000, 1_344_000])
  })
})

describe('evaluateTemplate with a product that left the catalog', () => {
  it('has every figure unknown and flagged incomplete, never 0, for a template whose only product vanished', () => {
    expect(evaluateTemplate(TEMPLATE_VANISHED, TEMPLATE_CATALOG, null, NO_OVERRIDES)).toEqual(UNKNOWN_EVALUATION)
  })

  it('has every figure unknown for a template with one vanished product among valid ones, even though B and M alone would be worth 75,246 a day', () => {
    const oneMissing = makeTemplate('tpl-one-missing', 'One missing', [
      { typeId: RANKING_PRODUCT_B, targetPerDay: 120 },
      { typeId: VANISHED_PRODUCT, targetPerDay: 12 },
      { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 },
    ])

    expect(evaluateTemplate(oneMissing, TEMPLATE_CATALOG, PLANNER_ACCOUNT, NO_OVERRIDES)).toEqual(UNKNOWN_EVALUATION)
  })
})

describe('evaluateTemplate profit boundaries', () => {
  it('has no profit when the sell price of B is missing but still knows the 336,000 of purchases a week, without flagging them incomplete', () => {
    const catalog = withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: null })

    expect(evaluateTemplate(TEMPLATE_B_LINE, catalog, null, NO_OVERRIDES)).toEqual({
      planets: 1,
      planetsIncomplete: false,
      profitPerDay: null,
      profitPerWeek: null,
      profitPerMonth: null,
      profitIncomplete: true,
      weeklyPurchasesIsk: 336_000,
      purchasesIncomplete: false,
    })
  })

  it('gives a profit of exactly 0, not null, when B sells at its cost of 400 without tax: 120 x (400 - 400)', () => {
    const catalog = withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: 400 })
    const untaxed = makeTemplate('tpl-untaxed', 'Untaxed B', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, { priceSource: 'jita-sell', taxPercent: 0, daysBetweenRounds: 4 })

    const evaluation = evaluateTemplate(untaxed, catalog, null, NO_OVERRIDES)

    expect([evaluation.profitPerDay, evaluation.profitPerWeek, evaluation.profitPerMonth]).toEqual([0, 0, 0])
    expect(evaluation.profitIncomplete).toBe(false)
  })

  it('gives a negative profit when B sells at 300 for a cost of 400 + 12: 120 x (300 - 412) = -13,440 a day, -94,080 a week, -403,200 a month', () => {
    const catalog = withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: 300 })

    const evaluation = evaluateTemplate(TEMPLATE_B_LINE, catalog, null, NO_OVERRIDES)

    expect([evaluation.profitPerDay, evaluation.profitPerWeek, evaluation.profitPerMonth]).toEqual([-13_440, -94_080, -403_200])
    expect(evaluation.profitIncomplete).toBe(false)
  })
})

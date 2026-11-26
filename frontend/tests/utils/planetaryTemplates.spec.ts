import { DEFAULT_SETTINGS } from '@/utils/planetaryPlanner'
import {
  MAX_TEMPLATE_NAME_LENGTH,
  evaluateTemplate,
  isTemplateResolvable,
  loadTemplate,
  mergeTemplate,
  templateToPayload,
} from '@/utils/planetaryTemplates'
import {
  AQUEOUS_LIQUIDS,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  RANKING_INPUT_A,
  RANKING_INPUT_C,
  RANKING_PRODUCT_B,
  WATER,
  withSellPrices,
  withTypeVolume,
} from '../planetaryPlannerFixtures'
import {
  TEMPLATE_B_AND_M,
  TEMPLATE_B_LINE,
  TEMPLATE_CATALOG,
  TEMPLATE_PRODUCT_M,
  TEMPLATE_VANISHED,
  makeTemplate,
} from '../planetaryTemplatesFixtures'

const NO_OVERRIDES = {}

describe('evaluateTemplate on a P2 made from two bought P1', () => {
  it('values B at 120 a day at 120 x (1,000 - 440) = 67,200 a day, 470,400 a week and 2,016,000 a month, with 336,000 of purchases a week on one planet', () => {
    expect(evaluateTemplate(TEMPLATE_B_LINE, TEMPLATE_CATALOG, null, NO_OVERRIDES)).toEqual({
      planets: 1,
      planetsIncomplete: false,
      profitPerDay: 67_200,
      profitPerWeek: 470_400,
      profitPerMonth: 2_016_000,
      profitIncomplete: false,
      weeklyPurchasesIsk: 336_000,
      purchasesIncomplete: false,
    })
  })

  it('recomputes with today prices: B sold at 1,100 gives 120 x (1,100 - (400 + 44)) = 78,720 a day, nothing being stored', () => {
    const catalog = withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: 1_100 })

    const evaluation = evaluateTemplate(TEMPLATE_B_LINE, catalog, null, NO_OVERRIDES)

    expect([evaluation.profitPerDay, evaluation.profitPerWeek, evaluation.profitPerMonth]).toEqual([78_720, 551_040, 2_361_600])
    expect(evaluation.weeklyPurchasesIsk).toBe(336_000)
  })

  it('has no profit and an incomplete purchase line, never 0, when the price of a bought input is missing', () => {
    const catalog = withSellPrices(TEMPLATE_CATALOG, { [RANKING_INPUT_C]: null })

    expect(evaluateTemplate(TEMPLATE_B_LINE, catalog, null, NO_OVERRIDES)).toEqual({
      planets: 1,
      planetsIncomplete: false,
      profitPerDay: null,
      profitPerWeek: null,
      profitPerMonth: null,
      profitIncomplete: true,
      weeklyPurchasesIsk: null,
      purchasesIncomplete: true,
    })
  })

  it('uses the tax saved in the template: at 10 % the unit costs 400 + 100 = 500, a profit of 60,000 a day, 420,000 a week and 1,800,000 a month', () => {
    const template = makeTemplate('tpl-tax', 'Heavy tax', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, { ...DEFAULT_SETTINGS, taxPercent: 10 })

    const evaluation = evaluateTemplate(template, TEMPLATE_CATALOG, null, NO_OVERRIDES)

    expect([evaluation.profitPerDay, evaluation.profitPerWeek, evaluation.profitPerMonth]).toEqual([60_000, 420_000, 1_800_000])
    expect(evaluation.weeklyPurchasesIsk).toBe(336_000)
  })

  it('uses the price source saved in the template: on Jita buy B costs 360 + 36 and sells at 900, 60,480 a day, with 302,400 of purchases a week', () => {
    const template = makeTemplate('tpl-buy', 'Jita buy', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, { ...DEFAULT_SETTINGS, priceSource: 'jita-buy' })

    const evaluation = evaluateTemplate(template, TEMPLATE_CATALOG, null, NO_OVERRIDES)

    expect([evaluation.profitPerDay, evaluation.profitPerWeek, evaluation.profitPerMonth]).toEqual([60_480, 423_360, 1_814_400])
    expect(evaluation.weeklyPurchasesIsk).toBe(302_400)
  })

  it('adds the productions of a template: B at 120 and M at 18 give 67,200 + 8,046 = 75,246 a day and 364,350 of purchases a week', () => {
    const template = makeTemplate('tpl-mix', 'B and M', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }])

    const evaluation = evaluateTemplate(template, TEMPLATE_CATALOG, null, NO_OVERRIDES)

    expect([evaluation.profitPerDay, evaluation.profitPerWeek, evaluation.profitPerMonth]).toEqual([75_246, 526_722, 2_257_380])
    expect([evaluation.weeklyPurchasesIsk, evaluation.planets]).toEqual([364_350, 1])
  })
})

describe('evaluateTemplate planets', () => {
  const big = (settings = DEFAULT_SETTINGS) => makeTemplate('tpl-big', 'Big B', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 3_240 }], {}, settings)

  it('needs 2 planets for the 27 factories of 3,240 a day when a planet holds 26, and 3 planets when the saved delay of 200 days lets a planet hold 13', () => {
    expect(evaluateTemplate(big(), TEMPLATE_CATALOG, null, NO_OVERRIDES).planets).toBe(2)
    expect(evaluateTemplate(big({ ...DEFAULT_SETTINGS, daysBetweenRounds: 200 }), TEMPLATE_CATALOG, null, NO_OVERRIDES).planets).toBe(3)
  })

  it('has unknown planets, flagged incomplete, when the volume of a type is unknown, while the profit stays known', () => {
    const evaluation = evaluateTemplate(TEMPLATE_B_LINE, withTypeVolume(TEMPLATE_CATALOG, RANKING_INPUT_A, null), null, NO_OVERRIDES)

    expect([evaluation.planets, evaluation.planetsIncomplete]).toEqual([null, true])
    expect(evaluation.profitPerDay).toBe(67_200)
  })

  it('counts the extraction planets from the account yield of 6,000 an hour, from a typed yield of 4,000, and leaves them unknown without any yield', () => {
    const water = makeTemplate('tpl-water', 'Water only', [{ typeId: WATER, targetPerDay: 1_920 }])

    const fromAccount = evaluateTemplate(water, PLANNER_CATALOG, PLANNER_ACCOUNT, NO_OVERRIDES)
    const fromOverride = evaluateTemplate(water, PLANNER_CATALOG, PLANNER_ACCOUNT, { [AQUEOUS_LIQUIDS]: 4_000 })
    const unknown = evaluateTemplate(water, PLANNER_CATALOG, null, NO_OVERRIDES)

    expect([fromAccount.planets, fromAccount.planetsIncomplete]).toEqual([2, false])
    expect([fromOverride.planets, fromOverride.planetsIncomplete]).toEqual([3, false])
    expect([unknown.planets, unknown.planetsIncomplete]).toEqual([null, true])
  })
})

describe('isTemplateResolvable', () => {
  it('accepts a template whose products all have a recipe in the catalog', () => {
    expect(isTemplateResolvable(TEMPLATE_B_LINE, TEMPLATE_CATALOG)).toBe(true)
    expect(isTemplateResolvable(TEMPLATE_B_AND_M, TEMPLATE_CATALOG)).toBe(true)
  })

  it('refuses a template with a product that left the catalog, a single missing one among valid ones, or a type without recipe', () => {
    const oneMissing = makeTemplate('tpl-one-missing', 'One missing', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }, ...TEMPLATE_VANISHED.productions])
    const withoutRecipe = makeTemplate('tpl-no-recipe', 'No recipe', [{ typeId: RANKING_INPUT_A, targetPerDay: 40 }])

    expect(isTemplateResolvable(TEMPLATE_VANISHED, TEMPLATE_CATALOG)).toBe(false)
    expect(isTemplateResolvable(oneMissing, TEMPLATE_CATALOG)).toBe(false)
    expect(isTemplateResolvable(withoutRecipe, TEMPLATE_CATALOG)).toBe(false)
  })
})

describe('loadTemplate', () => {
  it('gives the productions, the explicit choices and the settings of the template as copies that leave the template untouched', () => {
    const loaded = loadTemplate(TEMPLATE_B_AND_M)

    expect(loaded).toEqual({
      productions: [{ typeId: RANKING_PRODUCT_B, targetPerDay: 60 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }],
      choices: { [RANKING_PRODUCT_B]: 'produce', [TEMPLATE_PRODUCT_M]: 'buy' },
      settings: { priceSource: 'jita-buy', taxPercent: 7.5, daysBetweenRounds: 6 },
    })

    loaded.productions[0].targetPerDay = 999
    loaded.choices[RANKING_PRODUCT_B] = 'buy'
    loaded.settings.taxPercent = 50

    expect(TEMPLATE_B_AND_M.productions[0].targetPerDay).toBe(60)
    expect(TEMPLATE_B_AND_M.choices[RANKING_PRODUCT_B]).toBe('produce')
    expect(TEMPLATE_B_AND_M.settings.taxPercent).toBe(7.5)
  })
})

describe('mergeTemplate', () => {
  it('puts the productions of the plan first, then those of the template, a product already planned staying a distinct production: B 120, B 60, M 18', () => {
    const merged = mergeTemplate([{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, TEMPLATE_B_AND_M)

    expect(merged.productions).toEqual([
      { typeId: RANKING_PRODUCT_B, targetPerDay: 120 },
      { typeId: RANKING_PRODUCT_B, targetPerDay: 60 },
      { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 },
    ])
  })

  it('applies a choice of the template only to a product without explicit choice in the plan: B stays bought, M becomes bought', () => {
    const currentProductions = [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }]
    const currentChoices = { [RANKING_PRODUCT_B]: 'buy' as const }

    const merged = mergeTemplate(currentProductions, currentChoices, TEMPLATE_B_AND_M)

    expect(merged.choices).toEqual({ [RANKING_PRODUCT_B]: 'buy', [TEMPLATE_PRODUCT_M]: 'buy' })
    expect(currentProductions).toEqual([{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }])
    expect(currentChoices).toEqual({ [RANKING_PRODUCT_B]: 'buy' })
    expect(TEMPLATE_B_AND_M.choices).toEqual({ [RANKING_PRODUCT_B]: 'produce', [TEMPLATE_PRODUCT_M]: 'buy' })
  })
})

describe('templateToPayload', () => {
  it('carries the name, the productions, the explicit choices and the settings of the plan, and the name limit is 100 characters', () => {
    const settings = { priceSource: 'jita-buy' as const, taxPercent: 7.5, daysBetweenRounds: 6 }

    expect(templateToPayload([{ typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }], { [RANKING_INPUT_C]: 'buy' }, settings, 'M line')).toEqual({
      name: 'M line',
      productions: [{ typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }],
      choices: { [RANKING_INPUT_C]: 'buy' },
      settings: { priceSource: 'jita-buy', taxPercent: 7.5, daysBetweenRounds: 6 },
    })
    expect(MAX_TEMPLATE_NAME_LENGTH).toBe(100)
  })
})

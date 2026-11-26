import type { BuildChoice, PlannedProduction, PlannerCatalog, PlannerSettings } from '@/utils/planetaryPlanner'
import type { PlanTemplate } from '@/utils/planetaryTemplates'
import { RANKING_CATALOG, RANKING_INPUT_A, RANKING_INPUT_C, RANKING_PRODUCT_B } from './planetaryPlannerFixtures'

export const TEMPLATE_PRODUCT_M = 92_031
export const VANISHED_PRODUCT = 99_999

export const DEFAULT_TEMPLATE_SETTINGS: PlannerSettings = { priceSource: 'jita-sell', taxPercent: 4, daysBetweenRounds: 4 }

export const TEMPLATE_CATALOG: PlannerCatalog = {
  ...RANKING_CATALOG,
  recipes: [
    ...RANKING_CATALOG.recipes,
    { outputTypeId: TEMPLATE_PRODUCT_M, outputQuantity: 4, cycleSeconds: 7_200, inputs: [{ typeId: RANKING_INPUT_A, quantity: 30 }, { typeId: RANKING_INPUT_C, quantity: 10 }] },
  ],
  types: [...RANKING_CATALOG.types, { typeId: TEMPLATE_PRODUCT_M, name: 'Ranking Product M', tier: 'P2', volume: 0.05 }],
  prices: [...RANKING_CATALOG.prices, { typeId: TEMPLATE_PRODUCT_M, sell: 700, buy: 600 }],
}

export function makeTemplate(
  id: string,
  name: string,
  productions: PlannedProduction[],
  choices: Record<number, BuildChoice> = {},
  settings: PlannerSettings = DEFAULT_TEMPLATE_SETTINGS,
  createdAt = '2026-11-20T12:00:00+00:00',
  followed = false,
): PlanTemplate {
  return { id, name, productions, choices, settings, followed, createdAt }
}

export function asFollowed(template: PlanTemplate, followed = true): PlanTemplate {
  return { ...template, followed }
}

export const TEMPLATE_B_LINE = makeTemplate('tpl-b-line', 'Barren B line', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }])

export const TEMPLATE_B_AND_M = makeTemplate(
  'tpl-b-and-m',
  'B and M mix',
  [{ typeId: RANKING_PRODUCT_B, targetPerDay: 60 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }],
  { [RANKING_PRODUCT_B]: 'produce', [TEMPLATE_PRODUCT_M]: 'buy' },
  { priceSource: 'jita-buy', taxPercent: 7.5, daysBetweenRounds: 6 },
  '2026-11-22T18:30:00+00:00',
)

export const TEMPLATE_VANISHED = makeTemplate('tpl-vanished', 'Vanished recipe', [{ typeId: VANISHED_PRODUCT, targetPerDay: 12 }], {}, DEFAULT_TEMPLATE_SETTINGS, '2026-11-18T08:15:00+00:00')

import { DAYS_PER_MONTH, DAYS_PER_WEEK } from '@/utils/planetaryRanking'
import { buildPlan, indexCatalog, type BuildChoice, type PlannedProduction, type PlannerCatalog, type PlannerSettings } from '@/utils/planetaryPlanner'
import { buildPlanetPlan, type PlanetMode, type PlannerAccount } from '@/utils/planetaryPlanets'

export interface PlanTemplate {
  id: string
  name: string
  productions: PlannedProduction[]
  choices: Record<number, BuildChoice>
  settings: PlannerSettings
  followed: boolean
  createdAt: string
}

export interface PlanTemplatePayload {
  name: string
  productions: PlannedProduction[]
  choices: Record<number, BuildChoice>
  settings: PlannerSettings
}

export interface TemplateEvaluation {
  planets: number | null
  planetsIncomplete: boolean
  profitPerDay: number | null
  profitPerWeek: number | null
  profitPerMonth: number | null
  profitIncomplete: boolean
  weeklyPurchasesIsk: number | null
  purchasesIncomplete: boolean
}

export interface LoadedTemplate {
  productions: PlannedProduction[]
  choices: Record<number, BuildChoice>
  settings: PlannerSettings
}

export interface MergedTemplate {
  productions: PlannedProduction[]
  choices: Record<number, BuildChoice>
}

export { DAYS_PER_MONTH, DAYS_PER_WEEK } from '@/utils/planetaryRanking'
export const MAX_TEMPLATE_NAME_LENGTH = 100

const UNKNOWN_EVALUATION: TemplateEvaluation = {
  planets: null,
  planetsIncomplete: true,
  profitPerDay: null,
  profitPerWeek: null,
  profitPerMonth: null,
  profitIncomplete: true,
  weeklyPurchasesIsk: null,
  purchasesIncomplete: true,
}

export function isTemplateResolvable(template: PlanTemplate, catalog: PlannerCatalog): boolean {
  const { recipeByOutput } = indexCatalog(catalog)
  return template.productions.every(production => recipeByOutput.has(production.typeId))
}

export function evaluateTemplate(
  template: PlanTemplate,
  catalog: PlannerCatalog,
  account: PlannerAccount | null,
  overrides: Record<number, number>,
  planetMode?: PlanetMode,
): TemplateEvaluation {
  if (!isTemplateResolvable(template, catalog)) return { ...UNKNOWN_EVALUATION }

  const { summary } = buildPlan(template.productions, catalog, template.choices, template.settings)
  const planetPlan = buildPlanetPlan(template.productions, catalog, template.choices, template.settings, account, overrides, planetMode)
  const profitPerDay = summary.profitIncomplete ? null : summary.totalProfitPerDay

  return {
    planets: planetPlan.incomplete ? null : planetPlan.totalPlanets,
    planetsIncomplete: planetPlan.incomplete,
    profitPerDay,
    profitPerWeek: profitPerDay === null ? null : profitPerDay * DAYS_PER_WEEK,
    profitPerMonth: profitPerDay === null ? null : profitPerDay * DAYS_PER_MONTH,
    profitIncomplete: summary.profitIncomplete,
    weeklyPurchasesIsk: summary.purchasesIncomplete ? null : summary.weeklyPurchasesIsk,
    purchasesIncomplete: summary.purchasesIncomplete,
  }
}

export function loadTemplate(template: PlanTemplate): LoadedTemplate {
  return {
    productions: template.productions.map(production => ({ ...production })),
    choices: { ...template.choices },
    settings: { ...template.settings },
  }
}

export function mergeTemplate(currentProductions: PlannedProduction[], currentChoices: Record<number, BuildChoice>, template: PlanTemplate): MergedTemplate {
  const choices = { ...currentChoices }
  for (const [typeId, choice] of Object.entries(template.choices)) {
    if (!(typeId in choices)) choices[Number(typeId)] = choice
  }

  return {
    productions: [...currentProductions, ...template.productions].map(production => ({ ...production })),
    choices,
  }
}

export function templateToPayload(productions: PlannedProduction[], choices: Record<number, BuildChoice>, settings: PlannerSettings, name: string): PlanTemplatePayload {
  return {
    name,
    productions: productions.map(production => ({ ...production })),
    choices: { ...choices },
    settings: { ...settings },
  }
}

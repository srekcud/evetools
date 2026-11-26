import type { BuildChoice, PlannedProduction, PlannerCatalog, PlannerSettings } from '@/utils/planetaryPlanner'
import type { PlannerAccount } from '@/utils/planetaryPlanets'

export interface PlanTemplate {
  id: string
  name: string
  productions: PlannedProduction[]
  choices: Record<number, BuildChoice>
  settings: PlannerSettings
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

export const DAYS_PER_WEEK = 7
export const DAYS_PER_MONTH = 30
export const MAX_TEMPLATE_NAME_LENGTH = 100

export function evaluateTemplate(
  template: PlanTemplate,
  catalog: PlannerCatalog,
  account: PlannerAccount | null,
  overrides: Record<number, number>,
): TemplateEvaluation {
  void template
  void catalog
  void account
  void overrides
  return {
    planets: null,
    planetsIncomplete: false,
    profitPerDay: null,
    profitPerWeek: null,
    profitPerMonth: null,
    profitIncomplete: false,
    weeklyPurchasesIsk: null,
    purchasesIncomplete: false,
  }
}

export function isTemplateResolvable(template: PlanTemplate, catalog: PlannerCatalog): boolean {
  void template
  void catalog
  return true
}

export function loadTemplate(template: PlanTemplate): LoadedTemplate {
  void template
  return { productions: [], choices: {}, settings: { priceSource: 'jita-sell', taxPercent: 4, daysBetweenRounds: 4 } }
}

export function mergeTemplate(currentProductions: PlannedProduction[], currentChoices: Record<number, BuildChoice>, template: PlanTemplate): MergedTemplate {
  void template
  return { productions: currentProductions, choices: currentChoices }
}

export function templateToPayload(productions: PlannedProduction[], choices: Record<number, BuildChoice>, settings: PlannerSettings, name: string): PlanTemplatePayload {
  return { name, productions, choices, settings }
}

export type PlanetaryTier = 'P0' | 'P1' | 'P2' | 'P3' | 'P4'
export type BuildChoice = 'produce' | 'buy'
export type PriceSource = 'jita-sell' | 'jita-buy'

export interface PlannerRecipeInput {
  typeId: number
  quantity: number
}

export interface PlannerRecipe {
  outputTypeId: number
  outputQuantity: number
  cycleSeconds: number
  inputs: PlannerRecipeInput[]
}

export interface PlannerType {
  typeId: number
  name: string
  tier: PlanetaryTier
  volume: number | null
}

export interface PlannerPrice {
  typeId: number
  sell: number | null
  buy: number | null
}

export interface PlannerCatalog {
  recipes: PlannerRecipe[]
  types: PlannerType[]
  prices: PlannerPrice[]
}

export interface PlannerSettings {
  priceSource: PriceSource
  taxPercent: number
  daysBetweenRounds: number
}

export interface PlannedProduction {
  typeId: number
  targetPerDay: number
}

export interface TreeNode {
  typeId: number
  tier: PlanetaryTier
  perDay: number
  factories: number | null
  produceCost: number | null
  price: number | null
  choice: BuildChoice
  isTarget: boolean
  active: boolean
  star: BuildChoice | null
  children: TreeNode[]
}

export interface WeeklyPurchase {
  typeId: number
  quantity: number
  isk: number | null
}

export interface ExtractionRate {
  typeId: number
  perHour: number
}

export interface PlanSummary {
  unitCost: (number | null)[]
  profitPerDay: (number | null)[]
  totalProfitPerDay: number
  profitIncomplete: boolean
  weeklyPurchases: WeeklyPurchase[]
  weeklyPurchasesIsk: number
  purchasesIncomplete: boolean
  factoriesByTier: Record<'P1' | 'P2' | 'P3' | 'P4', number>
  extractionPerHour: ExtractionRate[]
}

export interface PlannerPlan {
  trees: TreeNode[]
  summary: PlanSummary
}

export const DEFAULT_SETTINGS: PlannerSettings = { priceSource: 'jita-sell', taxPercent: 4, daysBetweenRounds: 4 }
export const SECONDS_PER_DAY = 86400
export const FACTORY_TOLERANCE = 1e-9

export function defaultChoice(tier: PlanetaryTier): BuildChoice {
  void tier
  return 'produce'
}

export function defaultTargetPerDay(recipe: PlannerRecipe): number {
  return recipe.outputQuantity
}

export function effectiveChoice(typeId: number, choices: Record<number, BuildChoice>, catalog: PlannerCatalog): BuildChoice {
  return choices[typeId] ?? defaultChoice(catalog.types.find(type => type.typeId === typeId)?.tier ?? 'P0')
}

export function unitPrice(typeId: number, catalog: PlannerCatalog, source: PriceSource): number | null {
  void typeId
  void catalog
  void source
  return null
}

export function unitCost(typeId: number, catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): number | null {
  void typeId
  void catalog
  void choices
  void settings
  return null
}

export function produceCost(typeId: number, catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): number | null {
  void typeId
  void catalog
  void choices
  void settings
  return null
}

export function starOf(typeId: number, isTarget: boolean, catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): BuildChoice | null {
  void typeId
  void isTarget
  void catalog
  void choices
  void settings
  return null
}

export function cheapestChoices(productions: PlannedProduction[], catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): Record<number, BuildChoice> {
  void productions
  void catalog
  void settings
  return choices
}

export function buildTree(production: PlannedProduction, catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): TreeNode {
  void catalog
  void choices
  void settings
  return {
    typeId: production.typeId,
    tier: 'P0',
    perDay: 0,
    factories: null,
    produceCost: null,
    price: null,
    choice: 'produce',
    isTarget: true,
    active: true,
    star: null,
    children: [],
  }
}

export function buildPlan(productions: PlannedProduction[], catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): PlannerPlan {
  return {
    trees: productions.map(production => buildTree(production, catalog, choices, settings)),
    summary: {
      unitCost: [],
      profitPerDay: [],
      totalProfitPerDay: 0,
      profitIncomplete: false,
      weeklyPurchases: [],
      weeklyPurchasesIsk: 0,
      purchasesIncomplete: false,
      factoriesByTier: { P1: 0, P2: 0, P3: 0, P4: 0 },
      extractionPerHour: [],
    },
  }
}

export function isValidTarget(value: number): boolean {
  void value
  return true
}

export function isValidTaxPercent(value: number): boolean {
  void value
  return true
}

export function isValidDaysBetweenRounds(value: number): boolean {
  void value
  return true
}

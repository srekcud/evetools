import type { PlannerExtractionPlanetTypes, PlannerPlanetType } from '@/utils/planetaryPlanets'

export type PlanetaryTier = 'P0' | 'P1' | 'P2' | 'P3' | 'P4'
export type BuildChoice = 'produce' | 'buy'
export type PurchaseSource = 'jita-sell' | 'jita-buy' | 'hub' | 'paid' | 'best'
export type SaleSource = 'jita-sell' | 'jita-buy' | 'hub'

export interface PlannerHub {
  structureId: number
  structureName: string
  hasData: boolean
}

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
  hub: number | null
  paid: number | null
}

export interface PlannerCatalog {
  recipes: PlannerRecipe[]
  types: PlannerType[]
  prices: PlannerPrice[]
  planetTypes: PlannerPlanetType[]
  extractionPlanetTypes: PlannerExtractionPlanetTypes[]
}

export interface PlannerSettings {
  purchaseSource: PurchaseSource
  saleSource: SaleSource
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
  salePrice: number | null
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

export const DEFAULT_SETTINGS: PlannerSettings = { purchaseSource: 'jita-sell', saleSource: 'jita-sell', taxPercent: 4, daysBetweenRounds: 4 }
export const PAID_PRICE_DAYS = 90
export const SECONDS_PER_DAY = 86400
export const FACTORY_TOLERANCE = 1e-9
export const MIN_DAYS_BETWEEN_ROUNDS = 0.5
export const MAX_TAX_PERCENT = 100

const DAYS_PER_WEEK = 7
const HOURS_PER_DAY = 24
const PERCENT = 100
const FACTORY_TIERS = ['P1', 'P2', 'P3', 'P4'] as const

export interface CatalogIndex {
  recipeByOutput: Map<number, PlannerRecipe>
  typeById: Map<number, PlannerType>
  priceById: Map<number, PlannerPrice>
}

interface PlanContext {
  index: CatalogIndex
  catalog: PlannerCatalog
  choices: Record<number, BuildChoice>
  settings: PlannerSettings
}

const indexCache = new WeakMap<PlannerCatalog, CatalogIndex>()

export function indexCatalog(catalog: PlannerCatalog): CatalogIndex {
  const cached = indexCache.get(catalog)
  if (cached) return cached

  const index: CatalogIndex = {
    recipeByOutput: new Map(catalog.recipes.map(recipe => [recipe.outputTypeId, recipe])),
    typeById: new Map(catalog.types.map(type => [type.typeId, type])),
    priceById: new Map(catalog.prices.map(price => [price.typeId, price])),
  }
  indexCache.set(catalog, index)
  return index
}

function typeOf(typeId: number, index: CatalogIndex): PlannerType {
  const type = index.typeById.get(typeId)
  if (!type) throw new Error(`Type ${typeId} is missing from the planner catalog`)
  return type
}

function outputPerDayOfOneFactory(recipe: PlannerRecipe): number {
  return (recipe.outputQuantity * SECONDS_PER_DAY) / recipe.cycleSeconds
}

export function defaultChoice(tier: PlanetaryTier): BuildChoice {
  return tier === 'P1' ? 'buy' : 'produce'
}

export function defaultTargetPerDay(recipe: PlannerRecipe): number {
  return outputPerDayOfOneFactory(recipe)
}

export function effectiveChoice(typeId: number, choices: Record<number, BuildChoice>, catalog: PlannerCatalog): BuildChoice {
  return choices[typeId] ?? defaultChoice(typeOf(typeId, indexCatalog(catalog)).tier)
}

function lowestKnown(prices: (number | null)[]): number | null {
  const known = prices.filter((price): price is number => price !== null)
  return known.length === 0 ? null : Math.min(...known)
}

export function purchasePrice(typeId: number, catalog: PlannerCatalog, source: PurchaseSource): number | null {
  const price = indexCatalog(catalog).priceById.get(typeId)
  if (!price) return null
  if (source === 'best') return lowestKnown([price.paid, price.hub, price.sell])
  if (source === 'jita-sell') return price.sell
  if (source === 'jita-buy') return price.buy
  return source === 'hub' ? price.hub : price.paid
}

export function salePrice(typeId: number, catalog: PlannerCatalog, source: SaleSource): number | null {
  const price = indexCatalog(catalog).priceById.get(typeId)
  if (!price) return null
  if (source === 'jita-sell') return price.sell
  return source === 'jita-buy' ? price.buy : price.hub
}

function contextOf(catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): PlanContext {
  return { index: indexCatalog(catalog), catalog, choices, settings }
}

function priceIn(typeId: number, context: PlanContext): number | null {
  return purchasePrice(typeId, context.catalog, context.settings.purchaseSource)
}

function salePriceIn(typeId: number, context: PlanContext): number | null {
  return salePrice(typeId, context.catalog, context.settings.saleSource)
}

function produceCostIn(typeId: number, context: PlanContext): number | null {
  const recipe = context.index.recipeByOutput.get(typeId)
  if (!recipe) return null

  const ownSalePrice = salePriceIn(typeId, context)
  if (ownSalePrice === null) return null
  const tax = (ownSalePrice * context.settings.taxPercent) / PERCENT

  if (typeOf(typeId, context.index).tier === 'P1') return tax

  let inputsCost = 0
  for (const input of recipe.inputs) {
    const inputCost = unitCostIn(input.typeId, context)
    if (inputCost === null) return null
    inputsCost += input.quantity * inputCost
  }
  return inputsCost / recipe.outputQuantity + tax
}

function unitCostIn(typeId: number, context: PlanContext): number | null {
  return effectiveChoice(typeId, context.choices, context.catalog) === 'buy' ? priceIn(typeId, context) : produceCostIn(typeId, context)
}

function starIn(typeId: number, isTarget: boolean, context: PlanContext): BuildChoice | null {
  if (isTarget) return null
  const tier = typeOf(typeId, context.index).tier
  if (tier === 'P0' || tier === 'P1') return null

  const cost = produceCostIn(typeId, context)
  const price = priceIn(typeId, context)
  if (cost === null || price === null) return null
  return cost <= price ? 'produce' : 'buy'
}

export function unitCost(typeId: number, catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): number | null {
  return unitCostIn(typeId, contextOf(catalog, choices, settings))
}

export function produceCost(typeId: number, catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): number | null {
  return produceCostIn(typeId, contextOf(catalog, choices, settings))
}

export function starOf(typeId: number, isTarget: boolean, catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): BuildChoice | null {
  return starIn(typeId, isTarget, contextOf(catalog, choices, settings))
}

function walk(typeId: number, perDay: number, isTarget: boolean, active: boolean, context: PlanContext): TreeNode {
  const recipe = context.index.recipeByOutput.get(typeId)
  const choice = isTarget ? 'produce' : effectiveChoice(typeId, context.choices, context.catalog)
  const isMade = choice === 'produce'

  return {
    typeId,
    tier: typeOf(typeId, context.index).tier,
    perDay,
    factories: active && isMade && recipe ? perDay / outputPerDayOfOneFactory(recipe) : null,
    produceCost: produceCostIn(typeId, context),
    price: priceIn(typeId, context),
    salePrice: salePriceIn(typeId, context),
    choice,
    isTarget,
    active,
    star: starIn(typeId, isTarget, context),
    children: recipe
      ? recipe.inputs.map(input => walk(input.typeId, (perDay * input.quantity) / recipe.outputQuantity, false, active && isMade, context))
      : [],
  }
}

export function activeNodes(tree: TreeNode): TreeNode[] {
  if (!tree.active) return []
  return [tree, ...tree.children.flatMap(activeNodes)]
}

function treeIn(production: PlannedProduction, context: PlanContext): TreeNode {
  return walk(production.typeId, production.targetPerDay, true, true, context)
}

export function buildTree(production: PlannedProduction, catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): TreeNode {
  return treeIn(production, contextOf(catalog, choices, settings))
}

export function cheapestChoices(productions: PlannedProduction[], catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): Record<number, BuildChoice> {
  const starred: Record<number, BuildChoice> = {}
  for (const production of productions) {
    for (const node of activeNodes(buildTree(production, catalog, choices, settings))) {
      if (node.star !== null) starred[node.typeId] = node.star
    }
  }
  return { ...choices, ...starred }
}

function sumByType(nodes: TreeNode[], amountOf: (node: TreeNode) => number): Map<number, number> {
  const totals = new Map<number, number>()
  for (const node of nodes) {
    const current = totals.get(node.typeId)
    totals.set(node.typeId, current === undefined ? amountOf(node) : current + amountOf(node))
  }
  return totals
}

function sum(amounts: number[]): number {
  return amounts.reduce((total, amount) => total + amount, 0)
}

export function factoriesOfTier(nodes: TreeNode[], tier: PlanetaryTier): number {
  const fractional = sum(nodes.filter(node => node.tier === tier).flatMap(node => (node.factories === null ? [] : [node.factories])))
  return Math.max(0, Math.ceil(fractional - FACTORY_TOLERANCE))
}

export function extractionPerHourOf(nodes: TreeNode[]): ExtractionRate[] {
  return [...sumByType(nodes.filter(node => node.tier === 'P0'), node => node.perDay)].map(([typeId, perDay]) => ({ typeId, perHour: perDay / HOURS_PER_DAY }))
}

function profitOf(production: PlannedProduction | undefined, tree: TreeNode): number | null {
  if (!production || tree.produceCost === null || tree.salePrice === null) return null
  return production.targetPerDay * (tree.salePrice - tree.produceCost)
}

function summarize(productions: PlannedProduction[], trees: TreeNode[], context: PlanContext): PlanSummary {
  const nodes = trees.flatMap(activeNodes)
  const profitPerDay = trees.map((tree, position) => profitOf(productions[position], tree))

  const weeklyPurchases = [...sumByType(nodes.filter(node => node.tier !== 'P0' && node.choice === 'buy'), node => node.perDay * DAYS_PER_WEEK)].map(([typeId, quantity]) => {
    const price = priceIn(typeId, context)
    return { typeId, quantity, isk: price === null ? null : quantity * price }
  })

  return {
    unitCost: trees.map(tree => tree.produceCost),
    profitPerDay,
    totalProfitPerDay: sum(profitPerDay.flatMap(profit => (profit === null ? [] : [profit]))),
    profitIncomplete: profitPerDay.some(profit => profit === null),
    weeklyPurchases,
    weeklyPurchasesIsk: sum(weeklyPurchases.flatMap(purchase => (purchase.isk === null ? [] : [purchase.isk]))),
    purchasesIncomplete: weeklyPurchases.some(purchase => purchase.isk === null),
    factoriesByTier: Object.fromEntries(FACTORY_TIERS.map(tier => [tier, factoriesOfTier(nodes, tier)])) as Record<(typeof FACTORY_TIERS)[number], number>,
    extractionPerHour: extractionPerHourOf(nodes),
  }
}

export function buildPlan(productions: PlannedProduction[], catalog: PlannerCatalog, choices: Record<number, BuildChoice>, settings: PlannerSettings): PlannerPlan {
  const context = contextOf(catalog, choices, settings)
  const trees = productions.map(production => treeIn(production, context))
  return { trees, summary: summarize(productions, trees, context) }
}

export function isValidTarget(value: number): boolean {
  return Number.isFinite(value) && value > 0
}

export function isValidTaxPercent(value: number): boolean {
  return Number.isFinite(value) && value >= 0 && value <= MAX_TAX_PERCENT
}

export function isValidDaysBetweenRounds(value: number): boolean {
  return Number.isFinite(value) && value >= MIN_DAYS_BETWEEN_ROUNDS
}

export function parseDecimalInput(raw: string): number {
  const trimmed = raw.trim().replace(',', '.')
  return trimmed === '' ? Number.NaN : Number(trimmed)
}

import {
  activeNodes,
  buildTree,
  FACTORY_TOLERANCE,
  indexCatalog,
  produceCost,
  purchasePrice,
  type BuildChoice,
  type PlanetaryTier,
  type PlannerCatalog,
  type PlannerSettings,
  type TreeNode,
} from '@/utils/planetaryPlanner'
import {
  factoryDailyVolume,
  factoryLayout,
  PLANETS_PER_CHARACTER,
  type FactoryPlanetRow,
  type FactoryTier,
  type PlanetLayout,
  type PlanetPlan,
} from '@/utils/planetaryPlanets'

export interface PlannerMarketProduct {
  typeId: number
  averageDailyVolume: number
  averagePrice: number
  priceChange: number | null
  lastDate: string
}

export interface PlannerMarket {
  regionId: number
  products: PlannerMarketProduct[]
}

export type RankingBase = 'free' | 'planet' | 'character'
export type RankingTierFilter = 'all' | FactoryTier

export interface RankingOptions {
  baseCount: number | null
  volumeSharePercent: number
  tier: RankingTierFilter
}

export interface ChainTier {
  tier: PlanetaryTier
  produced: number
  bought: number
}

export interface RankingRow {
  typeId: number
  tier: FactoryTier
  chain: ChainTier[]
  choices: Record<number, BuildChoice>
  unitCost: number | null
  price: number | null
  margin: number | null
  fit: FactoryPlanetRow['status']
  doesNotFitDays: number | null
  planetsPerUnit: number | null
  maxOutputPerDay: number | null
  outputPerDay: number | null
  capped: boolean
  planetsUsed: number | null
  baseCount: number | null
  averageDailyVolume: number | null
  priceChange: number | null
  lastDate: string | null
  profitPerDay: number | null
  profitPerWeek: number | null
  profitPerMonth: number | null
  weeklyPurchasesIsk: number | null
  planTarget: number | null
}

export const DEFAULT_VOLUME_SHARE_PERCENT = 5
export const DEFAULT_RANKING_ROWS = 15
export const DAYS_PER_WEEK = 7
export const DAYS_PER_MONTH = 30
export const MAX_VOLUME_SHARE_PERCENT = 100

const PERCENT = 100
const FACTORY_TIERS: readonly FactoryTier[] = ['P2', 'P3', 'P4']
const CHAIN_TIERS: readonly PlanetaryTier[] = ['P1', 'P2', 'P3']

interface TierFit {
  status: FactoryPlanetRow['status']
  doesNotFitDays: number | null
  planetsPerUnit: number | null
}

function isFactoryTier(tier: PlanetaryTier): tier is FactoryTier {
  return (FACTORY_TIERS as readonly PlanetaryTier[]).includes(tier)
}

function decideChain(typeId: number, catalog: PlannerCatalog, settings: PlannerSettings, choices: Record<number, BuildChoice>): void {
  const recipe = indexCatalog(catalog).recipeByOutput.get(typeId)
  if (!recipe) return

  for (const input of recipe.inputs) {
    if (input.typeId in choices) continue
    const inputType = indexCatalog(catalog).typeById.get(input.typeId)
    if (!inputType) throw new Error(`Type ${input.typeId} is missing from the planner catalog`)
    if (inputType.tier === 'P1' || inputType.tier === 'P0') {
      choices[input.typeId] = 'buy'
      continue
    }
    decideChain(input.typeId, catalog, settings, choices)
    const cost = produceCost(input.typeId, catalog, choices, settings)
    const price = purchasePrice(input.typeId, catalog, settings.purchaseSource)
    choices[input.typeId] = cost !== null && price !== null && cost > price ? 'buy' : 'produce'
  }
}

export function bestChainFor(typeId: number, catalog: PlannerCatalog, settings: PlannerSettings): Record<number, BuildChoice> {
  const choices: Record<number, BuildChoice> = {}
  decideChain(typeId, catalog, settings, choices)
  return choices
}

function chainOf(nodes: TreeNode[]): ChainTier[] {
  const choiceByType = new Map<number, { tier: PlanetaryTier, choice: BuildChoice }>()
  for (const node of nodes) {
    if (!node.isTarget) choiceByType.set(node.typeId, { tier: node.tier, choice: node.choice })
  }
  return CHAIN_TIERS.flatMap(tier => {
    const members = [...choiceByType.values()].filter(member => member.tier === tier)
    if (members.length === 0) return []
    return [{
      tier,
      produced: members.filter(member => member.choice === 'produce').length,
      bought: members.filter(member => member.choice === 'buy').length,
    }]
  })
}

function sumOf(amounts: number[]): number {
  return amounts.reduce((total, amount) => total + amount, 0)
}

function fitOf(tree: TreeNode, nodes: TreeNode[], catalog: PlannerCatalog, settings: PlannerSettings): TierFit {
  let planetsPerUnit = 0
  let problem: TierFit | null = null
  for (const tier of FACTORY_TIERS) {
    const factories = sumOf(nodes.filter(node => node.tier === tier && node.factories !== null).map(node => node.factories as number))
    if (!nodes.some(node => node.tier === tier && node.factories !== null)) continue

    const dailyVolume = factoryDailyVolume(tier, [tree], catalog)
    const layout: PlanetLayout | null = dailyVolume === null ? null : factoryLayout(tier, catalog, dailyVolume, settings.daysBetweenRounds).layout
    if (layout === null) {
      problem ??= { status: 'unknownVolume', doesNotFitDays: null, planetsPerUnit: null }
    } else if (layout.factories === 0) {
      problem ??= { status: 'doesNotFit', doesNotFitDays: layout.days, planetsPerUnit: null }
    } else {
      planetsPerUnit += factories / layout.factories
    }
  }
  return problem ?? { status: 'ok', doesNotFitDays: null, planetsPerUnit }
}

function weeklyPurchasesOf(nodes: TreeNode[], outputPerDay: number): number | null {
  const prices = nodes.filter(node => node.choice === 'buy').map(node => (node.price === null ? null : node.perDay * node.price))
  if (prices.some(price => price === null)) return null
  return outputPerDay * DAYS_PER_WEEK * sumOf(prices as number[])
}

function volumeCapOf(volumeSharePercent: number, marketProduct: PlannerMarketProduct | undefined): number | null {
  if (volumeSharePercent === 0) return 0
  return marketProduct === undefined ? null : (volumeSharePercent * marketProduct.averageDailyVolume) / PERCENT
}

export function rankProduct(typeId: number, catalog: PlannerCatalog, settings: PlannerSettings, market: PlannerMarket | null, options: RankingOptions): RankingRow {
  const choices = bestChainFor(typeId, catalog, settings)
  const tree = buildTree({ typeId, targetPerDay: 1 }, catalog, choices, settings)
  if (!isFactoryTier(tree.tier)) throw new Error(`Type ${typeId} is not a P2, P3 or P4 product`)

  const nodes = activeNodes(tree)
  const fit = fitOf(tree, nodes, catalog, settings)
  const marketProduct = market === null ? undefined : market.products.find(product => product.typeId === typeId)
  const unitCost = tree.produceCost
  const price = tree.salePrice

  const maxOutputPerDay = fit.planetsPerUnit === null || options.baseCount === null ? null : options.baseCount / fit.planetsPerUnit
  const volumeCap = volumeCapOf(options.volumeSharePercent, marketProduct)
  const outputPerDay = maxOutputPerDay === null ? null : volumeCap === null ? maxOutputPerDay : Math.min(maxOutputPerDay, volumeCap)
  const capped = outputPerDay !== null && maxOutputPerDay !== null && outputPerDay < maxOutputPerDay
  const planetsUsed = outputPerDay === null || fit.planetsPerUnit === null ? null : Math.max(0, Math.ceil(outputPerDay * fit.planetsPerUnit - FACTORY_TOLERANCE))
  const profitPerDay = outputPerDay === null || marketProduct === undefined || price === null || unitCost === null ? null : outputPerDay * (price - unitCost)

  return {
    typeId,
    tier: tree.tier,
    chain: chainOf(nodes),
    choices,
    unitCost,
    price,
    margin: price === null || unitCost === null || price === 0 ? null : (price - unitCost) / price,
    fit: fit.status,
    doesNotFitDays: fit.doesNotFitDays,
    planetsPerUnit: fit.planetsPerUnit,
    maxOutputPerDay,
    outputPerDay,
    capped,
    planetsUsed,
    baseCount: options.baseCount,
    averageDailyVolume: marketProduct === undefined ? null : marketProduct.averageDailyVolume,
    priceChange: marketProduct === undefined ? null : marketProduct.priceChange,
    lastDate: marketProduct === undefined ? null : marketProduct.lastDate,
    profitPerDay,
    profitPerWeek: profitPerDay === null ? null : profitPerDay * DAYS_PER_WEEK,
    profitPerMonth: profitPerDay === null ? null : profitPerDay * DAYS_PER_MONTH,
    weeklyPurchasesIsk: outputPerDay === null || unitCost === null ? null : weeklyPurchasesOf(nodes, outputPerDay),
    planTarget: marketProduct === undefined ? null : planTargetOf(outputPerDay, options.baseCount),
  }
}

function compareRows(left: RankingRow, right: RankingRow): number {
  if (left.profitPerDay !== null && right.profitPerDay !== null && left.profitPerDay !== right.profitPerDay) return right.profitPerDay - left.profitPerDay
  if (left.profitPerDay === null && right.profitPerDay !== null) return 1
  if (left.profitPerDay !== null && right.profitPerDay === null) return -1
  return left.typeId - right.typeId
}

export function rankProducts(catalog: PlannerCatalog, settings: PlannerSettings, market: PlannerMarket | null, options: RankingOptions): RankingRow[] {
  const index = indexCatalog(catalog)
  return catalog.types
    .filter(type => isFactoryTier(type.tier) && index.recipeByOutput.has(type.typeId) && (options.tier === 'all' || type.tier === options.tier))
    .map(type => rankProduct(type.typeId, catalog, settings, market, options))
    .sort(compareRows)
}

export function rankingBaseCount(base: RankingBase, planetPlan: PlanetPlan | null): number | null {
  if (base === 'planet') return 1
  if (base === 'character') return PLANETS_PER_CHARACTER
  return planetPlan === null ? null : planetPlan.freePlanets
}

export function isValidVolumeShare(value: number): boolean {
  return Number.isFinite(value) && value >= 0 && value <= MAX_VOLUME_SHARE_PERCENT
}

export function planTargetOf(outputPerDay: number | null, baseCount: number | null): number | null {
  if (outputPerDay === null || baseCount === null || baseCount <= 0) return null
  const target = Math.round(outputPerDay)
  return target >= 1 ? target : null
}

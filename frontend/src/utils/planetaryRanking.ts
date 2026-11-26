import type { BuildChoice, PlanetaryTier, PlannerCatalog, PlannerSettings } from '@/utils/planetaryPlanner'
import type { FactoryPlanetRow, FactoryTier, PlanetPlan } from '@/utils/planetaryPlanets'

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

export function bestChainFor(typeId: number, catalog: PlannerCatalog, settings: PlannerSettings): Record<number, BuildChoice> {
  void typeId
  void catalog
  void settings
  return {}
}

export function rankProduct(typeId: number, catalog: PlannerCatalog, settings: PlannerSettings, market: PlannerMarket | null, options: RankingOptions): RankingRow {
  void catalog
  void settings
  void market
  return {
    typeId,
    tier: 'P2',
    chain: [],
    choices: {},
    unitCost: null,
    price: null,
    margin: null,
    fit: 'ok',
    doesNotFitDays: null,
    planetsPerUnit: null,
    maxOutputPerDay: null,
    outputPerDay: null,
    capped: false,
    planetsUsed: null,
    baseCount: options.baseCount,
    averageDailyVolume: null,
    priceChange: null,
    lastDate: null,
    profitPerDay: null,
    profitPerWeek: null,
    profitPerMonth: null,
    weeklyPurchasesIsk: null,
    planTarget: null,
  }
}

export function rankProducts(catalog: PlannerCatalog, settings: PlannerSettings, market: PlannerMarket | null, options: RankingOptions): RankingRow[] {
  void catalog
  void settings
  void market
  void options
  return []
}

export function rankingBaseCount(base: RankingBase, planetPlan: PlanetPlan | null): number | null {
  void base
  void planetPlan
  return null
}

export function isValidVolumeShare(value: number): boolean {
  void value
  return true
}

export function planTargetOf(outputPerDay: number | null, baseCount: number | null): number | null {
  void outputPerDay
  void baseCount
  return null
}

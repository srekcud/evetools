import { buildPlan, salePrice, type PlanetaryTier, type PlannerCatalog } from '@/utils/planetaryPlanner'
import type { PlanTemplate } from '@/utils/planetaryTemplates'

export type FollowTier = 'P1' | 'P2' | 'P3' | 'P4'
export type FactoryGapState = 'missing' | 'free' | 'ok' | 'unknown'

export interface FollowedTargets {
  factoriesByTier: Record<FollowTier, number>
  salesPerDay: number | null
  profitPerDay: number | null
  incomplete: boolean
}

export interface FactoryGap {
  tier: FollowTier
  planned: number
  real: number | null
  gap: number | null
  state: FactoryGapState
}

export interface SalesComparison {
  planned: number | null
  real: number
  gap: number | null
  ratio: number | null
}

export interface RevenueSales {
  realisedIsk: number
  days: number
}

export const FOLLOW_TIERS: FollowTier[] = ['P1', 'P2', 'P3', 'P4']

export function followedTargets(template: PlanTemplate, catalog: PlannerCatalog): FollowedTargets {
  const { summary } = buildPlan(template.productions, catalog, template.choices, template.settings)
  const salesPerDay = salesPerDayOf(template, catalog)
  const profitPerDay = summary.profitIncomplete ? null : summary.totalProfitPerDay

  return {
    factoriesByTier: summary.factoriesByTier,
    salesPerDay,
    profitPerDay,
    incomplete: salesPerDay === null || summary.profitIncomplete,
  }
}

function salesPerDayOf(template: PlanTemplate, catalog: PlannerCatalog): number | null {
  let total = 0
  for (const production of template.productions) {
    const price = salePrice(production.typeId, catalog, template.settings.saleSource)
    if (price === null) return null
    total += production.targetPerDay * price
  }
  return total
}

function stateOf(gap: number): FactoryGapState {
  if (gap < 0) return 'missing'
  return gap > 0 ? 'free' : 'ok'
}

export function compareFactories(planned: Record<FollowTier, number>, real: Record<FollowTier, number> | null): FactoryGap[] {
  return FOLLOW_TIERS.map(tier => {
    if (real === null) return { tier, planned: planned[tier], real: null, gap: null, state: 'unknown' }
    const gap = real[tier] - planned[tier]
    return { tier, planned: planned[tier], real: real[tier], gap, state: stateOf(gap) }
  })
}

export function compareSales(plannedSalesPerDay: number | null, revenue: RevenueSales): SalesComparison {
  const real = revenue.realisedIsk / revenue.days
  if (plannedSalesPerDay === null) return { planned: null, real, gap: null, ratio: null }

  return {
    planned: plannedSalesPerDay,
    real,
    gap: real - plannedSalesPerDay,
    ratio: plannedSalesPerDay === 0 ? null : real / plannedSalesPerDay,
  }
}

export function isFollowTier(tier: PlanetaryTier): tier is FollowTier {
  return tier !== 'P0'
}

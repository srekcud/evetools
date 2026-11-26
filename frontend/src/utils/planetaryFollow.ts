import type { PlanetaryTier, PlannerCatalog } from '@/utils/planetaryPlanner'
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
  void template
  void catalog
  return { factoriesByTier: { P1: 0, P2: 0, P3: 0, P4: 0 }, salesPerDay: null, profitPerDay: null, incomplete: false }
}

export function compareFactories(planned: Record<FollowTier, number>, real: Record<FollowTier, number> | null): FactoryGap[] {
  void planned
  void real
  return []
}

export function compareSales(plannedSalesPerDay: number | null, revenue: RevenueSales): SalesComparison {
  void plannedSalesPerDay
  return { planned: null, real: 0, gap: null, ratio: revenue.days > 0 ? null : null }
}

export function isFollowTier(tier: PlanetaryTier): tier is FollowTier {
  return tier !== 'P0'
}

import type { RouteLocationRaw } from 'vue-router'

export type RevenueTone = 'pve' | 'mining' | 'industry' | 'planetary' | 'neutral' | 'loss'

export type RevenueColumnKind = 'actual' | 'estimate' | 'unknown'

export type RevenueContributorUnit = 'characters' | 'products'

export interface RevenueContributor {
  id: string | number
  name: string
  value: number
}

export interface RevenueDailyAmount {
  date: string
  amount: number
}

export interface RevenueSeries {
  change: number | null
  daily: RevenueDailyAmount[] | null
  contributors: RevenueContributor[] | null
}

export interface RevenueGridColumn extends RevenueSeries {
  id: string
  label: string
  tone: RevenueTone
  kind: RevenueColumnKind
  value: number | null
  headerBadge?: number
  cellBadge?: number
  to?: RouteLocationRaw
}

export interface RevenueGridPeriod {
  to: string
  days: number
}

export interface RevenueTodoItem {
  text: string
  effect: string
  actionLabel?: string
  to?: RouteLocationRaw
}

export interface RevenueStripCell {
  date: string
  amount: number
}

export interface RevenueStrip {
  unit: 'day' | 'week'
  cells: RevenueStripCell[]
  activeCount: number
}

export interface RevenueContributorSplit {
  named: Array<{ id: string | number; name: string; value: number; sharePercent: number }>
  restCount: number
  restSharePercent: number
  all: RevenueContributor[]
}

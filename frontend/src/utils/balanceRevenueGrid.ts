import type { LedgerIndustry, MiningGrid } from '@/stores/ledger'
import type { PlanetaryRevenue } from '@/stores/planetary'
import type { PveGrid } from '@/stores/pve'
import type { RevenueDailyAmount, RevenueGridColumn, RevenueSeries } from '@/types/revenueGrid'
import { buildIndustryRevenueColumns, INDUSTRY_PROFIT_COLUMN_ID } from '@/utils/industryRevenueGrid'
import { buildMiningRevenueColumns } from '@/utils/miningRevenueGrid'
import { buildPveRevenueColumns } from '@/utils/pveRevenueGrid'
import { isCountedInNet, sumNet } from '@/utils/revenueGrid'

export const PLANETARY_MAX_DAYS = 365
export const ACCOUNT_PROJECTS_CONTRIBUTOR_ID = 'account-projects'

export const BALANCE_COLUMN_IDS = ['pve', 'mining', 'industry', 'planetary', 'unknown'] as const
export type BalanceColumnId = (typeof BALANCE_COLUMN_IDS)[number]

export type BalanceTodoKind = 'missingPrice' | 'uncategorised' | 'unknownProfit' | 'piWithoutSale'

export interface BalanceTodo {
  kind: BalanceTodoKind
  count: number
}

export interface BalanceSources {
  pve: PveGrid | null
  mining: MiningGrid | null
  industry: LedgerIndustry | null
  planetary: PlanetaryRevenue | null
}

export interface BalanceLabels {
  columns: Record<BalanceColumnId, string>
  planetaryEstimate: string
  accountProjects: string
  pveColumns: Parameters<typeof buildPveRevenueColumns>[1]
  miningColumns: Parameters<typeof buildMiningRevenueColumns>[1]
  industry: Parameters<typeof buildIndustryRevenueColumns>[1]
}

export interface BalanceRevenue {
  columns: RevenueGridColumn[]
  net: RevenueSeries
  todos: BalanceTodo[]
  period: { to: string; days: number } | null
}

function toDailyAmounts(daily: Record<string, number>): RevenueDailyAmount[] {
  return Object.entries(daily).map(([date, amount]) => ({ date, amount }))
}

export function balanceTodosOf(sources: BalanceSources): BalanceTodo[] {
  const todos: BalanceTodo[] = []
  const missingPriceCount = sources.mining?.missingPriceTypeIds.length ?? 0
  const uncategorisedQuantity = sources.mining?.columns.unknown.quantity ?? 0
  const unknownProfitCount = sources.industry?.totals.unknownProjectCount ?? 0
  const piWithoutSaleCount = sources.planetary?.toFix.length ?? 0

  if (missingPriceCount > 0) todos.push({ kind: 'missingPrice', count: missingPriceCount })
  if (uncategorisedQuantity > 0) todos.push({ kind: 'uncategorised', count: uncategorisedQuantity })
  if (unknownProfitCount > 0) todos.push({ kind: 'unknownProfit', count: unknownProfitCount })
  if (piWithoutSaleCount > 0) todos.push({ kind: 'piWithoutSale', count: piWithoutSaleCount })

  return todos
}

function pveColumn(pve: PveGrid | null, labels: BalanceLabels): RevenueGridColumn {
  return {
    id: 'pve',
    label: labels.columns.pve,
    tone: 'pve',
    kind: 'actual',
    value: pve === null ? null : sumNet(buildPveRevenueColumns(pve, labels.pveColumns)),
    change: pve?.net.change ?? null,
    daily: pve === null ? null : toDailyAmounts(pve.net.daily),
    contributors: null,
    to: { name: 'pve-revenue' },
  }
}

function miningColumn(mining: MiningGrid | null, labels: BalanceLabels, badge: number | undefined): RevenueGridColumn {
  return {
    id: 'mining',
    label: labels.columns.mining,
    tone: 'mining',
    kind: 'actual',
    value: mining === null ? null : sumNet(buildMiningRevenueColumns(mining, labels.miningColumns)),
    change: mining?.net.change ?? null,
    daily: mining === null ? null : toDailyAmounts(mining.net.daily),
    contributors: mining?.net.contributors ?? null,
    to: { name: 'mining-my-mining' },
    ...(badge === undefined ? {} : { cellBadge: badge }),
  }
}

function industryColumn(industry: LedgerIndustry | null, labels: BalanceLabels, badge: number | undefined): RevenueGridColumn {
  const profit = industry === null ? null : buildIndustryRevenueColumns(industry, labels.industry).find(column => column.id === INDUSTRY_PROFIT_COLUMN_ID)

  return {
    id: 'industry',
    label: labels.columns.industry,
    tone: 'industry',
    kind: 'actual',
    value: profit?.value ?? null,
    change: profit?.change ?? null,
    daily: profit?.daily ?? null,
    contributors: profit?.value ? [{ id: ACCOUNT_PROJECTS_CONTRIBUTOR_ID, name: labels.accountProjects, value: profit.value }] : null,
    to: { name: 'industry-revenue' },
    ...(badge === undefined ? {} : { cellBadge: badge }),
  }
}

function planetaryColumn(planetary: PlanetaryRevenue | null, labels: BalanceLabels, badge: number | undefined): RevenueGridColumn {
  const badgeProps = badge === undefined ? {} : { headerBadge: badge }
  const to = { name: 'planetary', query: { tab: 'revenue' } }

  if (planetary === null) {
    return { id: 'planetary', label: labels.columns.planetary, tone: 'planetary', kind: 'actual', value: null, change: null, daily: null, contributors: null, to, ...badgeProps }
  }

  if (planetary.realisedIsk > 0) {
    return {
      id: 'planetary',
      label: labels.columns.planetary,
      tone: 'planetary',
      kind: 'actual',
      value: planetary.realisedIsk,
      change: planetary.realisedChange,
      daily: planetary.realisedDaily,
      contributors: planetary.realisedContributors.map(contributor => ({ id: contributor.characterId, name: contributor.characterName, value: contributor.value })),
      to,
      ...badgeProps,
    }
  }

  return {
    id: 'planetary',
    label: labels.planetaryEstimate,
    tone: 'planetary',
    kind: 'estimate',
    value: planetary.estimate.periodIsk,
    change: null,
    daily: null,
    contributors: planetary.estimate.contributors.map(contributor => ({ id: contributor.characterId, name: contributor.characterName, value: contributor.value })),
    to,
    ...badgeProps,
  }
}

function notValuedColumn(mining: MiningGrid | null, labels: BalanceLabels, badge: number | undefined): RevenueGridColumn {
  return {
    id: 'unknown',
    label: labels.columns.unknown,
    tone: 'neutral',
    kind: 'unknown',
    value: mining?.columns.unknown.value ?? null,
    change: null,
    daily: null,
    contributors: mining?.columns.unknown.contributors ?? null,
    to: { name: 'mining-my-mining' },
    ...(badge === undefined ? {} : { headerBadge: badge }),
  }
}

function netDailyOf(columns: RevenueGridColumn[]): RevenueDailyAmount[] | null {
  const counted = columns.filter(column => isCountedInNet(column) && column.daily !== null)
  if (counted.length === 0) return null
  const amountByDate = new Map<string, number>()
  for (const column of counted) {
    for (const entry of column.daily as RevenueDailyAmount[]) {
      amountByDate.set(entry.date, (amountByDate.get(entry.date) ?? 0) + entry.amount)
    }
  }

  return [...amountByDate.entries()].map(([date, amount]) => ({ date, amount }))
}

export function buildBalanceRevenue(sources: BalanceSources, labels: BalanceLabels): BalanceRevenue {
  const todos = balanceTodosOf(sources)
  const badgeOf = (kind: BalanceTodoKind): number | undefined => {
    const index = todos.findIndex(todo => todo.kind === kind)
    return index === -1 ? undefined : index + 1
  }

  const columns = [
    pveColumn(sources.pve, labels),
    miningColumn(sources.mining, labels, badgeOf('missingPrice')),
    industryColumn(sources.industry, labels, badgeOf('unknownProfit')),
    planetaryColumn(sources.planetary, labels, badgeOf('piWithoutSale')),
    notValuedColumn(sources.mining, labels, badgeOf('uncategorised')),
  ]
  const periodSource = sources.pve ?? sources.mining

  return {
    columns,
    net: { change: null, daily: netDailyOf(columns), contributors: null },
    todos,
    period: periodSource === null ? null : { to: periodSource.period.to, days: periodSource.period.days },
  }
}

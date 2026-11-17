import type { MiningGrid, MiningGridColumn, MiningGridColumnId } from '@/stores/ledger'
import { MINING_GRID_COLUMN_IDS } from '@/stores/ledger'
import type { RevenueDailyAmount, RevenueGridColumn, RevenueSeries } from '@/types/revenueGrid'

export type MiningTodoKind = 'missingPrice' | 'uncategorised'

function toDailyAmounts(daily: Record<string, number>): RevenueDailyAmount[] {
  return Object.entries(daily).map(([date, amount]) => ({ date, amount }))
}

function toColumn(id: MiningGridColumnId, column: MiningGridColumn, label: string, grid: MiningGrid, todoNumbers: Map<MiningTodoKind, number>): RevenueGridColumn {
  const isUncategorised = id === 'unknown'
  const isCountedInPve = id === 'corp_project' && grid.corpProjectCountedInPve
  const missingPriceNumber = column.value === null ? todoNumbers.get('missingPrice') : undefined
  const uncategorisedNumber = isUncategorised && column.quantity > 0 ? todoNumbers.get('uncategorised') : undefined

  return {
    id,
    label,
    tone: isUncategorised ? 'neutral' : 'mining',
    kind: isUncategorised || isCountedInPve ? 'unknown' : 'actual',
    value: column.value,
    change: column.change,
    daily: column.daily === null ? null : toDailyAmounts(column.daily),
    contributors: column.contributors,
    ...(uncategorisedNumber === undefined ? {} : { headerBadge: uncategorisedNumber }),
    ...(missingPriceNumber === undefined ? {} : { cellBadge: missingPriceNumber }),
  }
}

export function miningTodoKinds(grid: MiningGrid): MiningTodoKind[] {
  const kinds: MiningTodoKind[] = []
  if (grid.missingPriceTypeIds.length > 0) kinds.push('missingPrice')
  if (grid.columns.unknown.quantity > 0) kinds.push('uncategorised')
  return kinds
}

export function buildMiningRevenueColumns(grid: MiningGrid, labels: Record<MiningGridColumnId, string>): RevenueGridColumn[] {
  const todoNumbers = new Map(miningTodoKinds(grid).map((kind, index) => [kind, index + 1]))
  return MINING_GRID_COLUMN_IDS.map(id => toColumn(id, grid.columns[id], labels[id], grid, todoNumbers))
}

export function buildMiningNetSeries(grid: MiningGrid): RevenueSeries {
  return {
    change: grid.net.change,
    daily: toDailyAmounts(grid.net.daily),
    contributors: grid.net.contributors,
  }
}

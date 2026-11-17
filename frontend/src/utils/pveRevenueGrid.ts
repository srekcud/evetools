import type { PveGrid, PveGridColumn, PveGridColumnId } from '@/stores/pve'
import { PVE_GRID_COLUMN_IDS } from '@/stores/pve'
import type { RevenueDailyAmount, RevenueGridColumn, RevenueSeries } from '@/types/revenueGrid'

function toDailyAmounts(daily: Record<string, number>, sign: 1 | -1): RevenueDailyAmount[] {
  return Object.entries(daily).map(([date, amount]) => ({ date, amount: amount === 0 ? 0 : sign * amount }))
}

function toColumn(id: PveGridColumnId, column: PveGridColumn, label: string): RevenueGridColumn {
  const isExpense = id === 'expenses'
  const sign = isExpense ? -1 : 1

  return {
    id,
    label,
    tone: isExpense ? 'loss' : 'pve',
    kind: 'actual',
    value: column.value === 0 ? 0 : sign * column.value,
    change: column.change,
    daily: toDailyAmounts(column.daily, sign),
    contributors: column.contributors === null ? null : column.contributors.map(contributor => ({ id: contributor.name, name: contributor.name, value: sign * contributor.value })),
  }
}

export function buildPveRevenueColumns(grid: PveGrid, labels: Record<PveGridColumnId, string>): RevenueGridColumn[] {
  return PVE_GRID_COLUMN_IDS.map(id => toColumn(id, grid.columns[id], labels[id]))
}

export function buildPveNetSeries(grid: PveGrid): RevenueSeries {
  return {
    change: grid.net.change,
    daily: toDailyAmounts(grid.net.daily, 1),
    contributors: null,
  }
}

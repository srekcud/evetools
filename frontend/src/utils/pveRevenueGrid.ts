import type { PveGrid, PveGridColumn, PveGridColumnId } from '@/stores/pve'
import { PVE_GRID_COLUMN_IDS } from '@/stores/pve'
import type { RevenueDailyAmount, RevenueGridColumn, RevenueSeries } from '@/types/revenueGrid'

function toDailyAmounts(daily: Record<string, number>, sign: 1 | -1): RevenueDailyAmount[] {
  return Object.entries(daily).map(([date, amount]) => ({ date, amount: amount === 0 ? 0 : sign * amount }))
}

export type PveTodoKind = 'neverSynced' | 'lootScan' | 'expenseScan'

const SCAN_TODO_KIND_OF_COLUMN: Partial<Record<PveGridColumnId, PveTodoKind>> = {
  lootSales: 'lootScan',
  expenses: 'expenseScan',
}

export function pveTodoKinds(grid: PveGrid | null, neverSynced: boolean): PveTodoKind[] {
  const kinds: PveTodoKind[] = []
  if (neverSynced) kinds.push('neverSynced')
  if (grid?.scans.lootSales.overdue) kinds.push('lootScan')
  if (grid?.scans.expenses.overdue) kinds.push('expenseScan')
  return kinds
}

function toColumn(id: PveGridColumnId, column: PveGridColumn, label: string, todoKinds: PveTodoKind[]): RevenueGridColumn {
  const isExpense = id === 'expenses'
  const sign = isExpense ? -1 : 1
  const todoKind = SCAN_TODO_KIND_OF_COLUMN[id]
  const todoIndex = todoKind === undefined ? -1 : todoKinds.indexOf(todoKind)

  return {
    id,
    label,
    tone: isExpense ? 'loss' : 'pve',
    kind: 'actual',
    value: column.value === 0 ? 0 : sign * column.value,
    change: column.change,
    daily: toDailyAmounts(column.daily, sign),
    contributors: column.contributors === null ? null : column.contributors.map(contributor => ({ id: contributor.name, name: contributor.name, value: sign * contributor.value })),
    ...(todoIndex === -1 ? {} : { headerBadge: todoIndex + 1 }),
  }
}

export function buildPveRevenueColumns(grid: PveGrid, labels: Record<PveGridColumnId, string>, todoKinds: PveTodoKind[] = []): RevenueGridColumn[] {
  return PVE_GRID_COLUMN_IDS.map(id => toColumn(id, grid.columns[id], labels[id], todoKinds))
}

export function buildPveNetSeries(grid: PveGrid): RevenueSeries {
  return {
    change: grid.net.change,
    daily: toDailyAmounts(grid.net.daily, 1),
    contributors: null,
  }
}

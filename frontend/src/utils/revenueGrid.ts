import type {
  RevenueContributor,
  RevenueContributorSplit,
  RevenueDailyAmount,
  RevenueGridColumn,
  RevenueStrip,
  RevenueTone,
} from '@/types/revenueGrid'

export const STRIP_DAILY_MAX_DAYS = 30
export const STRIP_WEEKLY_MAX_BUCKETS = 52
export const NAMED_CONTRIBUTORS = 2
const DAYS_PER_WEEK = 7
const MILLISECONDS_PER_DAY = 86_400_000
const PERCENT = 100
const TINT_FLOOR = 0.08
const TINT_RANGE = 0.5
const STRIP_INTENSITY_FLOOR = 0.25

export const REVENUE_TONE_COLORS: Record<RevenueTone, string> = {
  pve: '#22d3ee',
  mining: '#f59e0b',
  industry: '#34d399',
  planetary: '#a78bfa',
  neutral: '#e2e8f0',
  loss: '#f87171',
}

export const UNKNOWN_HATCH = 'repeating-linear-gradient(45deg,rgba(148,163,184,.28) 0 2px,transparent 2px 6px)'

export function hexWithAlpha(hex: string, alpha: number): string {
  const channels = parseInt(hex.slice(1), 16)
  return `rgba(${channels >> 16},${(channels >> 8) & 255},${channels & 255},${alpha.toFixed(3)})`
}

function parseUtcDate(date: string): number {
  return new Date(`${date}T00:00:00Z`).getTime()
}

function formatUtcDate(timestamp: number): string {
  return new Date(timestamp).toISOString().slice(0, 10)
}

export function buildRegularityStrip(daily: RevenueDailyAmount[], periodEnd: string, days: number): RevenueStrip {
  const weekly = days > STRIP_DAILY_MAX_DAYS
  const bucketDays = weekly ? DAYS_PER_WEEK : 1
  const bucketCount = weekly ? Math.min(Math.ceil(days / DAYS_PER_WEEK), STRIP_WEEKLY_MAX_BUCKETS) : days
  const endTimestamp = parseUtcDate(periodEnd)
  const amounts = new Array<number>(bucketCount).fill(0)

  for (const entry of daily) {
    const daysBeforeEnd = Math.round((endTimestamp - parseUtcDate(entry.date)) / MILLISECONDS_PER_DAY)
    if (daysBeforeEnd < 0) continue
    const bucketsBeforeEnd = Math.floor(daysBeforeEnd / bucketDays)
    if (bucketsBeforeEnd >= bucketCount) continue
    amounts[bucketCount - 1 - bucketsBeforeEnd] += entry.amount
  }

  const cells = amounts.map((amount, index) => ({
    date: formatUtcDate(endTimestamp - (bucketCount - 1 - index) * bucketDays * MILLISECONDS_PER_DAY),
    amount,
  }))

  return {
    unit: weekly ? 'week' : 'day',
    cells,
    activeCount: cells.filter(cell => cell.amount !== 0).length,
  }
}

export function stripCellIntensity(amount: number, cells: RevenueStrip['cells']): number {
  if (amount === 0) return 0
  const largest = Math.max(...cells.map(cell => Math.abs(cell.amount)))
  return STRIP_INTENSITY_FLOOR + (1 - STRIP_INTENSITY_FLOOR) * (Math.abs(amount) / largest)
}

export function splitContributors(contributors: RevenueContributor[]): RevenueContributorSplit {
  const all = contributors
    .filter(contributor => contributor.value !== 0)
    .sort((a, b) => Math.abs(b.value) - Math.abs(a.value))
  const total = all.reduce((sum, contributor) => sum + Math.abs(contributor.value), 0)
  const share = (value: number) => Math.round((Math.abs(value) / total) * PERCENT)
  const rest = all.slice(NAMED_CONTRIBUTORS)
  const restValue = rest.reduce((sum, contributor) => sum + Math.abs(contributor.value), 0)

  return {
    named: all.slice(0, NAMED_CONTRIBUTORS).map(contributor => ({ ...contributor, sharePercent: share(contributor.value) })),
    restCount: rest.length,
    restSharePercent: rest.length > 0 ? share(restValue) : 0,
    all,
  }
}

export function isCountedInNet(column: Pick<RevenueGridColumn, 'kind' | 'value'>): boolean {
  return column.kind !== 'unknown' && column.value !== null
}

export function sumNet(columns: Array<Pick<RevenueGridColumn, 'kind' | 'value'>>): number | null {
  const counted = columns.filter(isCountedInNet)
  if (counted.length === 0) return null
  return counted.reduce((sum, column) => sum + (column.value as number), 0)
}

export function netIncludesEstimate(columns: Array<Pick<RevenueGridColumn, 'kind' | 'value'>>): boolean {
  return columns.some(column => column.kind === 'estimate' && isCountedInNet(column))
}

export function cellTintRatio(value: number, columns: Array<Pick<RevenueGridColumn, 'kind' | 'value'>>): number {
  const counted = columns.filter(isCountedInNet).map(column => Math.abs(column.value as number))
  const largest = counted.length === 0 ? 0 : Math.max(...counted)
  return largest === 0 ? 0 : Math.abs(value) / largest
}

export function cellTintAlpha(ratio: number): number {
  return TINT_FLOOR + TINT_RANGE * ratio
}

export function changeArrow(change: number): '▲' | '▼' | '=' {
  const rounded = Math.round(change)
  if (rounded > 0) return '▲'
  if (rounded < 0) return '▼'
  return '='
}

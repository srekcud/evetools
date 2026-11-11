import type { SchedulerHealthEntry } from '@/stores/admin'

const MS_PER_SECOND = 1000

const SEVERITY_ORDER = ['error', 'stale', 'late', 'unknown', 'running', 'healthy', 'on-demand'] as const

type Severity = (typeof SEVERITY_ORDER)[number]

function severityOf(entry: SchedulerHealthEntry): Severity {
  return entry.status === 'error' ? 'error' : entry.health
}

export function sortBySeverity(entries: SchedulerHealthEntry[]): SchedulerHealthEntry[] {
  return [...entries].sort(
    (left, right) => SEVERITY_ORDER.indexOf(severityOf(left)) - SEVERITY_ORDER.indexOf(severityOf(right)),
  )
}

export function nextRunAt(entry: SchedulerHealthEntry): Date | null {
  if (entry.expectedInterval === null || entry.completedAt === null || entry.status === 'running') return null

  return new Date(new Date(entry.completedAt).getTime() + entry.expectedInterval * MS_PER_SECOND)
}

export function runDurationSeconds(entry: SchedulerHealthEntry): number | null {
  if (entry.startedAt === null || entry.completedAt === null || entry.status === 'running') return null

  const elapsedMs = new Date(entry.completedAt).getTime() - new Date(entry.startedAt).getTime()

  return Math.max(0, Math.round(elapsedMs / MS_PER_SECOND))
}

import type { SchedulerHealthEntry } from '@/stores/admin'
import { nextRunAt, runDurationSeconds, sortBySeverity } from '@/utils/schedulerHealth'

const HOURLY_INTERVAL = 3_600

function entry(type: string, overrides: Partial<SchedulerHealthEntry> = {}): SchedulerHealthEntry {
  return {
    type,
    status: 'ok',
    health: 'healthy',
    startedAt: '2026-11-11T10:00:00+00:00',
    completedAt: '2026-11-11T10:00:42+00:00',
    message: null,
    expectedInterval: HOURLY_INTERVAL,
    onDemand: false,
    triggerable: false,
    ...overrides,
  }
}

function typesOf(entries: SchedulerHealthEntry[]): string[] {
  return entries.map(item => item.type)
}

describe('sortBySeverity', () => {
  it('orders error, stale, late, running, healthy, on-demand', () => {
    const entries = [
      entry('on-demand', { health: 'on-demand', expectedInterval: null, onDemand: true }),
      entry('healthy'),
      entry('running', { status: 'running', health: 'running' }),
      entry('late', { health: 'late' }),
      entry('stale', { health: 'stale' }),
      entry('error', { status: 'error' }),
    ]

    expect(typesOf(sortBySeverity(entries))).toEqual(['error', 'stale', 'late', 'running', 'healthy', 'on-demand'])
  })

  it('puts a failed run first even when its completion time still reads healthy', () => {
    const entries = [entry('stale', { health: 'stale' }), entry('failed-but-recent', { status: 'error', health: 'healthy' })]

    expect(typesOf(sortBySeverity(entries))).toEqual(['failed-but-recent', 'stale'])
  })

  it('puts a failed on-demand sync with the errors', () => {
    const entries = [
      entry('stale', { health: 'stale' }),
      entry('on-demand-failed', { status: 'error', health: 'on-demand', expectedInterval: null, onDemand: true }),
    ]

    expect(typesOf(sortBySeverity(entries))).toEqual(['on-demand-failed', 'stale'])
  })

  it('places a never-run scheduled task between late and running', () => {
    const entries = [
      entry('healthy'),
      entry('running', { status: 'running', health: 'running' }),
      entry('never-run', { status: 'unknown', health: 'unknown', startedAt: null, completedAt: null }),
      entry('late', { health: 'late' }),
    ]

    expect(typesOf(sortBySeverity(entries))).toEqual(['late', 'never-run', 'running', 'healthy'])
  })

  it('keeps the original order inside a severity and leaves the input untouched', () => {
    const entries = [entry('b'), entry('a'), entry('c')]

    expect(typesOf(sortBySeverity(entries))).toEqual(['b', 'a', 'c'])
    expect(typesOf(entries)).toEqual(['b', 'a', 'c'])
  })
})

describe('nextRunAt', () => {
  it('adds the expected interval to the last completion', () => {
    const next = nextRunAt(entry('hourly', { completedAt: '2026-11-11T11:30:00+00:00' }))

    expect(next?.toISOString()).toBe('2026-11-11T12:30:00.000Z')
  })

  it('keeps a date in the past for an overdue task', () => {
    const next = nextRunAt(entry('overdue', { completedAt: '2026-11-11T08:00:00+00:00' }))

    expect(next?.toISOString()).toBe('2026-11-11T09:00:00.000Z')
  })

  it.each([
    ['an on-demand sync', { expectedInterval: null, onDemand: true }],
    ['a task that never ran', { completedAt: null, status: 'unknown' as const }],
    ['a running task', { status: 'running' as const, health: 'running' as const }],
  ])('is unknown for %s', (_label, overrides) => {
    expect(nextRunAt(entry('x', overrides))).toBeNull()
  })

  it('is unknown when the interval is absent from the payload', () => {
    const onDemandEntry = entry('absent', { health: 'on-demand', onDemand: true })
    Reflect.deleteProperty(onDemandEntry, 'expectedInterval')

    expect(nextRunAt(onDemandEntry)).toBeNull()
  })
})

describe('runDurationSeconds', () => {
  it('is the gap between start and completion of the last run', () => {
    expect(runDurationSeconds(entry('quick'))).toBe(42)
  })

  it('counts a failed run too', () => {
    expect(runDurationSeconds(entry('failed', { status: 'error', completedAt: '2026-11-11T10:02:00+00:00' }))).toBe(120)
  })

  it.each([
    ['a running task', { status: 'running' as const, completedAt: '2026-11-11T09:00:00+00:00' }],
    ['a task that never ran', { startedAt: null, completedAt: null, status: 'unknown' as const }],
    ['a missing start', { startedAt: null }],
  ])('is unknown for %s', (_label, overrides) => {
    expect(runDurationSeconds(entry('x', overrides))).toBeNull()
  })
})

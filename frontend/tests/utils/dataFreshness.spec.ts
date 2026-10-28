import { dataFreshnessLevel, type DataFreshnessLevel } from '@/utils/dataFreshness'

const NOW = new Date('2026-10-26T12:00:00Z')
const JITA_PRICES_INTERVAL = 3_600
const COST_INDICES_INTERVAL = 7_200
const ADJUSTED_PRICES_INTERVAL = 86_400

describe('dataFreshnessLevel', () => {
  it.each([
    ['E1', '2026-10-26T11:59:30Z', JITA_PRICES_INTERVAL, 'fresh'],
    ['E2', '2026-10-26T11:18:00Z', JITA_PRICES_INTERVAL, 'fresh'],
    ['E3', '2026-10-26T10:30:00Z', JITA_PRICES_INTERVAL, 'fresh'],
    ['E4', '2026-10-26T10:29:00Z', JITA_PRICES_INTERVAL, 'aging'],
    ['E5', '2026-10-26T09:00:00Z', JITA_PRICES_INTERVAL, 'aging'],
    ['E6', '2026-10-26T08:59:00Z', JITA_PRICES_INTERVAL, 'stale'],
    ['E7', '2026-10-26T09:00:00Z', COST_INDICES_INTERVAL, 'fresh'],
    ['E8', '2026-10-26T07:00:00Z', COST_INDICES_INTERVAL, 'aging'],
    ['E9', '2026-10-26T05:30:00Z', COST_INDICES_INTERVAL, 'stale'],
    ['E10', '2026-10-25T00:00:00Z', ADJUSTED_PRICES_INTERVAL, 'fresh'],
    ['E11', '2026-10-24T00:00:00Z', ADJUSTED_PRICES_INTERVAL, 'aging'],
    ['E12', '2026-10-22T12:00:00Z', ADJUSTED_PRICES_INTERVAL, 'stale'],
  ] as const)('%s: a last successful sync at %s with a %is interval is %s', (_example, lastSuccessAt, expectedInterval, level) => {
    expect(dataFreshnessLevel({ lastSuccessAt, expectedInterval, lastRunFailed: false }, NOW)).toBe(level)
  })

  it('E13: a failed last run makes fresh data aging', () => {
    expect(dataFreshnessLevel({
      lastSuccessAt: '2026-10-26T11:18:00Z',
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed: true,
    }, NOW)).toBe('aging')
  })

  it('E14: a failed last run keeps stale data stale', () => {
    expect(dataFreshnessLevel({
      lastSuccessAt: '2026-10-26T08:00:00Z',
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed: true,
    }, NOW)).toBe('stale')
  })

  it('a failed last run keeps aging data aging', () => {
    expect(dataFreshnessLevel({
      lastSuccessAt: '2026-10-26T07:00:00Z',
      expectedInterval: COST_INDICES_INTERVAL,
      lastRunFailed: true,
    }, NOW)).toBe('aging')
  })

  it.each([
    [false],
    [true],
  ])('E15: a missing last successful sync is unknown, last run failed: %s', (lastRunFailed) => {
    expect(dataFreshnessLevel({
      lastSuccessAt: null,
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed,
    }, NOW)).toBe('unknown')
  })

  it('E16: an unreadable sync state is unknown', () => {
    expect(dataFreshnessLevel(null, NOW)).toBe('unknown')
  })

  it('E17: a last successful sync ahead of the browser clock is fresh', () => {
    expect(dataFreshnessLevel({
      lastSuccessAt: '2026-10-26T12:02:00Z',
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed: false,
    }, NOW)).toBe('fresh')
  })

  it.each([
    ['2026-10-26T12:00:00+02:00', 'aging'],
    ['2026-10-26T13:30:00+02:00', 'fresh'],
  ] as const)('reads the offset of %s regardless of the browser time zone', (lastSuccessAt, level: DataFreshnessLevel) => {
    expect(dataFreshnessLevel({
      lastSuccessAt,
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed: false,
    }, NOW)).toBe(level)
  })
})

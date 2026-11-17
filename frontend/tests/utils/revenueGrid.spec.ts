import {
  buildRegularityStrip,
  cellTintRatio,
  changeArrow,
  netIncludesEstimate,
  splitContributors,
  stripCellIntensity,
  sumNet,
} from '@/utils/revenueGrid'

const PERIOD_END = '2026-11-17'
const WEEKLY_PERIOD_DAYS = 90
const ONE_WEEK_BUCKETS = 13
const MAX_WEEKLY_BUCKETS = 52
const LONG_PERIOD_DAYS = 800

describe('buildRegularityStrip', () => {
  it('draws one cell per day up to 30 days and counts the days with revenue', () => {
    const strip = buildRegularityStrip(
      [
        { date: '2026-11-17', amount: 5 },
        { date: '2026-11-15', amount: 7 },
        { date: '2026-11-10', amount: 1 },
      ],
      PERIOD_END,
      7,
    )

    expect(strip.unit).toBe('day')
    expect(strip.cells).toHaveLength(7)
    expect(strip.activeCount).toBe(2)
    expect(strip.cells[6]).toEqual({ date: '2026-11-17', amount: 5 })
    expect(strip.cells[4]).toEqual({ date: '2026-11-15', amount: 7 })
    expect(strip.cells[0].date).toBe('2026-11-11')
  })

  it('draws one cell per week beyond 30 days and sums the days of a week', () => {
    const strip = buildRegularityStrip(
      [
        { date: '2026-11-17', amount: 5 },
        { date: '2026-11-12', amount: 3 },
        { date: '2026-11-10', amount: 2 },
      ],
      PERIOD_END,
      WEEKLY_PERIOD_DAYS,
    )

    expect(strip.unit).toBe('week')
    expect(strip.cells).toHaveLength(ONE_WEEK_BUCKETS)
    expect(strip.cells[ONE_WEEK_BUCKETS - 1].amount).toBe(8)
    expect(strip.cells[ONE_WEEK_BUCKETS - 2].amount).toBe(2)
    expect(strip.activeCount).toBe(2)
  })

  it('caps the strip at 52 weeks and ignores days outside the window', () => {
    const strip = buildRegularityStrip(
      [
        { date: '2024-01-01', amount: 9 },
        { date: '2026-11-18', amount: 4 },
        { date: '2026-11-17', amount: 1 },
      ],
      PERIOD_END,
      LONG_PERIOD_DAYS,
    )

    expect(strip.cells).toHaveLength(MAX_WEEKLY_BUCKETS)
    expect(strip.activeCount).toBe(1)
  })

  it('keeps a day with a cost as active', () => {
    const strip = buildRegularityStrip([{ date: '2026-11-17', amount: -4 }], PERIOD_END, 7)

    expect(strip.activeCount).toBe(1)
  })
})

describe('stripCellIntensity', () => {
  const cells = [
    { date: '2026-11-16', amount: 10 },
    { date: '2026-11-17', amount: 40 },
    { date: '2026-11-15', amount: 0 },
  ]

  it('is zero for an empty day and full for the largest day', () => {
    expect(stripCellIntensity(0, cells)).toBe(0)
    expect(stripCellIntensity(40, cells)).toBe(1)
  })

  it('stays visible for a small day', () => {
    expect(stripCellIntensity(10, cells)).toBeGreaterThan(0.25)
    expect(stripCellIntensity(10, cells)).toBeLessThan(1)
  })
})

describe('splitContributors', () => {
  it('names the two largest contributors with their share and groups the rest', () => {
    const split = splitContributors([
      { id: 1, name: 'Small', value: 10 },
      { id: 2, name: 'Big', value: 60 },
      { id: 3, name: 'Medium', value: 20 },
      { id: 4, name: 'Tiny', value: 10 },
    ])

    expect(split.named.map(contributor => [contributor.name, contributor.sharePercent])).toEqual([
      ['Big', 60],
      ['Medium', 20],
    ])
    expect(split.restCount).toBe(2)
    expect(split.restSharePercent).toBe(20)
    expect(split.all.map(contributor => contributor.name)).toEqual(['Big', 'Medium', 'Small', 'Tiny'])
  })

  it('drops contributors without revenue and has no rest with two contributors or fewer', () => {
    const split = splitContributors([
      { id: 1, name: 'Idle', value: 0 },
      { id: 2, name: 'Only', value: 5 },
    ])

    expect(split.named).toHaveLength(1)
    expect(split.named[0].sharePercent).toBe(100)
    expect(split.restCount).toBe(0)
    expect(split.restSharePercent).toBe(0)
  })

  it('weighs a cost contributor by its absolute amount', () => {
    const split = splitContributors([
      { id: 1, name: 'Spender', value: -75 },
      { id: 2, name: 'Other', value: -25 },
    ])

    expect(split.named[0]).toMatchObject({ name: 'Spender', sharePercent: 75 })
  })

  it('is empty without any contributor', () => {
    expect(splitContributors([]).all).toEqual([])
  })
})

describe('net of the columns', () => {
  it('leaves unknown columns and unknown values out of the net', () => {
    expect(
      sumNet([
        { kind: 'actual', value: 100 },
        { kind: 'actual', value: -40 },
        { kind: 'unknown', value: 900 },
        { kind: 'actual', value: null },
      ]),
    ).toBe(60)
  })

  it('is unknown when no column can be counted', () => {
    expect(sumNet([{ kind: 'unknown', value: 5 }, { kind: 'actual', value: null }])).toBeNull()
    expect(sumNet([])).toBeNull()
  })

  it('keeps a counted zero as a zero net', () => {
    expect(sumNet([{ kind: 'actual', value: 0 }])).toBe(0)
  })

  it('flags an estimate that is counted in the net', () => {
    expect(netIncludesEstimate([{ kind: 'actual', value: 1 }, { kind: 'estimate', value: 2 }])).toBe(true)
    expect(netIncludesEstimate([{ kind: 'actual', value: 1 }, { kind: 'estimate', value: null }])).toBe(false)
    expect(netIncludesEstimate([{ kind: 'actual', value: 1 }])).toBe(false)
  })
})

describe('cellTintRatio', () => {
  const columns = [
    { kind: 'actual' as const, value: 200 },
    { kind: 'actual' as const, value: -50 },
    { kind: 'unknown' as const, value: 9_000 },
  ]

  it('weighs a cell against the largest counted column, costs by absolute value', () => {
    expect(cellTintRatio(200, columns)).toBe(1)
    expect(cellTintRatio(-50, columns)).toBe(0.25)
  })

  it('ignores an unknown column when looking for the largest', () => {
    expect(cellTintRatio(100, columns)).toBe(0.5)
  })

  it('is zero when nothing is counted', () => {
    expect(cellTintRatio(0, [{ kind: 'unknown', value: 5 }])).toBe(0)
  })
})

describe('changeArrow', () => {
  it.each([
    [12, '▲'],
    [-3, '▼'],
    [0, '='],
    [0.4, '='],
    [-0.4, '='],
  ] as const)('shows %d as %s', (change, arrow) => {
    expect(changeArrow(change)).toBe(arrow)
  })
})

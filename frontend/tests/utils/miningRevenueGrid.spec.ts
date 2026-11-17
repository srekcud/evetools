import type { MiningGrid, MiningGridColumn } from '@/stores/ledger'
import { buildMiningNetSeries, buildMiningRevenueColumns, miningTodoKinds } from '@/utils/miningRevenueGrid'

const LABELS = { sold: 'Sold', corp_project: 'Corp project', unknown: 'Uncategorised' }

function column(overrides: Partial<MiningGridColumn> = {}): MiningGridColumn {
  return { value: 0, previous: 0, change: null, quantity: 0, daily: {}, contributors: [], ...overrides }
}

function grid(overrides: Partial<Omit<MiningGrid, 'columns'>> & { columns?: Partial<MiningGrid['columns']> } = {}): MiningGrid {
  const { columns, ...rest } = overrides
  return {
    period: { from: '2026-11-03', to: '2026-11-10', days: 7 },
    columns: { sold: column(), corp_project: column(), unknown: column(), ...columns },
    net: { value: 0, previous: 0, change: null, daily: {}, contributors: [] },
    missingPriceTypeIds: [],
    corpProjectCountedInPve: false,
    ...rest,
  }
}

describe('buildMiningRevenueColumns', () => {
  it('lists sold, corp project and uncategorised in that order with their labels', () => {
    const columns = buildMiningRevenueColumns(grid(), LABELS)

    expect(columns.map(item => [item.id, item.label])).toEqual([
      ['sold', 'Sold'],
      ['corp_project', 'Corp project'],
      ['unknown', 'Uncategorised'],
    ])
  })

  it('counts sold and corp project in the net and leaves the uncategorised ore out of it', () => {
    const columns = buildMiningRevenueColumns(grid(), LABELS)

    expect(columns.map(item => item.kind)).toEqual(['actual', 'actual', 'unknown'])
    expect(columns.map(item => item.tone)).toEqual(['mining', 'mining', 'neutral'])
  })

  it('leaves the corp project column out of the net while PVE counts it', () => {
    const columns = buildMiningRevenueColumns(grid({ corpProjectCountedInPve: true }), LABELS)

    expect(columns[1].kind).toBe('unknown')
    expect(columns[1].value).toBe(0)
  })

  it('keeps an unpriced column unknown instead of turning it into 0', () => {
    const columns = buildMiningRevenueColumns(grid({ columns: { sold: column({ value: null, daily: null, contributors: null }) }, missingPriceTypeIds: [81143] }), LABELS)

    expect(columns[0].value).toBeNull()
    expect(columns[0].daily).toBeNull()
    expect(columns[0].contributors).toBeNull()
  })

  it('turns the daily amounts into dated entries and keeps the characters as contributors', () => {
    const columns = buildMiningRevenueColumns(
      grid({ columns: { sold: column({ value: 150, daily: { '2026-11-09': 100, '2026-11-10': 50 }, contributors: [{ id: 11, name: 'Main', value: 150 }] }) } }),
      LABELS,
    )

    expect(columns[0].daily).toEqual([{ date: '2026-11-09', amount: 100 }, { date: '2026-11-10', amount: 50 }])
    expect(columns[0].contributors).toEqual([{ id: 11, name: 'Main', value: 150 }])
  })

  it('numbers the badges like the To fix items: the unpriced cell first, then the uncategorised header', () => {
    const columns = buildMiningRevenueColumns(
      grid({
        columns: { sold: column({ value: null, daily: null, contributors: null }), unknown: column({ quantity: 900 }) },
        missingPriceTypeIds: [81143],
      }),
      LABELS,
    )

    expect(columns[0].cellBadge).toBe(1)
    expect(columns[0].headerBadge).toBeUndefined()
    expect(columns[2].headerBadge).toBe(2)
  })

  it('numbers the uncategorised badge 1 when every ore has a price', () => {
    const columns = buildMiningRevenueColumns(grid({ columns: { unknown: column({ quantity: 900 }) } }), LABELS)

    expect(columns[2].headerBadge).toBe(1)
    expect(columns[0].cellBadge).toBeUndefined()
  })

  it('shows no badge when nothing needs fixing', () => {
    const columns = buildMiningRevenueColumns(grid(), LABELS)

    expect(columns.some(item => item.cellBadge !== undefined || item.headerBadge !== undefined)).toBe(false)
  })
})

describe('miningTodoKinds', () => {
  it('asks for prices when an ore has none and for categories when ore is uncategorised', () => {
    expect(miningTodoKinds(grid({ missingPriceTypeIds: [1], columns: { unknown: column({ quantity: 5 }) } }))).toEqual(['missingPrice', 'uncategorised'])
  })

  it('has nothing to fix when every ore is priced and categorised', () => {
    expect(miningTodoKinds(grid())).toEqual([])
  })
})

describe('buildMiningNetSeries', () => {
  it('carries the change, the daily amounts and the characters of the net', () => {
    const net = buildMiningNetSeries(grid({ net: { value: 150, previous: 100, change: 50, daily: { '2026-11-10': 150 }, contributors: [{ id: 11, name: 'Main', value: 150 }] } }))

    expect(net).toEqual({ change: 50, daily: [{ date: '2026-11-10', amount: 150 }], contributors: [{ id: 11, name: 'Main', value: 150 }] })
  })
})

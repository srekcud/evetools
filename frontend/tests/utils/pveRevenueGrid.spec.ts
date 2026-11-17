import type { PveGrid } from '@/stores/pve'
import { buildPveNetSeries, buildPveRevenueColumns } from '@/utils/pveRevenueGrid'

const LABELS = { bounties: 'Bounties', ess: 'ESS', missions: 'Missions', lootSales: 'Loot', escalations: 'Escalations sold', expenses: 'Expenses' }

function emptyColumn() {
  return { value: 0, previous: 0, change: null, daily: {}, contributors: null }
}

const GRID: PveGrid = {
  period: { from: '2026-11-10', to: '2026-11-17', days: 7 },
  columns: {
    bounties: { value: 300, previous: 200, change: 50, daily: { '2026-11-16': 100, '2026-11-17': 200 }, contributors: [{ name: 'Main', value: 250 }, { name: 'Alt', value: 50 }] },
    ess: emptyColumn(),
    missions: emptyColumn(),
    lootSales: { ...emptyColumn(), value: 20, daily: { '2026-11-17': 20 } },
    escalations: emptyColumn(),
    expenses: { value: 60, previous: 30, change: 100, daily: { '2026-11-17': 60 }, contributors: null },
  },
  net: { value: 260, previous: 170, change: 52.9, daily: { '2026-11-16': 100, '2026-11-17': 160 } },
}

describe('buildPveRevenueColumns', () => {
  it('gives one column per PVE source in the order of the grid, with its label', () => {
    expect(buildPveRevenueColumns(GRID, LABELS).map(column => [column.id, column.label])).toEqual([
      ['bounties', 'Bounties'],
      ['ess', 'ESS'],
      ['missions', 'Missions'],
      ['lootSales', 'Loot'],
      ['escalations', 'Escalations sold'],
      ['expenses', 'Expenses'],
    ])
  })

  it('carries the value, the change, the daily amounts and the contributors of a revenue column', () => {
    const [bounties] = buildPveRevenueColumns(GRID, LABELS)

    expect(bounties).toMatchObject({ tone: 'pve', kind: 'actual', value: 300, change: 50 })
    expect(bounties.daily).toEqual([
      { date: '2026-11-16', amount: 100 },
      { date: '2026-11-17', amount: 200 },
    ])
    expect(bounties.contributors).toEqual([
      { id: 'Main', name: 'Main', value: 250 },
      { id: 'Alt', name: 'Alt', value: 50 },
    ])
  })

  it('shows the expenses as a negative red column with negative daily amounts', () => {
    const expenses = buildPveRevenueColumns(GRID, LABELS)[5]

    expect(expenses).toMatchObject({ tone: 'loss', value: -60, change: 100 })
    expect(expenses.daily).toEqual([{ date: '2026-11-17', amount: -60 }])
  })

  it('keeps the contributors unknown where the server has none', () => {
    const [, , , lootSales, , expenses] = buildPveRevenueColumns(GRID, LABELS)

    expect(lootSales.contributors).toBeNull()
    expect(expenses.contributors).toBeNull()
  })

  it('keeps a zero as a plain zero, never a negative zero', () => {
    const noExpenses: PveGrid = { ...GRID, columns: { ...GRID.columns, expenses: emptyColumn() } }

    const expenses = buildPveRevenueColumns(noExpenses, LABELS)[5]

    expect(Object.is(expenses.value, 0)).toBe(true)
  })

  it('keeps an unknown change unknown', () => {
    expect(buildPveRevenueColumns(GRID, LABELS)[1].change).toBeNull()
  })
})

describe('buildPveNetSeries', () => {
  it('gives the net change and daily amounts, and no contributors', () => {
    expect(buildPveNetSeries(GRID)).toEqual({
      change: 52.9,
      daily: [
        { date: '2026-11-16', amount: 100 },
        { date: '2026-11-17', amount: 160 },
      ],
      contributors: null,
    })
  })
})

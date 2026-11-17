import type { LedgerIndustry } from '@/stores/ledger'
import {
  INDUSTRY_PROFIT_COLUMN_ID,
  INDUSTRY_UNKNOWN_PROFIT_COLUMN_ID,
  MULTI_PRODUCT_CONTRIBUTOR_ID,
  buildIndustryNetSeries,
  buildIndustryRevenueColumns,
  hasNoKnownProfit,
} from '@/utils/industryRevenueGrid'

const LABELS = { profit: 'Profit', unknownProfit: 'Unknown profit', multiProduct: 'Multi-product projects' }

function industry(overrides: Partial<LedgerIndustry> = {}): LedgerIndustry {
  return {
    period: { from: '2026-10-18', to: '2026-11-17', days: 30 },
    totals: { profit: 130_000_000, projectCount: 4, unknownProjectCount: 0, capital: 600_000_000, slotHours: 160, iskPerHour: 812_500, change: 12.5 },
    daily: { '2026-11-03': 150_000_000, '2026-10-20': -20_000_000 },
    months: [],
    products: [
      { typeId: 22456, typeName: 'Sabre', projectCount: 3, unknownProjectCount: 0, profit: 150_000_000, capital: 500_000_000, marginPercent: 30 },
      { typeId: 12005, typeName: 'Ishtar', projectCount: 1, unknownProjectCount: 0, profit: -20_000_000, capital: 100_000_000, marginPercent: -20 },
    ],
    ...overrides,
  }
}

describe('hasNoKnownProfit', () => {
  it.each([
    ['no project', 0, 0, false],
    ['only known projects', 3, 0, false],
    ['some unknown projects', 3, 1, false],
    ['only unknown projects', 2, 2, true],
  ])('is %s', (_case, projectCount, unknownProjectCount, expected) => {
    expect(hasNoKnownProfit({ projectCount, unknownProjectCount })).toBe(expected)
  })
})

describe('buildIndustryRevenueColumns', () => {
  it('gives one profit column with the change, the daily profit and the products as contributors', () => {
    const [profit, ...others] = buildIndustryRevenueColumns(industry(), LABELS)

    expect(others).toEqual([])
    expect(profit).toEqual({
      id: INDUSTRY_PROFIT_COLUMN_ID,
      label: 'Profit',
      tone: 'industry',
      kind: 'actual',
      value: 130_000_000,
      change: 12.5,
      daily: [{ date: '2026-11-03', amount: 150_000_000 }, { date: '2026-10-20', amount: -20_000_000 }],
      contributors: [
        { id: 22456, name: 'Sabre', value: 150_000_000 },
        { id: 12005, name: 'Ishtar', value: -20_000_000 },
      ],
    })
  })

  it('names the multi-product line and keeps its profit', () => {
    const [profit] = buildIndustryRevenueColumns(
      industry({ products: [{ typeId: null, typeName: null, projectCount: 2, unknownProjectCount: 0, profit: 40_000_000, capital: 100_000_000, marginPercent: 40 }] }),
      LABELS,
    )

    expect(profit.contributors).toEqual([{ id: MULTI_PRODUCT_CONTRIBUTOR_ID, name: 'Multi-product projects', value: 40_000_000 }])
  })

  it('adds a hatched unknown-profit column with a badge, outside the net, when a project has an unknown profit', () => {
    const columns = buildIndustryRevenueColumns(
      industry({ totals: { ...industry().totals, projectCount: 4, unknownProjectCount: 1 } }),
      LABELS,
    )

    expect(columns.map(column => column.id)).toEqual([INDUSTRY_PROFIT_COLUMN_ID, INDUSTRY_UNKNOWN_PROFIT_COLUMN_ID])
    expect(columns[1]).toMatchObject({ kind: 'unknown', value: null, headerBadge: 1, label: 'Unknown profit' })
    expect(columns[0].value).toBe(130_000_000)
  })

  it('leaves the profit unknown, never 0, when every project has an unknown profit', () => {
    const [profit, unknown] = buildIndustryRevenueColumns(
      industry({
        totals: { profit: 0, projectCount: 2, unknownProjectCount: 2, capital: 0, slotHours: null, iskPerHour: null, change: null },
        daily: {},
        products: [{ typeId: 35683, typeName: 'Hecate', projectCount: 2, unknownProjectCount: 2, profit: 0, capital: 0, marginPercent: null }],
      }),
      LABELS,
    )

    expect(profit).toMatchObject({ value: null, change: null, daily: null, contributors: null })
    expect(unknown.kind).toBe('unknown')
  })

  it('leaves out of the contributors a product whose projects all have an unknown profit', () => {
    const [profit] = buildIndustryRevenueColumns(
      industry({
        products: [
          { typeId: 22456, typeName: 'Sabre', projectCount: 3, unknownProjectCount: 0, profit: 150_000_000, capital: 500_000_000, marginPercent: 30 },
          { typeId: 35683, typeName: 'Hecate', projectCount: 1, unknownProjectCount: 1, profit: 0, capital: 0, marginPercent: null },
        ],
      }),
      LABELS,
    )

    expect(profit.contributors).toEqual([{ id: 22456, name: 'Sabre', value: 150_000_000 }])
  })

  it('keeps an unknown change unknown', () => {
    const [profit] = buildIndustryRevenueColumns(industry({ totals: { ...industry().totals, change: null } }), LABELS)

    expect(profit.change).toBeNull()
  })
})

describe('buildIndustryNetSeries', () => {
  it('carries the profit change and daily profit without contributors', () => {
    expect(buildIndustryNetSeries(industry())).toEqual({
      change: 12.5,
      daily: [{ date: '2026-11-03', amount: 150_000_000 }, { date: '2026-10-20', amount: -20_000_000 }],
      contributors: null,
    })
  })

  it('has no change and no daily profit when every project has an unknown profit', () => {
    const series = buildIndustryNetSeries(industry({
      totals: { profit: 0, projectCount: 1, unknownProjectCount: 1, capital: 0, slotHours: null, iskPerHour: null, change: null },
      daily: {},
    }))

    expect(series).toEqual({ change: null, daily: null, contributors: null })
  })
})

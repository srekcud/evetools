import type { LedgerIndustry, MiningGrid } from '@/stores/ledger'
import type { PlanetaryRevenue } from '@/stores/planetary'
import type { PveGrid } from '@/stores/pve'
import { sumNet } from '@/utils/revenueGrid'
import { buildMiningRevenueColumns } from '@/utils/miningRevenueGrid'
import { buildPveRevenueColumns } from '@/utils/pveRevenueGrid'
import { buildIndustryRevenueColumns } from '@/utils/industryRevenueGrid'
import { ACCOUNT_PROJECTS_CONTRIBUTOR_ID, balanceTodosOf, buildBalanceRevenue, type BalanceLabels, type BalanceSources } from '@/utils/balanceRevenueGrid'

const PVE_LABELS = { bounties: 'Bounties', ess: 'ESS', missions: 'Missions', lootSales: 'Loot', escalations: 'Escalations', expenses: 'Expenses' }
const MINING_LABELS = { sold: 'Sold', corp_project: 'Corp project', unknown: 'Uncategorised' }
const INDUSTRY_LABELS = { profit: 'Profit', unknownProfit: 'Unknown profit', multiProduct: 'Multi' }

const LABELS: BalanceLabels = {
  columns: { pve: 'PVE', mining: 'Mining', industry: 'Industry', planetary: 'Planetary', unknown: 'Not valued' },
  planetaryEstimate: 'Planetary (estimate)',
  accountProjects: 'Account projects',
  pveColumns: PVE_LABELS,
  miningColumns: MINING_LABELS,
  industry: INDUSTRY_LABELS,
}

const PERIOD = { from: '2026-10-18', to: '2026-11-17', days: 30 }

const PVE: PveGrid = {
  period: PERIOD,
  columns: {
    bounties: { value: 500, previous: 400, change: 25, daily: { '2026-11-17': 500 }, contributors: null },
    ess: { value: 0, previous: 0, change: null, daily: {}, contributors: null },
    missions: { value: 0, previous: 0, change: null, daily: {}, contributors: null },
    lootSales: { value: 100, previous: 0, change: null, daily: {}, contributors: null },
    escalations: { value: 0, previous: 0, change: null, daily: {}, contributors: null },
    expenses: { value: 60, previous: 0, change: null, daily: {}, contributors: null },
  },
  net: { value: 540, previous: 400, change: 35, daily: { '2026-11-17': 540, '2026-11-16': 10 } },
}

const MINING: MiningGrid = {
  period: PERIOD,
  columns: {
    sold: { value: 300, previous: 250, change: 20, quantity: 10, daily: { '2026-11-16': 300 }, contributors: [{ id: 1, name: 'Main', value: 300 }] },
    corp_project: { value: 50, previous: null, change: null, quantity: 2, daily: { '2026-11-16': 50 }, contributors: [{ id: 1, name: 'Main', value: 50 }] },
    unknown: { value: 40, previous: null, change: null, quantity: 5, daily: null, contributors: [{ id: 2, name: 'Alt', value: 40 }] },
  },
  net: { value: 350, previous: 250, change: 40, daily: { '2026-11-16': 350 }, contributors: [{ id: 1, name: 'Main', value: 350 }] },
  missingPriceTypeIds: [],
  corpProjectCountedInPve: false,
}

const INDUSTRY: LedgerIndustry = {
  period: PERIOD,
  totals: { profit: 130, projectCount: 4, unknownProjectCount: 0, capital: 600, slotHours: 10, iskPerHour: 13, change: 12.5 },
  daily: { '2026-11-15': 130 },
  months: [],
  products: [],
}

const PLANETARY: PlanetaryRevenue = {
  periodEnd: '2026-11-17',
  days: 30,
  tiers: [],
  realisedIsk: 80,
  realisedChange: 5,
  realisedDaily: [{ date: '2026-11-14', amount: 80 }],
  realisedContributors: [{ characterId: 9, characterName: 'Hauler', value: 80 }],
  estimate: { dailyIsk: 10, periodIsk: 300, contributors: [{ characterId: 9, characterName: 'Hauler', value: 300 }] },
  toFix: [],
}

function sources(overrides: Partial<BalanceSources> = {}): BalanceSources {
  return { pve: PVE, mining: MINING, industry: INDUSTRY, planetary: PLANETARY, ...overrides }
}

function columnOf(revenue: ReturnType<typeof buildBalanceRevenue>, id: string) {
  const column = revenue.columns.find(candidate => candidate.id === id)
  if (column === undefined) throw new Error(`no column ${id}`)
  return column
}

describe('buildBalanceRevenue', () => {
  it('lists PVE, Mining, Industry, Planetary then Not valued, each linking to its module', () => {
    const revenue = buildBalanceRevenue(sources(), LABELS)

    expect(revenue.columns.map(column => [column.id, column.label, column.to])).toEqual([
      ['pve', 'PVE', { name: 'pve-revenue' }],
      ['mining', 'Mining', { name: 'mining-my-mining' }],
      ['industry', 'Industry', { name: 'industry-revenue' }],
      ['planetary', 'Planetary', { name: 'planetary', query: { tab: 'revenue' } }],
      ['unknown', 'Not valued', { name: 'mining-my-mining' }],
    ])
  })

  it('gives each module column exactly the net shown on the module revenue tab', () => {
    const revenue = buildBalanceRevenue(sources(), LABELS)

    expect(columnOf(revenue, 'pve').value).toBe(sumNet(buildPveRevenueColumns(PVE, PVE_LABELS)))
    expect(columnOf(revenue, 'pve').value).toBe(540)
    expect(columnOf(revenue, 'mining').value).toBe(sumNet(buildMiningRevenueColumns(MINING, MINING_LABELS)))
    expect(columnOf(revenue, 'mining').value).toBe(350)
    expect(columnOf(revenue, 'industry').value).toBe(buildIndustryRevenueColumns(INDUSTRY, INDUSTRY_LABELS)[0].value)
    expect(columnOf(revenue, 'industry').value).toBe(130)
  })

  it('carries the change, the daily amounts and the contributors of each column', () => {
    const revenue = buildBalanceRevenue(sources(), LABELS)

    expect(columnOf(revenue, 'pve')).toMatchObject({ change: 35, daily: [{ date: '2026-11-17', amount: 540 }, { date: '2026-11-16', amount: 10 }], contributors: null })
    expect(columnOf(revenue, 'mining')).toMatchObject({ change: 40, contributors: [{ id: 1, name: 'Main', value: 350 }] })
    expect(columnOf(revenue, 'industry')).toMatchObject({
      change: 12.5,
      daily: [{ date: '2026-11-15', amount: 130 }],
      contributors: [{ id: ACCOUNT_PROJECTS_CONTRIBUTOR_ID, name: 'Account projects', value: 130 }],
    })
  })

  it('uses the realised planetary sales as an actual column when there are some', () => {
    const planetary = columnOf(buildBalanceRevenue(sources(), LABELS), 'planetary')

    expect(planetary).toMatchObject({
      kind: 'actual',
      label: 'Planetary',
      value: 80,
      change: 5,
      contributors: [{ id: 9, name: 'Hauler', value: 80 }],
    })
  })

  it('falls back on the production estimate, dashed and labelled as an estimate, when nothing was sold', () => {
    const planetary = columnOf(buildBalanceRevenue(sources({ planetary: { ...PLANETARY, realisedIsk: 0 } }), LABELS), 'planetary')

    expect(planetary).toMatchObject({ kind: 'estimate', label: 'Planetary (estimate)', value: 300, change: null, daily: null })
  })

  it('puts the uncategorised ore in a hatched column outside the net', () => {
    const unknown = columnOf(buildBalanceRevenue(sources(), LABELS), 'unknown')

    expect(unknown).toMatchObject({ kind: 'unknown', value: 40, contributors: [{ id: 2, name: 'Alt', value: 40 }] })
  })

  it('leaves a column unknown, never 0, when its source is not available', () => {
    const revenue = buildBalanceRevenue({ pve: null, mining: null, industry: null, planetary: null }, LABELS)

    expect(revenue.columns.map(column => column.value)).toEqual([null, null, null, null, null])
    expect(revenue.period).toBeNull()
    expect(revenue.net.daily).toBeNull()
  })

  it('leaves the industry profit unknown when every project has an unknown profit', () => {
    const industry = { ...INDUSTRY, totals: { ...INDUSTRY.totals, profit: 0, projectCount: 2, unknownProjectCount: 2 } }

    const column = columnOf(buildBalanceRevenue(sources({ industry }), LABELS), 'industry')

    expect(column).toMatchObject({ value: null, contributors: null })
  })

  it('adds the daily amounts of the columns counted in the net, and no change', () => {
    const revenue = buildBalanceRevenue(sources({ planetary: { ...PLANETARY, realisedIsk: 0 } }), LABELS)

    expect(revenue.net.change).toBeNull()
    expect(revenue.net.daily).toEqual([
      { date: '2026-11-17', amount: 540 },
      { date: '2026-11-16', amount: 360 },
      { date: '2026-11-15', amount: 130 },
    ])
  })

  it('takes the period of the PVE grid, or of the mining grid without it', () => {
    expect(buildBalanceRevenue(sources(), LABELS).period).toEqual({ to: '2026-11-17', days: 30 })
    expect(buildBalanceRevenue(sources({ pve: null }), LABELS).period).toEqual({ to: '2026-11-17', days: 30 })
  })
})

describe('balance badges and to fix list', () => {
  const ALL_ISSUES = sources({
    mining: { ...MINING, missingPriceTypeIds: [1230, 1231] },
    industry: { ...INDUSTRY, totals: { ...INDUSTRY.totals, unknownProjectCount: 2 } },
    planetary: { ...PLANETARY, toFix: [{ characterId: 9, characterName: 'Hauler', planetId: 1, planetName: 'A', solarSystemName: null }] },
  })

  it('lists no item when nothing skews the figures', () => {
    expect(balanceTodosOf(sources({ mining: { ...MINING, columns: { ...MINING.columns, unknown: { ...MINING.columns.unknown, quantity: 0 } } } }))).toEqual([])
  })

  it('aggregates the issues of every module in a fixed order', () => {
    expect(balanceTodosOf(ALL_ISSUES)).toEqual([
      { kind: 'missingPrice', count: 2 },
      { kind: 'uncategorised', count: 5 },
      { kind: 'unknownProfit', count: 2 },
      { kind: 'piWithoutSale', count: 1 },
    ])
  })

  it('numbers the badges like the to fix items, on the cell or the header concerned', () => {
    const revenue = buildBalanceRevenue(ALL_ISSUES, LABELS)

    expect(columnOf(revenue, 'mining').cellBadge).toBe(1)
    expect(columnOf(revenue, 'unknown').headerBadge).toBe(2)
    expect(columnOf(revenue, 'industry').cellBadge).toBe(3)
    expect(columnOf(revenue, 'planetary').headerBadge).toBe(4)
    expect(columnOf(revenue, 'pve').cellBadge).toBeUndefined()
  })

  it('renumbers when an issue is absent', () => {
    const revenue = buildBalanceRevenue(sources({ industry: { ...INDUSTRY, totals: { ...INDUSTRY.totals, unknownProjectCount: 1 } } }), LABELS)

    expect(columnOf(revenue, 'unknown').headerBadge).toBe(1)
    expect(columnOf(revenue, 'industry').cellBadge).toBe(2)
    expect(columnOf(revenue, 'mining').cellBadge).toBeUndefined()
  })
})

import type { PlanetaryRevenue } from '@/stores/planetary'
import {
  ESTIMATE_COLUMN_ID,
  OFF_MARKET_COLUMN_ID,
  buildEstimateColumn,
  buildRealisedColumns,
  buildRealisedNet,
  groupColoniesWithoutSale,
} from '@/utils/planetaryRevenue'
import { sumNet } from '@/utils/revenueGrid'

const LABELS = { tier: (tier: string) => `Tier ${tier}`, offMarket: 'Off-market', estimate: 'Estimate' }

const REVENUE: PlanetaryRevenue = {
  periodEnd: '2026-11-17',
  days: 30,
  tiers: [
    { tier: 'P1', realisedIsk: 0, change: null, daily: [], contributors: [] },
    { tier: 'P2', realisedIsk: 4_000_000, change: 12.5, daily: [{ date: '2026-11-16', amount: 4_000_000 }], contributors: [{ characterId: 7, characterName: 'Ilyra Castell', value: 4_000_000 }] },
  ],
  realisedIsk: 4_000_000,
  realisedChange: 12.5,
  realisedDaily: [{ date: '2026-11-16', amount: 4_000_000 }],
  realisedContributors: [{ characterId: 7, characterName: 'Ilyra Castell', value: 4_000_000 }],
  estimate: { dailyIsk: 500_000, periodIsk: 15_000_000, contributors: [{ characterId: 7, characterName: 'Ilyra Castell', value: 15_000_000 }] },
  toFix: [],
}

describe('planetary revenue columns', () => {
  it('builds one actual column per tier and a last off-market column excluded from the net with an unknown value', () => {
    const columns = buildRealisedColumns(REVENUE, LABELS)

    expect(columns.map(column => [column.id, column.kind, column.value])).toEqual([
      ['P1', 'actual', 0],
      ['P2', 'actual', 4_000_000],
      [OFF_MARKET_COLUMN_ID, 'unknown', null],
    ])
    expect(columns[2]).toMatchObject({ daily: null, contributors: null, change: null })
  })

  it('keeps the realised net free of the estimate', () => {
    const columns = buildRealisedColumns(REVENUE, LABELS)

    expect(sumNet(columns)).toBe(REVENUE.realisedIsk)
  })

  it('maps the contributors by character id and carries the change of the tier', () => {
    const [firstTier, secondTier] = buildRealisedColumns(REVENUE, LABELS)

    expect(secondTier).toMatchObject({ change: 12.5, contributors: [{ id: 7, name: 'Ilyra Castell', value: 4_000_000 }] })
    expect(firstTier?.change).toBeNull()
  })

  it('builds the estimate as a separate estimate column over the whole period with no daily series', () => {
    expect(buildEstimateColumn(REVENUE, LABELS)).toMatchObject({
      id: ESTIMATE_COLUMN_ID,
      kind: 'estimate',
      tone: 'planetary',
      value: 15_000_000,
      daily: null,
      change: null,
      contributors: [{ id: 7, name: 'Ilyra Castell', value: 15_000_000 }],
    })
  })

  it('builds the realised net series from the totals', () => {
    expect(buildRealisedNet(REVENUE)).toEqual({
      change: 12.5,
      daily: [{ date: '2026-11-16', amount: 4_000_000 }],
      contributors: [{ id: 7, name: 'Ilyra Castell', value: 4_000_000 }],
    })
  })
})

describe('groupColoniesWithoutSale', () => {
  it('groups the colonies by character and falls back to the planet id when the name is unknown', () => {
    const groups = groupColoniesWithoutSale([
      { characterId: 7, characterName: 'Ilyra Castell', planetId: 40009077, planetName: 'Jita IV', solarSystemName: 'Jita' },
      { characterId: 8, characterName: 'Doran Vex', planetId: 40009081, planetName: null, solarSystemName: null },
      { characterId: 7, characterName: 'Ilyra Castell', planetId: 40009078, planetName: 'Jita V', solarSystemName: 'Jita' },
    ])

    expect(groups).toEqual([
      { characterId: 7, characterName: 'Ilyra Castell', planetNames: ['Jita IV', 'Jita V'] },
      { characterId: 8, characterName: 'Doran Vex', planetNames: ['40009081'] },
    ])
  })
})

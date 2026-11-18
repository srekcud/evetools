import type { PlanetaryRevenue, PlanetaryRevenueContributor, PlanetaryRevenueToFix } from '@/stores/planetary'
import type { RevenueContributor, RevenueGridColumn, RevenueSeries } from '@/types/revenueGrid'

export const OFF_MARKET_COLUMN_ID = 'off-market'
export const ESTIMATE_COLUMN_ID = 'estimate'
export const WALLET_UNSYNCED_COLUMN_ID = 'wallet-unsynced'

export interface PlanetaryRevenueLabels {
  tier: (tier: string) => string
  offMarket: string
  walletUnsynced: string
  estimate: string
}

export interface ColoniesWithoutSale {
  characterId: number
  characterName: string
  planetNames: string[]
}

function toContributors(contributors: PlanetaryRevenueContributor[]): RevenueContributor[] {
  return contributors.map(contributor => ({ id: contributor.characterId, name: contributor.characterName, value: contributor.value }))
}

export function buildRealisedColumns(revenue: PlanetaryRevenue, labels: PlanetaryRevenueLabels): RevenueGridColumn[] {
  const tierColumns = revenue.tiers.map((tier): RevenueGridColumn => ({
    id: tier.tier,
    label: labels.tier(tier.tier),
    tone: 'planetary',
    kind: 'actual',
    value: tier.realisedIsk,
    change: tier.change,
    daily: tier.daily,
    contributors: toContributors(tier.contributors),
  }))
  const offMarketColumn: RevenueGridColumn = {
    id: OFF_MARKET_COLUMN_ID,
    label: labels.offMarket,
    tone: 'neutral',
    kind: 'unknown',
    value: null,
    change: null,
    daily: null,
    contributors: null,
  }

  const unsyncedCount = revenue.unsyncedWallets.length
  if (unsyncedCount === 0) return [...tierColumns, offMarketColumn]

  const walletUnsyncedColumn: RevenueGridColumn = {
    id: WALLET_UNSYNCED_COLUMN_ID,
    label: labels.walletUnsynced,
    tone: 'neutral',
    kind: 'unknown',
    value: null,
    change: null,
    daily: null,
    contributors: null,
    headerBadge: unsyncedCount,
  }

  return [...tierColumns, offMarketColumn, walletUnsyncedColumn]
}

export function buildRealisedNet(revenue: PlanetaryRevenue): RevenueSeries {
  return { change: revenue.realisedChange, daily: revenue.realisedDaily, contributors: toContributors(revenue.realisedContributors) }
}

export function buildEstimateColumn(revenue: PlanetaryRevenue, labels: PlanetaryRevenueLabels): RevenueGridColumn {
  return {
    id: ESTIMATE_COLUMN_ID,
    label: labels.estimate,
    tone: 'planetary',
    kind: 'estimate',
    value: revenue.estimate.periodIsk,
    change: null,
    daily: null,
    contributors: toContributors(revenue.estimate.contributors),
  }
}

export const ESTIMATE_NET: RevenueSeries = { change: null, daily: null, contributors: null }

export function groupColoniesWithoutSale(toFix: PlanetaryRevenueToFix[]): ColoniesWithoutSale[] {
  const byCharacter = new Map<number, ColoniesWithoutSale>()
  for (const colony of toFix) {
    const group = byCharacter.get(colony.characterId) ?? { characterId: colony.characterId, characterName: colony.characterName, planetNames: [] }
    group.planetNames.push(colony.planetName ?? String(colony.planetId))
    byCharacter.set(colony.characterId, group)
  }

  return [...byCharacter.values()]
}

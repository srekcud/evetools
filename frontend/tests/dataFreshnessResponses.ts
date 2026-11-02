import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import type { DataFreshnessLevel } from '@/utils/dataFreshness'

export const DATA_FRESHNESS_ENDPOINT = '/data-freshness'

export const NOW = '2026-10-31T12:00:00Z'

export const JITA_PRICES_INTERVAL = 3_600
export const COST_INDICES_INTERVAL = 7_200
export const ADJUSTED_PRICES_INTERVAL = 86_400

export interface DataFreshnessEntry {
  source: string
  lastSuccessAt: string | null
  expectedInterval: number
  lastRunFailed: boolean
}

export const JITA_PRICES_SYNCED_AT_11_18: DataFreshnessEntry = {
  source: 'market-jita',
  lastSuccessAt: '2026-10-31T11:18:00+00:00',
  expectedInterval: JITA_PRICES_INTERVAL,
  lastRunFailed: false,
}

export const COST_INDICES_SYNCED_AT_05_30_THEN_FAILED: DataFreshnessEntry = {
  source: 'cost-indices',
  lastSuccessAt: '2026-10-31T05:30:00+00:00',
  expectedInterval: COST_INDICES_INTERVAL,
  lastRunFailed: true,
}

export const ADJUSTED_PRICES_NEVER_SYNCED: DataFreshnessEntry = {
  source: 'adjusted-prices',
  lastSuccessAt: null,
  expectedInterval: ADJUSTED_PRICES_INTERVAL,
  lastRunFailed: false,
}

export const TYPICAL_DATA_FRESHNESS_RESPONSE: DataFreshnessEntry[] = [
  JITA_PRICES_SYNCED_AT_11_18,
  COST_INDICES_SYNCED_AT_05_30_THEN_FAILED,
  ADJUSTED_PRICES_NEVER_SYNCED,
]

export interface ShownFreshness {
  label: string
  level: string | undefined
}

export const JITA_PRICES_42_MIN_AGO_FRESH: ShownFreshness = { label: 'Jita prices · 42min ago', level: 'fresh' satisfies DataFreshnessLevel }
export const COST_INDICES_6_H_AGO_FAILED_STALE: ShownFreshness = { label: 'Cost indices · 6h ago · last sync failed', level: 'stale' satisfies DataFreshnessLevel }
export const ADJUSTED_PRICES_UNKNOWN: ShownFreshness = { label: 'Adjusted prices · unknown date', level: 'unknown' satisfies DataFreshnessLevel }
export const JITA_PRICES_UNKNOWN: ShownFreshness = { label: 'Jita prices · unknown date', level: 'unknown' satisfies DataFreshnessLevel }
export const COST_INDICES_UNKNOWN: ShownFreshness = { label: 'Cost indices · unknown date', level: 'unknown' satisfies DataFreshnessLevel }

export const TYPICAL_RESPONSE_SHOWN: ShownFreshness[] = [
  JITA_PRICES_42_MIN_AGO_FRESH,
  COST_INDICES_6_H_AGO_FAILED_STALE,
  ADJUSTED_PRICES_UNKNOWN,
]

export function shownFreshness(container: VueWrapper | DOMWrapper<Element>): ShownFreshness[] {
  return container.findAll('[data-testid="data-freshness"]').map(badge => ({
    label: badge.text(),
    level: badge.attributes('data-level'),
  }))
}

export function dataFreshnessRequestCount(calls: ReadonlyArray<readonly unknown[]>): number {
  return calls.filter(([endpoint]) => typeof endpoint === 'string' && endpoint.split('?')[0] === DATA_FRESHNESS_ENDPOINT).length
}

export type DataFreshnessSource = 'market-jita' | 'cost-indices' | 'adjusted-prices'

export type DataFreshnessLevel = 'fresh' | 'aging' | 'stale' | 'unknown'

export interface DataFreshnessState {
  lastSuccessAt: string | null
  expectedInterval: number
  lastRunFailed: boolean
}

const FRESH_INTERVAL_RATIO = 1.5
const AGING_INTERVAL_RATIO = 3
const MS_PER_SECOND = 1000

export function readableLastSuccessAt(state: DataFreshnessState | null): string | null {
  if (state === null || state.lastSuccessAt === null) return null
  return Number.isNaN(new Date(state.lastSuccessAt).getTime()) ? null : state.lastSuccessAt
}

export function dataFreshnessLevel(state: DataFreshnessState | null, now: Date): DataFreshnessLevel {
  const lastSuccessAt = readableLastSuccessAt(state)
  if (state === null || lastSuccessAt === null) return 'unknown'

  const ageSeconds = Math.max(0, now.getTime() - new Date(lastSuccessAt).getTime()) / MS_PER_SECOND

  if (ageSeconds > AGING_INTERVAL_RATIO * state.expectedInterval) return 'stale'
  if (ageSeconds > FRESH_INTERVAL_RATIO * state.expectedInterval || state.lastRunFailed) return 'aging'
  return 'fresh'
}

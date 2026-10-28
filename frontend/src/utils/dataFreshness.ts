export type DataFreshnessSource = 'market-jita' | 'cost-indices' | 'adjusted-prices'

export type DataFreshnessLevel = 'fresh' | 'aging' | 'stale' | 'unknown'

export interface DataFreshnessState {
  lastSuccessAt: string | null
  expectedInterval: number
  lastRunFailed: boolean
}

export function dataFreshnessLevel(_state: DataFreshnessState | null, _now: Date): DataFreshnessLevel {
  throw new Error('dataFreshnessLevel is not implemented')
}

import { ref } from 'vue'
import type { MinedQuantityState, MinedQuantityUnknownReason } from '@/stores/miningOperation'

export type StockpileCoverageUnknownReason = 'portion_size_unknown'

export interface StockpileCoverageMineral {
  typeId: number
  typeName: string | null
  producedQuantity: number | null
  deficit: number
  hasDeficit: boolean
  coveragePercent: number | null
  coversWholeDeficit: boolean | null
  coverageUnknownReason: StockpileCoverageUnknownReason | null
}

export interface StockpileCoverage {
  operationId: string
  state: MinedQuantityState
  unknownReason: MinedQuantityUnknownReason | null
  yieldKnown: boolean
  reprocessYield: number | null
  minerals: StockpileCoverageMineral[]
}

export function useStockpileCoverage() {
  const coverage = ref<StockpileCoverage | null>(null)
  const isLoading = ref(false)
  const hasError = ref(false)

  async function load(_operationId: string): Promise<void> {}

  return { coverage, isLoading, hasError, load }
}

import { ref } from 'vue'
import { apiRequest } from '@/services/api'
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

  async function load(operationId: string): Promise<void> {
    isLoading.value = true
    hasError.value = false
    try {
      coverage.value = await apiRequest<StockpileCoverage>(`/mining-operations/${operationId}/stockpile-coverage`)
    } catch (e) {
      console.error('Failed to load the Stockpile coverage:', e)
      coverage.value = null
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  return { coverage, isLoading, hasError, load }
}

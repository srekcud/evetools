import { computed, type ComputedRef } from 'vue'
import type { PartialPriceWarning } from '@/stores/industry/types'

export function usePartialPriceCoverages(_warnings: () => PartialPriceWarning[]): ComputedRef<Map<number, number>> {
  return computed(() => new Map<number, number>())
}

import { computed, type ComputedRef } from 'vue'
import type { PartialPriceWarning } from '@/stores/industry/types'

export function usePartialPriceCoverages(warnings: () => PartialPriceWarning[]): ComputedRef<Map<number, number>> {
  return computed(() => {
    const coverages = new Map<number, number>()
    for (const warning of warnings()) {
      const known = coverages.get(warning.typeId)
      if (known === undefined || warning.coveragePercent < known) coverages.set(warning.typeId, warning.coveragePercent)
    }
    return coverages
  })
}

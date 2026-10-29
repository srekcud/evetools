import { computed, type ComputedRef } from 'vue'
import type { IndustryProjectStep } from '@/stores/industry/types'

export function useStepProductNames(steps: ComputedRef<IndustryProjectStep[]>) {
  return computed(() => {
    const names = new Map<number, string>()
    for (const step of steps.value) {
      if (step.productTypeName) names.set(step.productTypeId, step.productTypeName)
    }
    return names
  })
}

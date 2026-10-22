import { computed, type ComputedRef } from 'vue'
import type { JobInstallCostUnknownReason, MissingData, MissingDataReason } from '@/stores/industry/types'

const JOB_INSTALL_COST_UNKNOWN_REASONS: Partial<Record<MissingDataReason, JobInstallCostUnknownReason>> = {
  cost_index: 'missing_cost_index',
  adjusted_price: 'missing_adjusted_price',
}

export function useUnknownInstallCostReasons(missingData: () => MissingData[]): ComputedRef<JobInstallCostUnknownReason[]> {
  return computed(() => {
    const reasons = new Set<JobInstallCostUnknownReason>()
    for (const missing of missingData()) {
      const reason = JOB_INSTALL_COST_UNKNOWN_REASONS[missing.reason]
      if (reason != null) reasons.add(reason)
    }
    return [...reasons]
  })
}

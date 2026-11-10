import { computed, type Ref } from 'vue'
import type { MiningEntry } from '@/stores/ledger'

export interface MiningValueByUsage {
  sold: number | null
  corp_project: number | null
  industry: number | null
  unknown: number | null
  total: number | null
}

function addKnownValues(left: number | null, right: number | null): number | null {
  return left === null || right === null ? null : left + right
}

export function useMiningPricing(miningEntries: Ref<MiningEntry[]>) {
  /**
   * Get just the best price numeric value for a given entry (for stats calculations).
   */
  function getBestPriceValue(entry: MiningEntry): number | null {
    return entry.unitValue
  }

  function getEntryValue(entry: MiningEntry): number | null {
    if (entry.quantity === 0) return 0
    const bestPrice = getBestPriceValue(entry)
    return bestPrice === null ? null : bestPrice * entry.quantity
  }

  /**
   * Aggregated mining values by usage type.
   */
  const miningByUsage = computed((): MiningValueByUsage => {
    const result: MiningValueByUsage = {
      sold: 0,
      corp_project: 0,
      industry: 0,
      unknown: 0,
      total: 0,
    }
    for (const entry of miningEntries.value) {
      const value = getEntryValue(entry)
      result[entry.usage] = addKnownValues(result[entry.usage], value)
      result.total = addKnownValues(result.total, value)
    }
    return result
  })

  return {
    getBestPriceValue,
    getEntryValue,
    miningByUsage,
  }
}

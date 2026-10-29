import { computed, type Ref } from 'vue'
import type { MiningEntry } from '@/stores/ledger'

export interface MiningValueByUsage {
  sold: number | null
  corp_project: number | null
  industry: number | null
  unknown: number | null
  total: number | null
}

interface PriceSource {
  value: number | null
  color: string
  source: string
}

type PricedSource = PriceSource & { value: number }

const FALLBACK_PRICE_COLOR = 'text-slate-400'
const NO_PRICE_COLOR = 'text-slate-600'

function addKnownValues(left: number | null, right: number | null): number | null {
  return left === null || right === null ? null : left + right
}

/**
 * Composable for mining pricing logic: best price selection and usage-based aggregation.
 */
export function useMiningPricing(
  miningEntries: Ref<MiningEntry[]>,
  reprocessYield: Ref<number>,
) {
  /**
   * Get best price with color and source label for display in the table.
   */
  function getBestPrice(entry: MiningEntry): PriceSource {
    const sources: PriceSource[] = [
      { value: entry.compressedEquivalentPrice, color: 'text-cyan-400', source: 'Jita compresse' },
      { value: entry.reprocessValue, color: 'text-purple-400', source: `Jita reprocess ${reprocessYield.value.toFixed(0)}%` },
      { value: entry.structureCompressedEquivalentPrice, color: 'text-teal-400', source: 'Structure compresse' },
      { value: entry.structureReprocessValue, color: 'text-lime-400', source: `Structure reprocess ${reprocessYield.value.toFixed(0)}%` },
    ]
    const pricedSources = sources.filter((source): source is PricedSource => source.value !== null)
    const positiveSources = pricedSources.filter(source => source.value > 0)

    if (positiveSources.length > 0) {
      return positiveSources.reduce((best, current) => current.value > best.value ? current : best)
    }
    if (entry.totalValue !== null && entry.quantity > 0) {
      return { value: entry.totalValue / entry.quantity, color: FALLBACK_PRICE_COLOR, source: '' }
    }
    if (entry.rawUnitPrice !== null) {
      return { value: entry.rawUnitPrice, color: FALLBACK_PRICE_COLOR, source: '' }
    }
    return { value: pricedSources.length > 0 ? 0 : null, color: NO_PRICE_COLOR, source: '' }
  }

  /**
   * Get just the best price numeric value for a given entry (for stats calculations).
   */
  function getBestPriceValue(entry: MiningEntry): number | null {
    return getBestPrice(entry).value
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
    getBestPrice,
    getBestPriceValue,
    getEntryValue,
    miningByUsage,
  }
}

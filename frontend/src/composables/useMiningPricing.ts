import { computed, type Ref } from 'vue'
import type { MiningEntry } from '@/stores/ledger'

/**
 * Composable for mining pricing logic: best price selection and usage-based aggregation.
 */
export function useMiningPricing(
  miningEntries: Ref<MiningEntry[]>,
  reprocessYield: Ref<number>,
) {
  /**
   * Get just the best price numeric value for a given entry (for stats calculations).
   */
  function getBestPriceValue(entry: MiningEntry): number {
    const prices: number[] = []

    if (entry.compressedEquivalentPrice) prices.push(entry.compressedEquivalentPrice)
    if (entry.reprocessValue) prices.push(entry.reprocessValue)
    if (entry.structureCompressedUnitPrice) prices.push(entry.structureCompressedUnitPrice / 100)
    if (entry.structureReprocessValue) prices.push(entry.structureReprocessValue)

    if (prices.length === 0 && entry.totalValue && entry.quantity > 0) {
      return entry.totalValue / entry.quantity
    }

    return prices.length > 0 ? Math.max(...prices) : 0
  }

  /**
   * Get best price with color and source label for display in the table.
   */
  function getBestPrice(entry: MiningEntry): { value: number; color: string; source: string } {
    const prices: { value: number; color: string; source: string }[] = []

    if (entry.compressedEquivalentPrice) {
      prices.push({ value: entry.compressedEquivalentPrice, color: 'text-cyan-400', source: 'Jita compresse' })
    }
    if (entry.reprocessValue) {
      prices.push({ value: entry.reprocessValue, color: 'text-purple-400', source: `Jita reprocess ${reprocessYield.value.toFixed(0)}%` })
    }
    if (entry.structureCompressedUnitPrice) {
      prices.push({ value: entry.structureCompressedUnitPrice / 100, color: 'text-teal-400', source: 'Structure compresse' })
    }
    if (entry.structureReprocessValue) {
      prices.push({ value: entry.structureReprocessValue, color: 'text-lime-400', source: `Structure reprocess ${reprocessYield.value.toFixed(0)}%` })
    }

    if (prices.length === 0) {
      return { value: 0, color: 'text-slate-600', source: '' }
    }

    return prices.reduce((best, current) => current.value > best.value ? current : best)
  }

  /**
   * Aggregated mining values by usage type.
   */
  const miningByUsage = computed(() => {
    const result = {
      sold: 0,
      corp_project: 0,
      industry: 0,
      unknown: 0,
      total: 0,
    }
    for (const entry of miningEntries.value) {
      const bestPrice = getBestPriceValue(entry)
      const value = bestPrice * entry.quantity
      result[entry.usage] = (result[entry.usage] || 0) + value
      result.total += value
    }
    return result
  })

  return {
    getBestPrice,
    getBestPriceValue,
    miningByUsage,
  }
}

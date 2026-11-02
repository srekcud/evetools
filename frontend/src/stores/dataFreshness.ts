import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { DataFreshnessSource, DataFreshnessState } from '@/utils/dataFreshness'

export const DATA_FRESHNESS_SOURCES: readonly DataFreshnessSource[] = ['market-jita', 'cost-indices', 'adjusted-prices']

export const useDataFreshnessStore = defineStore('dataFreshness', () => {
  const states = ref<Record<DataFreshnessSource, DataFreshnessState | null>>({
    'market-jita': null,
    'cost-indices': null,
    'adjusted-prices': null,
  })

  async function fetchDataFreshness(): Promise<void> {
    return
  }

  return { states, fetchDataFreshness }
})

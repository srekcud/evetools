import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { DataFreshnessSource, DataFreshnessState } from '@/utils/dataFreshness'

export const DATA_FRESHNESS_SOURCES: readonly DataFreshnessSource[] = ['market-jita', 'cost-indices', 'adjusted-prices']
export const JITA_PRICES_SOURCES: readonly DataFreshnessSource[] = ['market-jita']

type DataFreshnessStates = Record<DataFreshnessSource, DataFreshnessState | null>

interface DataFreshnessEntry extends DataFreshnessState {
  source: string
}

function allUnknown(): DataFreshnessStates {
  return {
    'market-jita': null,
    'cost-indices': null,
    'adjusted-prices': null,
  }
}

export const useDataFreshnessStore = defineStore('dataFreshness', () => {
  const states = ref<DataFreshnessStates>(allUnknown())

  async function fetchDataFreshness(): Promise<void> {
    try {
      const entries = await apiRequest<DataFreshnessEntry[]>('/data-freshness')
      const next = allUnknown()
      for (const { source, lastSuccessAt, expectedInterval, lastRunFailed } of entries) {
        const knownSource = DATA_FRESHNESS_SOURCES.find(candidate => candidate === source)
        if (knownSource !== undefined) next[knownSource] = { lastSuccessAt, expectedInterval, lastRunFailed }
      }
      states.value = next
    } catch {
      states.value = allUnknown()
    }
  }

  return { states, fetchDataFreshness }
})

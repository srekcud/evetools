import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { OpportunityMarket, OpportunitySort, ProductionOpportunitiesResponse } from './types'

export const useOpportunitiesStore = defineStore('industry-opportunities', () => {
  const result = ref<ProductionOpportunitiesResponse | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function fetchOpportunities(market: OpportunityMarket, sort: OpportunitySort, capital: number | null): Promise<void> {
    const request = ++latestRequest
    loading.value = true
    error.value = null
    const capitalQuery = capital === null ? '' : `&capital=${capital}`
    try {
      const response = await apiRequest<ProductionOpportunitiesResponse>(`/industry/opportunities?market=${market}&sort=${sort}${capitalQuery}`)
      if (request !== latestRequest) return
      result.value = response
    } catch (e) {
      if (request !== latestRequest) return
      result.value = null
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }

  return { result, loading, error, fetchOpportunities }
})

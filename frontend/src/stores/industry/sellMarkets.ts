import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { SellMarketResponse } from './types'

export const useSellMarketsStore = defineStore('industry-sell-markets', () => {
  const result = ref<SellMarketResponse | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function fetchSellMarkets(typeId: number, quantity: number): Promise<void> {
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const response = await apiRequest<SellMarketResponse>(`/industry/sell-markets/${typeId}?quantity=${quantity}`)
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

  return { result, loading, error, fetchSellMarkets }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { SellMarketResponse } from './types'

export const useSellMarketsStore = defineStore('industry-sell-markets', () => {
  const result = ref<SellMarketResponse | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchSellMarkets(typeId: number, quantity: number): Promise<void> {
    void typeId
    void quantity
  }

  return { result, loading, error, fetchSellMarkets }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import type { PendingStockResponse } from '@/utils/planetaryPickup'

export const usePlanetaryPendingStockStore = defineStore('planetaryPendingStock', () => {
  const data = ref<PendingStockResponse | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function fetchPendingStock(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await apiRequest<PendingStockResponse>('/planetary/pending-stock')
      if (!Array.isArray(response.customsOfficeStocks)) {
        throw new Error(i18n.global.t('pi.supply.pickup.errors.load'))
      }
      data.value = { pendingOutputs: response.pendingOutputs, customsOfficeStocks: response.customsOfficeStocks, complete: response.complete }
    } catch (e) {
      data.value = null
      error.value = e instanceof Error ? e.message : i18n.global.t('pi.supply.pickup.errors.load')
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return { data, isLoading, error, fetchPendingStock, clearError }
})

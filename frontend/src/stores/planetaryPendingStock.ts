import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { PendingStockResponse } from '@/utils/planetaryPickup'

export const usePlanetaryPendingStockStore = defineStore('planetaryPendingStock', () => {
  const data = ref<PendingStockResponse | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function fetchPendingStock(): Promise<void> {}

  function clearError(): void {
    error.value = null
  }

  return { data, isLoading, error, fetchPendingStock, clearError }
})

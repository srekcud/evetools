import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { SlotTrackerData } from './types'

export const useSlotsStore = defineStore('industry-slots', () => {
  const data = ref<SlotTrackerData | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const lastFetchAt = ref<Date | null>(null)

  async function fetchSlots(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const result = await apiRequest<SlotTrackerData>('/industry/slots')
      data.value = result
      lastFetchAt.value = new Date()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch slot data'
    } finally {
      loading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return { data, loading, error, lastFetchAt, fetchSlots, clearError }
})

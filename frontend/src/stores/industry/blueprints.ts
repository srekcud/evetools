import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { BlueprintLibrary } from './types'

export const useBlueprintsStore = defineStore('industry-blueprints', () => {
  const library = ref<BlueprintLibrary | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const requestingRefresh = ref(false)
  const refreshError = ref<string | null>(null)

  async function fetchLibrary(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      library.value = await apiRequest<BlueprintLibrary>('/industry/blueprints')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch the blueprint library'
    } finally {
      loading.value = false
    }
  }

  async function requestRefresh(): Promise<void> {
    requestingRefresh.value = true
    refreshError.value = null
    try {
      await apiRequest('/industry/blueprints/refresh', { method: 'POST' })
    } catch (e) {
      refreshError.value = e instanceof Error ? e.message : 'Failed to queue the blueprints refresh'
    } finally {
      requestingRefresh.value = false
    }
  }

  function clearErrors(): void {
    error.value = null
    refreshError.value = null
  }

  return { library, loading, error, requestingRefresh, refreshError, fetchLibrary, requestRefresh, clearErrors }
})

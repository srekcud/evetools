import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectSellOrders } from './types'

export const useProjectSellOrdersStore = defineStore('project-sell-orders', () => {
  const sellOrders = ref<ProjectSellOrders | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestProjectId: string | null = null

  async function fetchSellOrders(projectId: string): Promise<void> {
    latestProjectId = projectId
    loading.value = true
    error.value = null
    try {
      const fetched = await apiRequest<ProjectSellOrders>(`/industry/projects/${projectId}/sell-orders`)
      if (latestProjectId === projectId) sellOrders.value = fetched
    } catch (failure) {
      if (latestProjectId === projectId) {
        sellOrders.value = null
        error.value = failure instanceof Error ? failure.message : 'Failed to fetch sell orders'
      }
    } finally {
      if (latestProjectId === projectId) loading.value = false
    }
  }

  function reset(): void {
    latestProjectId = null
    sellOrders.value = null
    loading.value = false
    error.value = null
  }

  return { sellOrders, loading, error, fetchSellOrders, reset }
})

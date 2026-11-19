import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectPurchasesToDeliver } from './types'

function deliveriesPath(projectId: string): string {
  return `/industry/projects/${projectId}/purchases-to-deliver`
}

export const useGroupPurchasesToDeliverStore = defineStore('group-purchases-to-deliver', () => {
  const deliveries = ref<ProjectPurchasesToDeliver | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchDeliveries(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      deliveries.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const read = await apiRequest<ProjectPurchasesToDeliver>(deliveriesPath(projectId))
      if (request === latestRequest) {
        deliveries.value = read
      }
    } catch (e) {
      if (request === latestRequest) {
        deliveries.value = null
        error.value = e instanceof Error ? e.message : 'Failed to fetch purchases to deliver'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { deliveries, loading, error, fetchDeliveries }
})

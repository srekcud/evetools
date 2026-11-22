import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { TypeStationQuantities } from './types'

function stationQuantitiesPath(projectId: string): string {
  return `/industry/projects/${projectId}/station-quantities`
}

export const useGroupStationQuantitiesStore = defineStore('group-station-quantities', () => {
  const quantities = ref<TypeStationQuantities[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchStationQuantities(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      quantities.value = []
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const read = await apiRequest<TypeStationQuantities[]>(stationQuantitiesPath(projectId))
      if (!Array.isArray(read)) {
        throw new Error('Unexpected station quantities response')
      }
      if (request === latestRequest) {
        quantities.value = read
      }
    } catch (e) {
      if (request === latestRequest) {
        quantities.value = []
        error.value = e instanceof Error ? e.message : 'Failed to fetch station quantities'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { quantities, loading, error, fetchStationQuantities }
})

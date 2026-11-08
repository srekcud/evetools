import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectMaterialSourcing } from './types'

export const useGroupMaterialSourcingStore = defineStore('group-material-sourcing', () => {
  const sourcing = ref<ProjectMaterialSourcing | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function fetchMaterialSourcing(projectId: string): Promise<void> {
    if (sourcing.value !== null && sourcing.value.id !== projectId) {
      sourcing.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const fetched = await apiRequest<ProjectMaterialSourcing>(`/industry/projects/${projectId}/material-sourcing`)
      if (request === latestRequest) {
        sourcing.value = fetched
      }
    } catch (e) {
      if (request === latestRequest) {
        sourcing.value = null
        error.value = e instanceof Error ? e.message : 'Failed to fetch material sourcing'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { sourcing, loading, error, fetchMaterialSourcing }
})

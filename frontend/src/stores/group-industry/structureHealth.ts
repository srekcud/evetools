import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectStructureHealth, StructureHealthRow } from './types'

export const useGroupStructureHealthStore = defineStore('group-structure-health', () => {
  const rows = ref<StructureHealthRow[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchStructureHealth(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      rows.value = []
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const health = await apiRequest<ProjectStructureHealth>(`/industry/projects/${projectId}/structure-health`)
      if (request === latestRequest) {
        rows.value = health.rows
      }
    } catch (e) {
      if (request === latestRequest) {
        rows.value = []
        error.value = e instanceof Error ? e.message : 'Failed to read the structure health'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { rows, loading, error, fetchStructureHealth }
})

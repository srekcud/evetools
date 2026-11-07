import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectPlanning } from './types'

export const useGroupPlanningStore = defineStore('group-planning', () => {
  const planning = ref<ProjectPlanning | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchPlanning(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      planning.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const fetched = await apiRequest<ProjectPlanning>(`/industry/projects/${projectId}/planning`)
      if (request === latestRequest) {
        planning.value = fetched
      }
    } catch (e) {
      if (request === latestRequest) {
        planning.value = null
        error.value = e instanceof Error ? e.message : 'Failed to fetch planning'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { planning, loading, error, fetchPlanning }
})

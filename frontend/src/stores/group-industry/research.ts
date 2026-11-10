import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectResearch, ResearchRow } from './types'

export const useGroupResearchStore = defineStore('group-research', () => {
  const rows = ref<ResearchRow[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchResearch(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      rows.value = []
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const research = await apiRequest<ProjectResearch>(`/industry/projects/${projectId}/research`)
      if (request === latestRequest) {
        rows.value = research.rows
      }
    } catch (e) {
      if (request === latestRequest) {
        rows.value = []
        error.value = e instanceof Error ? e.message : 'Failed to fetch research suggestions'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { rows, loading, error, fetchResearch }
})

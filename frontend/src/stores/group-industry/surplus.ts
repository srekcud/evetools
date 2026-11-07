import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectSurplus, ProjectSurplusLine } from './types'

export const useGroupSurplusStore = defineStore('group-surplus', () => {
  const lines = ref<ProjectSurplusLine[]>([])
  const totalValue = ref<number | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchSurplus(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      lines.value = []
      totalValue.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const surplus = await apiRequest<ProjectSurplus>(`/industry/projects/${projectId}/surplus`)
      if (request === latestRequest) {
        lines.value = surplus.lines
        totalValue.value = surplus.totalValue
      }
    } catch (e) {
      if (request === latestRequest) {
        lines.value = []
        totalValue.value = null
        error.value = e instanceof Error ? e.message : 'Failed to read the material surplus'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { lines, totalValue, loading, error, fetchSurplus }
})

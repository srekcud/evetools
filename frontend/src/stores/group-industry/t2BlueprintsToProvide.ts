import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { T2BlueprintsToProvide } from './types'

export const useGroupT2BlueprintsToProvideStore = defineStore('group-t2-blueprints-to-provide', () => {
  const toProvide = ref<T2BlueprintsToProvide | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchToProvide(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      toProvide.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const read = await apiRequest<T2BlueprintsToProvide | undefined>(`/industry/projects/${projectId}/t2-blueprints-to-provide`)
      if (request === latestRequest) {
        toProvide.value = read ?? null
      }
    } catch (e) {
      if (request === latestRequest) {
        toProvide.value = null
        error.value = e instanceof Error ? e.message : 'Failed to read the T2 blueprint copies to provide'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { toProvide, loading, error, fetchToProvide }
})

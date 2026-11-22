import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { PlacedAction } from './types'

const ALL_PROJECTS_PATH = '/industry/place-actions'

function projectPath(projectId: string): string {
  return `/industry/projects/${projectId}/place-actions`
}

export const useGroupPlaceActionsStore = defineStore('group-place-actions', () => {
  const actions = ref<PlacedAction[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function load(path: string): Promise<void> {
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const read = await apiRequest<PlacedAction[]>(path)
      if (request === latestRequest) {
        actions.value = read
      }
    } catch (e) {
      if (request === latestRequest) {
        actions.value = []
        error.value = e instanceof Error ? e.message : 'Failed to fetch the actions'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  async function fetchAll(): Promise<void> {
    await load(ALL_PROJECTS_PATH)
  }

  async function fetchOfProject(projectId: string): Promise<void> {
    await load(projectPath(projectId))
  }

  return { actions, loading, error, fetchAll, fetchOfProject }
})

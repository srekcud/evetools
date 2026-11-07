import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectReactionSteps, ReactionStep } from './types'

export const useGroupReactionStepsStore = defineStore('group-reaction-steps', () => {
  const steps = ref<ReactionStep[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchReactionSteps(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      steps.value = []
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const reactions = await apiRequest<ProjectReactionSteps>(`/industry/projects/${projectId}/reactions`)
      if (request === latestRequest) {
        steps.value = reactions.steps
      }
    } catch (e) {
      if (request === latestRequest) {
        steps.value = []
        error.value = e instanceof Error ? e.message : 'Failed to fetch reaction steps'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { steps, loading, error, fetchReactionSteps }
})

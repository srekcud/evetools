import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { ReactionStep } from './types'

export const useGroupReactionStepsStore = defineStore('group-reaction-steps', () => {
  const steps = ref<ReactionStep[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchReactionSteps(projectId: string): Promise<void> {
    void projectId
  }

  return { steps, loading, error, fetchReactionSteps }
})

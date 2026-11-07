import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { ProjectPlanning } from './types'

export const useGroupPlanningStore = defineStore('group-planning', () => {
  const planning = ref<ProjectPlanning | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchPlanning(_projectId: string): Promise<void> {
  }

  return { planning, loading, error, fetchPlanning }
})

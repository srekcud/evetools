import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectActivity, ProjectActivityEvent, ProjectRunningJob } from './types'

export const useGroupActivityStore = defineStore('group-activity', () => {
  const events = ref<ProjectActivityEvent[]>([])
  const runningJobs = ref<ProjectRunningJob[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchActivity(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      events.value = []
      runningJobs.value = []
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const activity = await apiRequest<ProjectActivity>(`/industry/projects/${projectId}/activity`)
      if (request === latestRequest) {
        events.value = activity.events
        runningJobs.value = activity.runningJobs
      }
    } catch (e) {
      if (request === latestRequest) {
        events.value = []
        runningJobs.value = []
        error.value = e instanceof Error ? e.message : 'Failed to read the project activity'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { events, runningJobs, loading, error, fetchActivity }
})

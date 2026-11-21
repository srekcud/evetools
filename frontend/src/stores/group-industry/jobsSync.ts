import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectJobsSync } from './types'

export const useGroupJobsSyncStore = defineStore('group-jobs-sync', () => {
  const jobsSync = ref<ProjectJobsSync | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchJobsSync(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      jobsSync.value = null
    }
    const request = ++latestRequest
    try {
      const read = await apiRequest<ProjectJobsSync | undefined>(`/industry/projects/${projectId}/jobs-sync`)
      if (request === latestRequest) {
        jobsSync.value = Array.isArray(read?.installers) ? read : null
      }
    } catch {
      if (request === latestRequest) {
        jobsSync.value = null
      }
    }
  }

  return { jobsSync, fetchJobsSync }
})

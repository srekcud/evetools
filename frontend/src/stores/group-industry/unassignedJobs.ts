import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { apiRequest } from '@/services/api'
import { useRefetchOnReconnect } from '@/composables/useRefetchOnReconnect'
import { useSyncStore } from '@/stores/sync'
import type { UnassignedJob } from './types'

const UNASSIGNED_JOBS_PATH = '/industry/unassigned-jobs'
const INDUSTRY_JOBS_SYNC = 'industry-jobs'

export const useUnassignedJobsStore = defineStore('group-unassigned-jobs', () => {
  const jobs = ref<UnassignedJob[]>([])
  const error = ref<string | null>(null)
  const isLoading = ref(false)
  const count = computed(() => jobs.value.length)

  function reportFailure(failure: unknown, fallbackMessage: string): void {
    error.value = failure instanceof Error ? failure.message : fallbackMessage
  }

  async function fetchJobs(): Promise<void> {
    isLoading.value = true
    try {
      jobs.value = await apiRequest<UnassignedJob[]>(UNASSIGNED_JOBS_PATH)
    } catch (failure) {
      reportFailure(failure, 'Failed to fetch the jobs to assign')
    } finally {
      isLoading.value = false
    }
  }

  async function load(): Promise<void> {
    error.value = null
    await fetchJobs()
  }

  async function settle(esiJobId: number, action: string, request: RequestInit, fallbackMessage: string): Promise<void> {
    error.value = null
    isLoading.value = true
    try {
      await apiRequest(`${UNASSIGNED_JOBS_PATH}/${esiJobId}/${action}`, request)
    } catch (failure) {
      reportFailure(failure, fallbackMessage)
    }
    await fetchJobs()
  }

  async function assign(esiJobId: number, lineId: string): Promise<void> {
    await settle(esiJobId, 'assign', { method: 'POST', body: JSON.stringify({ lineId }) }, 'Failed to assign the job')
  }

  async function dismiss(esiJobId: number): Promise<void> {
    await settle(esiJobId, 'dismiss', { method: 'POST' }, 'Failed to dismiss the job')
  }

  const syncStore = useSyncStore()
  watch(() => syncStore.getSyncProgress(INDUSTRY_JOBS_SYNC), (progress) => {
    if (progress?.status === 'completed') void load()
  })

  useRefetchOnReconnect(load)

  return { jobs, count, error, isLoading, load, assign, dismiss }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectCourier } from './types'

function courierPath(projectId: string): string {
  return `/industry/projects/${projectId}/courier`
}

function linkPath(projectId: string): string {
  return `/industry/projects/${projectId}/courier-contract`
}

export const useGroupCourierStore = defineStore('group-courier', () => {
  const courier = ref<ProjectCourier | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchCourier(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      courier.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const read = await apiRequest<ProjectCourier>(courierPath(projectId))
      if (request === latestRequest) {
        courier.value = read
      }
    } catch (e) {
      if (request === latestRequest) {
        courier.value = null
        error.value = e instanceof Error ? e.message : 'Failed to fetch courier contract'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  async function changeLink(projectId: string, options: RequestInit, failure: string): Promise<void> {
    saving.value = true
    error.value = null
    try {
      await apiRequest<void>(linkPath(projectId), options)
    } catch (e) {
      error.value = e instanceof Error ? e.message : failure
      return
    } finally {
      saving.value = false
    }
    await fetchCourier(projectId)
  }

  async function linkContract(projectId: string, contractId: number): Promise<void> {
    await changeLink(projectId, { method: 'POST', body: JSON.stringify({ contractId }) }, 'Failed to link courier contract')
  }

  async function unlinkContract(projectId: string): Promise<void> {
    await changeLink(projectId, { method: 'DELETE' }, 'Failed to unlink courier contract')
  }

  return { courier, loading, saving, error, fetchCourier, linkContract, unlinkContract }
})

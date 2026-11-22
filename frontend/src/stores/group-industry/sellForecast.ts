import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { SellForecast } from './types'

export const useGroupSellForecastStore = defineStore('group-sell-forecast', () => {
  const forecast = ref<SellForecast | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0
  let pendingRead: { projectId: string; read: Promise<void> } | null = null

  function fetchForProject(projectId: string): Promise<void> {
    if (pendingRead !== null && pendingRead.projectId === projectId) return pendingRead.read
    const read = readForecast(projectId).finally(() => {
      if (pendingRead?.read === read) pendingRead = null
    })
    pendingRead = { projectId, read }
    return read
  }

  async function readForecast(projectId: string): Promise<void> {
    const request = ++latestRequest
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      forecast.value = null
    }
    loading.value = true
    error.value = null
    try {
      const fetched = await apiRequest<SellForecast>(`/industry/projects/${projectId}/sell-forecast`)
      if (typeof fetched !== 'object' || fetched === null || Array.isArray(fetched) || typeof fetched.totals !== 'object' || fetched.totals === null) {
        throw new Error('Unexpected sell forecast response')
      }
      if (request !== latestRequest) return
      forecast.value = fetched
    } catch (e) {
      if (request !== latestRequest) return
      forecast.value = null
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }

  return { forecast, loading, error, fetchForProject }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { FactoryProjectStockLine, FactoryStation, PersonalStockContainer } from './types'

const STATIONS_ENDPOINT = '/industry/factory/stations'
const PROJECT_STOCK_ENDPOINT = '/industry/factory/project-stock'
const PERSONAL_CONTAINERS_ENDPOINT = '/industry/settings/personal-stock-containers'

function messageOf(error: unknown, fallback: string): string {
  return error instanceof Error ? error.message : fallback
}

export const useFactoryStore = defineStore('industry-factory', () => {
  const stations = ref<FactoryStation[] | null>(null)
  const projectStock = ref<FactoryProjectStockLine[] | null>(null)
  const personalContainers = ref<PersonalStockContainer[] | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const declareError = ref<string | null>(null)

  async function fetchStations(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      stations.value = await apiRequest<FactoryStation[]>(STATIONS_ENDPOINT)
    } catch (e) {
      error.value = messageOf(e, 'Failed to fetch the stations')
    } finally {
      loading.value = false
    }
  }

  async function fetchProjectStock(): Promise<void> {
    error.value = null
    try {
      projectStock.value = await apiRequest<FactoryProjectStockLine[]>(PROJECT_STOCK_ENDPOINT)
    } catch (e) {
      error.value = messageOf(e, 'Failed to fetch the project stock')
    }
  }

  async function fetchPersonalContainers(): Promise<void> {
    error.value = null
    try {
      personalContainers.value = await apiRequest<PersonalStockContainer[]>(PERSONAL_CONTAINERS_ENDPOINT)
    } catch (e) {
      error.value = messageOf(e, 'Failed to fetch the personal stock containers')
    }
  }

  async function declareContainer(itemId: number): Promise<boolean> {
    declareError.value = null
    try {
      await apiRequest(PERSONAL_CONTAINERS_ENDPOINT, { method: 'POST', body: JSON.stringify({ itemId }) })
    } catch (e) {
      declareError.value = messageOf(e, 'Failed to declare the container')
      return false
    }
    await fetchPersonalContainers()
    return true
  }

  async function removeContainer(id: string): Promise<void> {
    error.value = null
    try {
      await apiRequest<null>(`${PERSONAL_CONTAINERS_ENDPOINT}/${id}`, { method: 'DELETE' })
    } catch (e) {
      error.value = messageOf(e, 'Failed to remove the container')
      return
    }
    await fetchPersonalContainers()
  }

  return {
    stations, projectStock, personalContainers, loading, error, declareError,
    fetchStations, fetchProjectStock, fetchPersonalContainers, declareContainer, removeContainer,
  }
})

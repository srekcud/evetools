import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectInvention, ProjectInventionProduct } from './types'

export const useGroupInventionStore = defineStore('group-invention', () => {
  const products = ref<ProjectInventionProduct[] | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchInvention(projectId: string, inventorCharacterId: string | null): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      products.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    const query = inventorCharacterId === null ? '' : `?inventorCharacterId=${encodeURIComponent(inventorCharacterId)}`
    try {
      const invention = await apiRequest<ProjectInvention>(`/industry/projects/${projectId}/invention${query}`)
      if (request === latestRequest) {
        products.value = invention.products
      }
    } catch (e) {
      if (request === latestRequest) {
        products.value = null
        error.value = e instanceof Error ? e.message : 'Failed to fetch the invention comparison'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { products, loading, error, fetchInvention }
})

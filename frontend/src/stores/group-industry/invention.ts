import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectInvention, ProjectInventionProduct } from './types'

export const useGroupInventionStore = defineStore('group-invention', () => {
  const products = ref<ProjectInventionProduct[] | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  const applying = ref(false)
  const applyError = ref<string | null>(null)
  let inventorCharacterIdInView: string | null = null
  let latestRequest = 0

  async function fetchInvention(projectId: string, inventorCharacterId: string | null): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      products.value = null
      applyError.value = null
    }
    inventorCharacterIdInView = inventorCharacterId
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

  async function applyDecryptor(projectId: string, productTypeId: number, decryptorTypeId: number | null): Promise<boolean> {
    applying.value = true
    applyError.value = null
    try {
      await apiRequest(`/industry/projects/${projectId}`, {
        method: 'PATCH',
        body: JSON.stringify({ inventionDecryptors: { [productTypeId]: decryptorTypeId } }),
      })
    } catch (e) {
      applyError.value = e instanceof Error ? e.message : 'Failed to apply the decryptor'
      return false
    } finally {
      applying.value = false
    }
    await fetchInvention(projectId, inventorCharacterIdInView)
    return true
  }

  return { products, loading, error, applying, applyError, fetchInvention, applyDecryptor }
})

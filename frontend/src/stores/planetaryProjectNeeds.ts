import { defineStore } from 'pinia'
import { ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'

export interface ProjectNeedProject {
  projectId: string
  projectName: string | null
  remaining: number
}

export interface ProjectNeedProduct {
  typeId: number
  typeName: string | null
  required: number
  stock: number | null
  missing: number | null
  projects: ProjectNeedProject[]
}

interface PlanetaryProjectNeedsResponse {
  products: ProjectNeedProduct[]
}

const PROJECT_NEEDS_ENDPOINT = '/planetary/project-needs'

export const usePlanetaryProjectNeedsStore = defineStore('planetaryProjectNeeds', () => {
  const products = ref<ProjectNeedProduct[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function load(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await apiRequest<PlanetaryProjectNeedsResponse>(PROJECT_NEEDS_ENDPOINT)
      products.value = response.products
    } catch {
      products.value = []
      error.value = i18n.global.t('pi.stock.projectNeeds.loadError')
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return { products, isLoading, error, load, clearError }
})

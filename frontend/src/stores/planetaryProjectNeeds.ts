import { defineStore } from 'pinia'
import { ref } from 'vue'

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

export const usePlanetaryProjectNeedsStore = defineStore('planetaryProjectNeeds', () => {
  const products = ref<ProjectNeedProduct[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function load(): Promise<void> {
    return undefined
  }

  function clearError(): void {
    error.value = null
  }

  return { products, isLoading, error, load, clearError }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import type { ColonyLayoutSummary } from '@/stores/planetaryLayouts'

export interface LibraryLayout {
  id: string
  name: string
  summary: ColonyLayoutSummary
  gameTemplate: Record<string, unknown>
  publishedAt: string
  mine: boolean
}

const LIBRARY_ENDPOINT = '/planetary/layout-library'

export const usePlanetaryLayoutLibraryStore = defineStore('planetaryLayoutLibrary', () => {
  const entries = ref<LibraryLayout[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function load(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await apiRequest<LibraryLayout[] | null>(LIBRARY_ENDPOINT)
      entries.value = Array.isArray(response) ? response : []
    } catch {
      error.value = i18n.global.t('pi.layouts.library.loadError')
    } finally {
      isLoading.value = false
    }
  }

  return { entries, isLoading, error, load }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { ColonyLayoutSummary } from '@/stores/planetaryLayouts'

export interface LibraryLayout {
  id: string
  name: string
  summary: ColonyLayoutSummary
  gameTemplate: Record<string, unknown>
  publishedAt: string
  mine: boolean
}

export const usePlanetaryLayoutLibraryStore = defineStore('planetaryLayoutLibrary', () => {
  const entries = ref<LibraryLayout[]>([])

  async function load(): Promise<void> {}

  return { entries, load }
})

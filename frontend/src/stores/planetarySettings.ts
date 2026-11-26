import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface PlanetarySettings {
  homeLocationId: number | null
  homeLocationName: string | null
  coverageWeeks: number
  hubStructureId: number
  hubStructureName: string
}

export interface HomeLocationCandidate {
  locationId: number
  locationName: string
  solarSystemName: string | null
}

export interface HubChoice {
  id: number
  name: string
}

export const MIN_COVERAGE_WEEKS = 1
export const MAX_COVERAGE_WEEKS = 52

export const usePlanetarySettingsStore = defineStore('planetarySettings', () => {
  const settings = ref<PlanetarySettings | null>(null)
  const locations = ref<HomeLocationCandidate[]>([])
  const hubChoices = ref<HubChoice[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function load(): Promise<void> {
    return undefined
  }

  async function saveHomeLocation(_locationId: number | null): Promise<boolean> {
    return false
  }

  async function saveCoverageWeeks(_weeks: number): Promise<boolean> {
    return false
  }

  async function saveHub(_structureId: number): Promise<boolean> {
    return false
  }

  function clearError(): void {
    error.value = null
  }

  return { settings, locations, hubChoices, isLoading, error, load, saveHomeLocation, saveCoverageWeeks, saveHub, clearError }
})

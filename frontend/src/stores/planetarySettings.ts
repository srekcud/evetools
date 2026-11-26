import { defineStore } from 'pinia'
import { ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'

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

interface HomeLocationCandidates {
  locations: HomeLocationCandidate[]
}

interface MarketSettings {
  preferredMarketStructureId: number | null
  preferredMarketStructureName: string | null
  marketStructures: HubChoice[]
}

export const MIN_COVERAGE_WEEKS = 1
export const MAX_COVERAGE_WEEKS = 52

const SETTINGS_ENDPOINT = '/planetary/settings'
const LOCATIONS_ENDPOINT = '/planetary/settings/locations'
const MARKET_SETTINGS_ENDPOINT = '/me/settings'

export function isValidCoverageWeeks(weeks: number): boolean {
  return Number.isInteger(weeks) && weeks >= MIN_COVERAGE_WEEKS && weeks <= MAX_COVERAGE_WEEKS
}

function hubChoicesOf(market: MarketSettings): HubChoice[] {
  if (market.marketStructures.length > 0) return market.marketStructures
  if (market.preferredMarketStructureId === null) return []

  return [{
    id: market.preferredMarketStructureId,
    name: market.preferredMarketStructureName ?? String(market.preferredMarketStructureId),
  }]
}

export const usePlanetarySettingsStore = defineStore('planetarySettings', () => {
  const settings = ref<PlanetarySettings | null>(null)
  const locations = ref<HomeLocationCandidate[]>([])
  const hubChoices = ref<HubChoice[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const saveRevision = ref(0)

  async function load(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const [loadedSettings, candidates, market] = await Promise.all([
        apiRequest<PlanetarySettings>(SETTINGS_ENDPOINT),
        apiRequest<HomeLocationCandidates>(LOCATIONS_ENDPOINT),
        apiRequest<MarketSettings>(MARKET_SETTINGS_ENDPOINT),
      ])
      settings.value = loadedSettings
      locations.value = candidates.locations
      hubChoices.value = hubChoicesOf(market)
    } catch {
      settings.value = null
      locations.value = []
      hubChoices.value = []
      error.value = i18n.global.t('pi.stock.settings.loadError')
    } finally {
      isLoading.value = false
    }
  }

  async function patchSettings(patch: Partial<Pick<PlanetarySettings, 'homeLocationId' | 'coverageWeeks'>>): Promise<boolean> {
    error.value = null

    try {
      settings.value = await apiRequest<PlanetarySettings>(SETTINGS_ENDPOINT, {
        method: 'PATCH',
        body: JSON.stringify(patch),
      })
      saveRevision.value += 1
      return true
    } catch {
      error.value = i18n.global.t('pi.stock.settings.saveError')
      return false
    }
  }

  async function saveHomeLocation(locationId: number | null): Promise<boolean> {
    return patchSettings({ homeLocationId: locationId })
  }

  async function saveCoverageWeeks(weeks: number): Promise<boolean> {
    if (!isValidCoverageWeeks(weeks)) return false

    return patchSettings({ coverageWeeks: weeks })
  }

  async function saveHub(structureId: number): Promise<boolean> {
    error.value = null

    const chosen = hubChoices.value.find(choice => choice.id === structureId)
      ?? (settings.value?.hubStructureId === structureId ? { id: structureId, name: settings.value.hubStructureName } : undefined)
    if (chosen === undefined) {
      error.value = i18n.global.t('pi.stock.settings.saveError')
      return false
    }

    try {
      await apiRequest<MarketSettings>(MARKET_SETTINGS_ENDPOINT, {
        method: 'PATCH',
        body: JSON.stringify({ preferredMarketStructureId: chosen.id, preferredMarketStructureName: chosen.name }),
      })
      saveRevision.value += 1
      settings.value = await apiRequest<PlanetarySettings>(SETTINGS_ENDPOINT)
      return true
    } catch {
      error.value = i18n.global.t('pi.stock.settings.saveError')
      return false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return { settings, locations, hubChoices, isLoading, error, saveRevision, load, saveHomeLocation, saveCoverageWeeks, saveHub, clearError }
})

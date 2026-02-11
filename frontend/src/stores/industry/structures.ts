import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type {
  StructureConfig,
  RigOptions,
  CorporationStructure,
  StructureSearchResult,
  UserSettings,
  CharacterSkill,
} from './types'

export const useStructuresStore = defineStore('industry-structures', () => {
  const structures = ref<StructureConfig[]>([])
  const rigOptions = ref<RigOptions | null>(null)
  const corporationStructures = ref<CorporationStructure[]>([])
  const userSettings = ref<UserSettings | null>(null)
  const characterSkills = ref<CharacterSkill[]>([])
  const error = ref<string | null>(null)

  async function fetchStructures() {
    try {
      const data = await apiRequest<{
        structures: StructureConfig[]
        rigOptions: RigOptions
      }>('/industry/structures')
      structures.value = data.structures
      rigOptions.value = data.rigOptions
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch structures'
    }
  }

  async function fetchCorporationStructures() {
    try {
      const data = await apiRequest<{ structures: CorporationStructure[] }>(
        '/industry/corporation-structures',
      )
      corporationStructures.value = data.structures
    } catch (e) {
      console.error('Failed to fetch corporation structures:', e)
    }
  }

  async function createStructure(data: {
    name: string
    locationId?: number | null
    solarSystemId?: number | null
    securityType: string
    structureType: string
    rigs: string[]
  }) {
    try {
      const structure = await apiRequest<StructureConfig>('/industry/structures', {
        method: 'POST',
        body: JSON.stringify({ ...data, isDefault: false }),
      })
      structures.value.push(structure)
      return structure
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to create structure'
      throw e
    }
  }

  async function updateStructure(id: string, data: Partial<StructureConfig>) {
    try {
      const updated = await apiRequest<StructureConfig>(`/industry/structures/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(data),
      })
      const idx = structures.value.findIndex(s => s.id === id)
      if (idx !== -1) {
        structures.value[idx] = updated
      }
      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update structure'
      throw e
    }
  }

  async function deleteStructure(id: string) {
    try {
      await apiRequest(`/industry/structures/${id}`, { method: 'DELETE' })
      structures.value = structures.value.filter(s => s.id !== id)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete structure'
      throw e
    }
  }

  async function searchStructures(query: string): Promise<{ structures: StructureSearchResult[], error?: string }> {
    if (query.length < 3) {
      return { structures: [] }
    }
    try {
      const data = await apiRequest<{ structures: StructureSearchResult[] }>(
        `/industry/search-structure?q=${encodeURIComponent(query)}`,
      )
      return { structures: data.structures }
    } catch (e) {
      const message = e instanceof Error ? e.message : 'Erreur de recherche'
      console.error('Failed to search structures:', e)
      return { structures: [], error: message }
    }
  }

  // User settings (favorite systems)
  async function fetchUserSettings() {
    try {
      userSettings.value = await apiRequest<UserSettings>('/industry/settings')
    } catch (e) {
      console.error('Failed to fetch user settings:', e)
    }
  }

  async function updateUserSettings(data: Partial<UserSettings>) {
    try {
      userSettings.value = await apiRequest<UserSettings>('/industry/settings', {
        method: 'PATCH',
        body: JSON.stringify(data),
      })
      return userSettings.value
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update settings'
      throw e
    }
  }

  // Character skills
  async function fetchCharacterSkills() {
    try {
      const data = await apiRequest<{ characters: CharacterSkill[] }>('/industry/character-skills')
      characterSkills.value = data.characters
    } catch (e) {
      console.error('Failed to fetch character skills:', e)
    }
  }

  async function syncCharacterSkills() {
    try {
      const data = await apiRequest<{ characters: CharacterSkill[], warning?: string }>(
        '/industry/character-skills/sync',
        { method: 'POST', body: '{}' },
      )
      characterSkills.value = data.characters
      return data.warning || null
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to sync skills'
      throw e
    }
  }

  async function updateCharacterSkill(characterId: number, data: Partial<CharacterSkill>) {
    try {
      const updated = await apiRequest<CharacterSkill>(
        `/industry/character-skills/${characterId}`,
        {
          method: 'PATCH',
          body: JSON.stringify(data),
        },
      )
      const idx = characterSkills.value.findIndex(c => c.characterId === characterId)
      if (idx !== -1) {
        characterSkills.value[idx] = updated
      }
      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update skill'
      throw e
    }
  }

  return {
    structures,
    rigOptions,
    corporationStructures,
    userSettings,
    characterSkills,
    error,
    fetchStructures,
    fetchCorporationStructures,
    createStructure,
    updateStructure,
    deleteStructure,
    searchStructures,
    fetchUserSettings,
    updateUserSettings,
    fetchCharacterSkills,
    syncCharacterSkills,
    updateCharacterSkill,
  }
})

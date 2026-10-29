import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type {
  StructureConfig,
  RigOptions,
  StructureBonusPreview,
  CorporationStructure,
  StructureSearchResult,
  UserSettings,
  CharacterSkill,
  CharacterSyncFailure,
} from './types'

export const useStructuresStore = defineStore('industry-structures', () => {
  const structures = ref<StructureConfig[]>([])
  const rigOptions = ref<RigOptions | null>(null)
  const corporationStructures = ref<CorporationStructure[]>([])
  const userSettings = ref<UserSettings | null>(null)
  const characterSkills = ref<CharacterSkill[]>([])

  async function fetchStructures() {
    const data = await apiRequest<{
      structures: StructureConfig[]
      rigOptions: RigOptions
    }>('/industry/structures')
    structures.value = data.structures
    rigOptions.value = data.rigOptions
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
    facilityTaxRate: number | null
  }) {
    const structure = await apiRequest<StructureConfig>('/industry/structures', {
      method: 'POST',
      body: JSON.stringify({ ...data, isDefault: false }),
    })
    structures.value.push(structure)
    return structure
  }

  async function updateStructure(id: string, data: Partial<StructureConfig>) {
    const updated = await apiRequest<StructureConfig>(`/industry/structures/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
    const idx = structures.value.findIndex(s => s.id === id)
    if (idx !== -1) {
      structures.value[idx] = updated
    }
    return updated
  }

  async function deleteStructure(id: string) {
    await apiRequest(`/industry/structures/${id}`, { method: 'DELETE' })
    structures.value = structures.value.filter(s => s.id !== id)
  }

  async function searchStructures(query: string): Promise<{ structures: StructureSearchResult[], incomplete?: boolean, error?: string }> {
    if (query.length < 3) {
      return { structures: [] }
    }
    try {
      const data = await apiRequest<{ structures: StructureSearchResult[], incomplete: boolean }>(
        `/industry/search-structure?q=${encodeURIComponent(query)}`,
      )
      return { structures: data.structures, incomplete: data.incomplete }
    } catch (e) {
      const message = e instanceof Error ? e.message : 'Erreur de recherche'
      console.error('Failed to search structures:', e)
      return { structures: [], error: message }
    }
  }

  async function previewStructureBonuses(data: {
    securityType: string
    structureType: string
    rigs: string[]
  }): Promise<StructureBonusPreview> {
    return apiRequest<StructureBonusPreview>('/industry/structures/bonus-preview', {
      method: 'POST',
      body: JSON.stringify(data),
    })
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
    userSettings.value = await apiRequest<UserSettings>('/industry/settings', {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
    return userSettings.value
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
    const data = await apiRequest<{ characters: CharacterSkill[], failedCharacters: CharacterSyncFailure[] }>(
      '/industry/character-skills/sync',
      { method: 'POST', body: '{}' },
    )
    characterSkills.value = data.characters
    return data.failedCharacters
  }

  async function updateCharacterSkill(characterId: number, data: Partial<CharacterSkill>) {
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
  }

  return {
    structures,
    rigOptions,
    corporationStructures,
    userSettings,
    characterSkills,
    fetchStructures,
    fetchCorporationStructures,
    createStructure,
    updateStructure,
    deleteStructure,
    searchStructures,
    previewStructureBonuses,
    fetchUserSettings,
    updateUserSettings,
    fetchCharacterSkills,
    syncCharacterSkills,
    updateCharacterSkill,
  }
})

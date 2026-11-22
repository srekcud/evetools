import { ref, type Ref } from 'vue'
import { apiRequest } from '@/services/api'

export type ActiveProjectCharacter = { id: string; eveCharacterId: number; name: string; activeProjectId: string | null; activeProjectName: string | null }
export type CharacterActiveProjectUpdate = { id: string; activeProjectId: string | null; activeProjectName: string | null }

function isCharacterList(value: unknown): value is ActiveProjectCharacter[] {
  return Array.isArray(value) && value.every(candidate =>
    typeof candidate === 'object' && candidate !== null
    && typeof (candidate as ActiveProjectCharacter).id === 'string'
    && typeof (candidate as ActiveProjectCharacter).eveCharacterId === 'number'
    && typeof (candidate as ActiveProjectCharacter).name === 'string')
}

export function useCharacterActiveProjects(): {
  characters: Ref<ActiveProjectCharacter[]>
  loadFailed: Ref<boolean>
  load: () => Promise<void>
  dedicate: (characterId: string, projectId: string | null) => Promise<CharacterActiveProjectUpdate>
  applyUpdate: (update: CharacterActiveProjectUpdate) => void
} {
  const characters = ref<ActiveProjectCharacter[]>([])
  const loadFailed = ref(false)

  async function load(): Promise<void> {
    loadFailed.value = false
    try {
      const loaded: unknown = await apiRequest<ActiveProjectCharacter[]>('/me/characters')
      if (!isCharacterList(loaded)) throw new Error('Unexpected characters')
      characters.value = loaded.map(character => ({
        ...character,
        activeProjectId: character.activeProjectId ?? null,
        activeProjectName: character.activeProjectName ?? null,
      }))
    } catch {
      loadFailed.value = true
    }
  }

  async function dedicate(characterId: string, projectId: string | null): Promise<CharacterActiveProjectUpdate> {
    const updated = await apiRequest<CharacterActiveProjectUpdate>(`/me/characters/${characterId}/active-project`, {
      method: 'PATCH',
      body: JSON.stringify({ projectId }),
    })
    applyUpdate(updated)
    return updated
  }

  function applyUpdate(update: CharacterActiveProjectUpdate): void {
    characters.value = characters.value.map(character => character.id === update.id
      ? { ...character, activeProjectId: update.activeProjectId, activeProjectName: update.activeProjectName }
      : character)
  }

  return { characters, loadFailed, load, dedicate, applyUpdate }
}

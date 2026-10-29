import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useStructuresStore } from '@/stores/industry/structures'
import type { CharacterSkill, CharacterSyncFailure } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SYNCED_SKILLS: CharacterSkill[] = [
  { characterId: 2112000001, characterName: 'Pilot One', industry: 5, advancedIndustry: 4, reactions: 2, source: 'esi', lastSyncAt: '2026-10-29T09:15:00+00:00' },
  { characterId: 2112000002, characterName: 'Pilot Two', industry: 3, advancedIndustry: 0, reactions: 1, source: 'manual', lastSyncAt: null },
]

const FAILED_CHARACTERS: CharacterSyncFailure[] = [
  { characterName: 'Pilot Two', reason: 'missing_scope', statusCode: null },
]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('structures store character skills sync', () => {
  it('returns the failed characters and keeps the skills of every character', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ characters: SYNCED_SKILLS, syncedCount: 1, failedCharacters: FAILED_CHARACTERS })

    const store = useStructuresStore()
    const failedCharacters = await store.syncCharacterSkills()

    expect(failedCharacters).toEqual(FAILED_CHARACTERS)
    expect(store.characterSkills).toEqual(SYNCED_SKILLS)
    expect(vi.mocked(apiRequest).mock.calls).toEqual([['/industry/character-skills/sync', { method: 'POST', body: '{}' }]])
  })

  it('returns no failed character when every character was synced', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ characters: SYNCED_SKILLS, syncedCount: 2, failedCharacters: [] })

    expect(await useStructuresStore().syncCharacterSkills()).toEqual([])
  })

  it('rethrows the error when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Service unavailable'))

    await expect(useStructuresStore().syncCharacterSkills()).rejects.toThrow('Service unavailable')
  })
})

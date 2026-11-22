import { useCharacterActiveProjects } from '@/composables/useCharacterActiveProjects'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const CHARACTERS_ENDPOINT = '/me/characters'
const ARIA = { id: 'uuid-aria', eveCharacterId: 2_112_000_001, name: 'Aria Solberg', activeProjectId: 'project-orca', activeProjectName: 'Orca batch' }
const BRIN = { id: 'uuid-brin', eveCharacterId: 2_112_000_002, name: 'Brin Solberg', activeProjectId: null, activeProjectName: null }
const BRIN_ENDPOINT = `/me/characters/${BRIN.id}/active-project`

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('useCharacterActiveProjects', () => {
  it('loads the characters of the user with their active project', async () => {
    vi.mocked(apiRequest).mockImplementation(async endpoint => {
      if (endpoint === CHARACTERS_ENDPOINT) return [ARIA, BRIN]
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const { characters, loadFailed, load } = useCharacterActiveProjects()

    await load()

    expect(characters.value.map(character => [character.name, character.activeProjectId, character.activeProjectName])).toEqual([
      ['Aria Solberg', 'project-orca', 'Orca batch'],
      ['Brin Solberg', null, null],
    ])
    expect(loadFailed.value).toBe(false)
  })

  it('patches the active project of one character and updates only its entry from the answer', async () => {
    const updated = { id: BRIN.id, activeProjectId: 'project-hecate', activeProjectName: 'Hecate batch' }
    vi.mocked(apiRequest).mockImplementation(async endpoint => {
      if (endpoint === CHARACTERS_ENDPOINT) return [ARIA, BRIN]
      if (endpoint === BRIN_ENDPOINT) return updated
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const { characters, load, dedicate } = useCharacterActiveProjects()
    await load()

    const answer = await dedicate(BRIN.id, 'project-hecate')

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(BRIN_ENDPOINT, expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ projectId: 'project-hecate' }) }))
    expect(answer).toEqual(updated)
    expect(characters.value.map(character => [character.name, character.activeProjectId, character.activeProjectName])).toEqual([
      ['Aria Solberg', 'project-orca', 'Orca batch'],
      ['Brin Solberg', 'project-hecate', 'Hecate batch'],
    ])
  })
})

import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { CharacterSyncFailure } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-197'

const FAILED_CHARACTERS: CharacterSyncFailure[] = [
  { characterName: 'Pilot One', reason: 'esi_unavailable', statusCode: 503 },
  { characterName: 'Pilot Two', reason: 'esi_error', statusCode: 401 },
  { characterName: 'Pilot Three', reason: 'missing_token', statusCode: null },
]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('projects store job matching', () => {
  it('returns the failed characters of the job sync', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ steps: [], jobsCost: 0, syncedCharacters: 0, failedCharacters: FAILED_CHARACTERS })

    const failedCharacters = await useProjectsStore().matchJobs(PROJECT_ID)

    expect(failedCharacters).toEqual(FAILED_CHARACTERS)
    expect(vi.mocked(apiRequest).mock.calls).toEqual([[`/industry/projects/${PROJECT_ID}/match-jobs`, { method: 'POST', body: '{}' }]])
  })

  it('returns no failed character when every character was synced', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ steps: [], jobsCost: 1250000, syncedCharacters: 3, failedCharacters: [] })

    expect(await useProjectsStore().matchJobs(PROJECT_ID)).toEqual([])
  })

  it('returns no failed character and keeps the error when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Bad gateway'))

    const store = useProjectsStore()
    const failedCharacters = await store.matchJobs(PROJECT_ID)

    expect(failedCharacters).toEqual([])
    expect(store.error).toBe('Bad gateway')
  })
})

import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MAUNDER_TYPE_ID = 12023
const MAUNDER_RUNS = 4
const MAUNDER_ME = 6
const MAUNDER_TE = 12
const MAX_JOB_DURATION_DAYS = 2
const MINIMUM_OUTSIDE_SAVING = 250000

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue({ id: 'maunder-project' })
})

describe('projects store project creation outside the favorite system', () => {
  it('posts the acceptance of a structure outside the favorite system and the minimum saving with the project', async () => {
    await useProjectsStore().createProject(
      MAUNDER_TYPE_ID,
      MAUNDER_RUNS,
      MAUNDER_ME,
      MAUNDER_TE,
      MAX_JOB_DURATION_DAYS,
      null,
      [],
      true,
      MINIMUM_OUTSIDE_SAVING,
    )

    const [path, options] = vi.mocked(apiRequest).mock.calls[0]
    expect(path).toBe('/industry/projects')
    expect(JSON.parse(String(options?.body))).toEqual({
      typeId: MAUNDER_TYPE_ID,
      runs: MAUNDER_RUNS,
      meLevel: MAUNDER_ME,
      teLevel: MAUNDER_TE,
      maxJobDurationDays: MAX_JOB_DURATION_DAYS,
      name: null,
      excludedTypeIds: [],
      acceptsOutsideFavoriteSystem: true,
      minimumOutsideSaving: MINIMUM_OUTSIDE_SAVING,
    })
  })
})

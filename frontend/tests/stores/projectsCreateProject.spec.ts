import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const WIDOW_TYPE_ID = 22436
const WIDOW_RUNS = 10
const INVENTED_BLUEPRINT_ME = 2
const INVENTED_BLUEPRINT_TE = 4
const MAX_JOB_DURATION_DAYS = 2
const SUSTAINED_SHIELD_EMITTER_TYPE_ID = 11558
const TITANIUM_CARBIDE_TYPE_ID = 16671
const RIFTER_TYPE_ID = 587
const RIFTER_RUNS = 3

function bodyOfTheProjectCreation(): unknown {
  expect(apiRequest).toHaveBeenCalledTimes(1)
  const [path, options] = vi.mocked(apiRequest).mock.calls[0]
  expect(path).toBe('/industry/projects')
  expect(options?.method).toBe('POST')
  return JSON.parse(String(options?.body))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue({ id: 'widow-project' })
})

describe('projects store project creation', () => {
  it('posts the excluded components with the project', async () => {
    await useProjectsStore().createProject(
      WIDOW_TYPE_ID,
      WIDOW_RUNS,
      INVENTED_BLUEPRINT_ME,
      INVENTED_BLUEPRINT_TE,
      MAX_JOB_DURATION_DAYS,
      null,
      [SUSTAINED_SHIELD_EMITTER_TYPE_ID, TITANIUM_CARBIDE_TYPE_ID],
    )

    expect(bodyOfTheProjectCreation()).toEqual({
      typeId: WIDOW_TYPE_ID,
      runs: WIDOW_RUNS,
      meLevel: INVENTED_BLUEPRINT_ME,
      teLevel: INVENTED_BLUEPRINT_TE,
      maxJobDurationDays: MAX_JOB_DURATION_DAYS,
      name: null,
      excludedTypeIds: [SUSTAINED_SHIELD_EMITTER_TYPE_ID, TITANIUM_CARBIDE_TYPE_ID],
    })
  })

  it('posts no excluded component when none is given', async () => {
    await useProjectsStore().createProject(RIFTER_TYPE_ID, RIFTER_RUNS, 0, 0, MAX_JOB_DURATION_DAYS, null)

    expect(bodyOfTheProjectCreation()).toEqual({
      typeId: RIFTER_TYPE_ID,
      runs: RIFTER_RUNS,
      meLevel: 0,
      teLevel: 0,
      maxJobDurationDays: MAX_JOB_DURATION_DAYS,
      name: null,
      excludedTypeIds: [],
    })
  })
})

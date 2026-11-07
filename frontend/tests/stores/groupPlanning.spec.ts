import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupPlanningStore } from '@/stores/group-industry/planning'
import { deferred } from '../deferred'
import { LATE_PLANNING, PLANNING_PATH, PLANNING_PROJECT_ID, projectPlanning } from '../projectPlanningData'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const DRAKE_PROJECT_ID = 'project-drake-batch'
const DRAKE_PLANNING = projectPlanning({ id: DRAKE_PROJECT_ID, plannedEnd: '2026-11-09T18:00:00+00:00' })

function planningPath(projectId: string): string {
  return `/industry/projects/${projectId}/planning`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group planning store', () => {
  it('reads the planning of the project and exposes it while loading is true only during the request', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupPlanningStore()

    void store.fetchPlanning(PLANNING_PROJECT_ID)
    const whileLoading = { loading: store.loading, planning: store.planning }
    pending.resolve(LATE_PLANNING)
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(PLANNING_PATH)
    expect(whileLoading).toEqual({ loading: true, planning: null })
    expect({ loading: store.loading, error: store.error, planning: store.planning }).toEqual({
      loading: false,
      error: null,
      planning: LATE_PLANNING,
    })
  })

  it('exposes the API error and no planning when the request fails', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(LATE_PLANNING)
    const store = useGroupPlanningStore()
    await store.fetchPlanning(PLANNING_PROJECT_ID)
    vi.mocked(apiRequest).mockRejectedValue(new Error('Planning unavailable: project not found'))

    await store.fetchPlanning(PLANNING_PROJECT_ID)

    expect({ loading: store.loading, error: store.error, planning: store.planning }).toEqual({
      loading: false,
      error: 'Planning unavailable: project not found',
      planning: null,
    })
  })

  it('empties the planning of the previous project as soon as another project is read', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(LATE_PLANNING)
    const store = useGroupPlanningStore()
    await store.fetchPlanning(PLANNING_PROJECT_ID)
    vi.mocked(apiRequest).mockReturnValue(deferred<unknown>().promise)

    void store.fetchPlanning(DRAKE_PROJECT_ID)

    expect({ loading: store.loading, error: store.error, planning: store.planning }).toEqual({
      loading: true,
      error: null,
      planning: null,
    })
  })

  it('ignores the late answer of a request of the previous project once another project is read', async () => {
    const staleAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === planningPath(PLANNING_PROJECT_ID)) return staleAnswer.promise
      return DRAKE_PLANNING
    })
    const store = useGroupPlanningStore()

    const staleRead = store.fetchPlanning(PLANNING_PROJECT_ID)
    await store.fetchPlanning(DRAKE_PROJECT_ID)
    staleAnswer.resolve(LATE_PLANNING)
    await staleRead

    expect({ loading: store.loading, error: store.error, planning: store.planning }).toEqual({
      loading: false,
      error: null,
      planning: DRAKE_PLANNING,
    })
  })
})

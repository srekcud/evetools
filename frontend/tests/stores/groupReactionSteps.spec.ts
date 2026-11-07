import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupReactionStepsStore } from '@/stores/group-industry/reactionSteps'
import { deferred } from '../deferred'
import { CAESARIUM_CADMIDE, FULLERIDES, HYPERSYNAPTIC_FIBERS } from '../reactionStepFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FULLERIDES_BATCH_ID = 'project-fullerides-batch'

function reactionsPath(projectId: string): string {
  return `/industry/projects/${projectId}/reactions`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group reaction steps store', () => {
  it('reads the reaction steps of the project and exposes them while loading is true only during the request', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupReactionStepsStore()

    void store.fetchReactionSteps(FULLERIDES_BATCH_ID)
    const whileLoading = { loading: store.loading, steps: store.steps }
    pending.resolve({ id: FULLERIDES_BATCH_ID, steps: [FULLERIDES, HYPERSYNAPTIC_FIBERS] })
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(reactionsPath(FULLERIDES_BATCH_ID))
    expect(whileLoading).toEqual({ loading: true, steps: [] })
    expect({ loading: store.loading, error: store.error, steps: store.steps }).toEqual({
      loading: false,
      error: null,
      steps: [FULLERIDES, HYPERSYNAPTIC_FIBERS],
    })
  })

  it('keeps the steps of the latest read when an older read answers after it', async () => {
    const olderRead = deferred<unknown>()
    const latestRead = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(olderRead.promise).mockReturnValueOnce(latestRead.promise)
    const store = useGroupReactionStepsStore()

    void store.fetchReactionSteps(FULLERIDES_BATCH_ID)
    void store.fetchReactionSteps(FULLERIDES_BATCH_ID)
    latestRead.resolve({ id: FULLERIDES_BATCH_ID, steps: [HYPERSYNAPTIC_FIBERS] })
    await flushPromises()
    olderRead.resolve({ id: FULLERIDES_BATCH_ID, steps: [FULLERIDES, CAESARIUM_CADMIDE] })
    await flushPromises()

    expect({ loading: store.loading, error: store.error, steps: store.steps }).toEqual({
      loading: false,
      error: null,
      steps: [HYPERSYNAPTIC_FIBERS],
    })
  })

  it('exposes the API error and no step instead of the previous steps when the request fails', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce({ id: FULLERIDES_BATCH_ID, steps: [FULLERIDES] })
    const store = useGroupReactionStepsStore()
    await store.fetchReactionSteps(FULLERIDES_BATCH_ID)
    vi.mocked(apiRequest).mockRejectedValue(new Error('Reaction steps unavailable: project not found'))

    await store.fetchReactionSteps(FULLERIDES_BATCH_ID)

    expect({ loading: store.loading, error: store.error, steps: store.steps }).toEqual({
      loading: false,
      error: 'Reaction steps unavailable: project not found',
      steps: [],
    })
  })
})

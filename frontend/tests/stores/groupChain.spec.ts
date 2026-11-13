import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useGroupChainStore } from '@/stores/group-industry/chain'
import { deferred } from '../deferred'
import { chainNode, HAMMERHEAD_I, HAMMERHEAD_I_ID, HAMMERHEAD_II, SALE, TRITANIUM } from '../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HAMMERHEAD_BATCH_ID = 'project-hammerhead-batch'
const DRAKE_BATCH_ID = 'project-drake-batch'

function chainPath(projectId: string): string {
  return `/industry/projects/${projectId}/chain`
}

function markPath(projectId: string, lineId: string): string {
  return `/industry/projects/${projectId}/bom/${lineId}/mark`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group chain store', () => {
  it('reads the chain of the project and exposes its nodes while loading is true only during the request', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupChainStore()

    void store.fetchChain(HAMMERHEAD_BATCH_ID)
    const whileLoading = { loading: store.loading, nodes: store.nodes }
    pending.resolve({ id: HAMMERHEAD_BATCH_ID, nodes: [HAMMERHEAD_I, HAMMERHEAD_II, SALE] })
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(chainPath(HAMMERHEAD_BATCH_ID))
    expect(whileLoading).toEqual({ loading: true, nodes: [] })
    expect({ loading: store.loading, error: store.error, nodes: store.nodes }).toEqual({
      loading: false,
      error: null,
      nodes: [HAMMERHEAD_I, HAMMERHEAD_II, SALE],
    })
  })

  it('keeps the level of a node and turns a level omitted by the API into an explicit null', async () => {
    const { level: _omitted, ...withoutLevel } = HAMMERHEAD_II
    vi.mocked(apiRequest).mockResolvedValue({ id: HAMMERHEAD_BATCH_ID, nodes: [HAMMERHEAD_I, withoutLevel] })
    const store = useGroupChainStore()

    await store.fetchChain(HAMMERHEAD_BATCH_ID)

    expect(store.nodes.map(node => node.level)).toEqual([1, null])
  })

  it('keeps the planned copy duration of a copy and turns one omitted by the API into an explicit null', async () => {
    const plannedCopyDuration = { seconds: 12_240, missingData: [], characterName: 'Bastien Orr' }
    const { plannedCopyDuration: _omitted, ...withoutPlannedCopyDuration } = TRITANIUM
    vi.mocked(apiRequest).mockResolvedValue({ id: HAMMERHEAD_BATCH_ID, nodes: [{ ...HAMMERHEAD_I, plannedCopyDuration }, withoutPlannedCopyDuration] })
    const store = useGroupChainStore()

    await store.fetchChain(HAMMERHEAD_BATCH_ID)

    expect(store.nodes.map(node => node.plannedCopyDuration)).toEqual([plannedCopyDuration, null])
  })

  it('keeps the step mark of a node and turns a mark omitted by the API into an explicit null', async () => {
    const mark = { state: 'done', runs: 3 }
    const { mark: _omitted, ...withoutMark } = HAMMERHEAD_II
    vi.mocked(apiRequest).mockResolvedValue({ id: HAMMERHEAD_BATCH_ID, nodes: [{ ...HAMMERHEAD_I, mark }, withoutMark] })
    const store = useGroupChainStore()

    await store.fetchChain(HAMMERHEAD_BATCH_ID)

    expect(store.nodes.map(node => node.mark)).toEqual([mark, null])
  })

  it('marks a step by posting its state and runs on the line then reads the chain again and answers true', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce({ id: HAMMERHEAD_I_ID }).mockResolvedValueOnce({
      id: HAMMERHEAD_BATCH_ID,
      nodes: [chainNode({ ...HAMMERHEAD_I, mark: { state: 'done', runs: 4 } })],
    })
    const store = useGroupChainStore()

    const marked = await store.markStep(HAMMERHEAD_BATCH_ID, HAMMERHEAD_I_ID, 'done', 4)

    expect(marked).toBe(true)
    expect(apiRequest).toHaveBeenCalledTimes(2)
    expect(apiRequest).toHaveBeenNthCalledWith(1, markPath(HAMMERHEAD_BATCH_ID, HAMMERHEAD_I_ID), { method: 'POST', body: JSON.stringify({ state: 'done', runs: 4 }) })
    expect(apiRequest).toHaveBeenNthCalledWith(2, chainPath(HAMMERHEAD_BATCH_ID))
    expect({ markError: store.markError, marks: store.nodes.map(node => node.mark) }).toEqual({ markError: null, marks: [{ state: 'done', runs: 4 }] })
  })

  it('exposes the message of the server when it refuses the mark, answers false and does not read the chain again', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new ApiError('Runs must be between 1 and 3', 400))
    const store = useGroupChainStore()

    const marked = await store.markStep(HAMMERHEAD_BATCH_ID, HAMMERHEAD_I_ID, 'launched', 9)

    expect(marked).toBe(false)
    expect(store.markError).toBe('Runs must be between 1 and 3')
    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(markPath(HAMMERHEAD_BATCH_ID, HAMMERHEAD_I_ID), { method: 'POST', body: JSON.stringify({ state: 'launched', runs: 9 }) })
  })

  it('removes the mark of a step with a delete on the line then reads the chain again and answers true', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(undefined).mockResolvedValueOnce({ id: HAMMERHEAD_BATCH_ID, nodes: [HAMMERHEAD_I] })
    const store = useGroupChainStore()

    const removed = await store.unmarkStep(HAMMERHEAD_BATCH_ID, HAMMERHEAD_I_ID)

    expect(removed).toBe(true)
    expect(apiRequest).toHaveBeenNthCalledWith(1, markPath(HAMMERHEAD_BATCH_ID, HAMMERHEAD_I_ID), { method: 'DELETE' })
    expect(apiRequest).toHaveBeenNthCalledWith(2, chainPath(HAMMERHEAD_BATCH_ID))
    expect(store.markError).toBeNull()
  })

  it('exposes the API error instead of an empty chain when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Chain unavailable: project not found'))
    const store = useGroupChainStore()

    await store.fetchChain(HAMMERHEAD_BATCH_ID)

    expect({ loading: store.loading, error: store.error, nodes: store.nodes }).toEqual({
      loading: false,
      error: 'Chain unavailable: project not found',
      nodes: [],
    })
  })

  it('empties the nodes of the previous project as soon as another project is read', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce({ id: HAMMERHEAD_BATCH_ID, nodes: [TRITANIUM] })
    const store = useGroupChainStore()
    await store.fetchChain(HAMMERHEAD_BATCH_ID)
    vi.mocked(apiRequest).mockReturnValue(deferred<unknown>().promise)

    void store.fetchChain(DRAKE_BATCH_ID)

    expect({ loading: store.loading, error: store.error, nodes: store.nodes }).toEqual({
      loading: true,
      error: null,
      nodes: [],
    })
  })
})

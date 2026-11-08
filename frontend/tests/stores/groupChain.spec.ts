import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupChainStore } from '@/stores/group-industry/chain'
import { deferred } from '../deferred'
import { HAMMERHEAD_I, HAMMERHEAD_II, SALE, TRITANIUM } from '../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HAMMERHEAD_BATCH_ID = 'project-hammerhead-batch'
const DRAKE_BATCH_ID = 'project-drake-batch'

function chainPath(projectId: string): string {
  return `/industry/projects/${projectId}/chain`
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

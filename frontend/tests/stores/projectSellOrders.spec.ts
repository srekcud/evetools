import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectSellOrdersStore } from '@/stores/group-industry/sellOrders'
import type { ProjectSellOrders } from '@/stores/group-industry/types'
import { sellOrder } from '../sellOrderFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const OTHER_PROJECT_ID = 'project-hecate'

function sellOrdersOf(projectId: string): ProjectSellOrders {
  return { projectId, orders: [sellOrder()], charactersToReconnect: [], syncedAt: '2026-11-10T12:15:00+00:00' }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('useProjectSellOrdersStore', () => {
  it('reads the sell orders of the project from its sell-orders resource', async () => {
    vi.mocked(apiRequest).mockResolvedValue(sellOrdersOf(PROJECT_ID))
    const store = useProjectSellOrdersStore()

    await store.fetchSellOrders(PROJECT_ID)

    expect({ held: store.sellOrders, loading: store.loading, error: store.error, path: vi.mocked(apiRequest).mock.calls[0][0] }).toEqual({
      held: sellOrdersOf(PROJECT_ID),
      loading: false,
      error: null,
      path: `/industry/projects/${PROJECT_ID}/sell-orders`,
    })
  })

  it('keeps no orders and reports the failure when the read fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('You do not have access to this project'))
    const store = useProjectSellOrdersStore()

    await store.fetchSellOrders(PROJECT_ID)

    expect({ held: store.sellOrders, error: store.error, loading: store.loading }).toEqual({
      held: null,
      error: 'You do not have access to this project',
      loading: false,
    })
  })

  it('ignores the answer of a project that is no longer the open one', async () => {
    let answerForFirstProject: (value: ProjectSellOrders) => void = () => undefined
    vi.mocked(apiRequest).mockImplementationOnce(() => new Promise((resolve) => { answerForFirstProject = resolve }))
    vi.mocked(apiRequest).mockResolvedValueOnce(sellOrdersOf(OTHER_PROJECT_ID))
    const store = useProjectSellOrdersStore()

    const firstRead = store.fetchSellOrders(PROJECT_ID)
    await store.fetchSellOrders(OTHER_PROJECT_ID)
    answerForFirstProject(sellOrdersOf(PROJECT_ID))
    await firstRead

    expect(store.sellOrders?.projectId).toBe(OTHER_PROJECT_ID)
  })

  it('forgets the orders on reset', async () => {
    vi.mocked(apiRequest).mockResolvedValue(sellOrdersOf(PROJECT_ID))
    const store = useProjectSellOrdersStore()
    await store.fetchSellOrders(PROJECT_ID)

    store.reset()

    expect({ held: store.sellOrders, error: store.error }).toEqual({ held: null, error: null })
  })
})

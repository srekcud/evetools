import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useIndustryActionsStore } from '@/stores/industry/actions'
import { industryAction } from '../industryActionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ACTIONS_ENDPOINT = '/industry/actions'
const NYX_PROJECT_ID = '0b6f3c2e-8d41-4a7b-9e15-5c2d7f80a3a1'
const WIDOW_PROJECT_ID = '7e2a9d14-3c5b-4f86-a0d2-91b4c6e8f2b7'
const HECATE_PROJECT_ID = '5d3c8b21-6a47-4e90-b1f2-8c9d0e7a4b63'

const QUEUE = [
  industryAction({ projectId: NYX_PROJECT_ID, projectName: 'Nyx batch', code: 'source_material', blocked: true, tab: 'materials', typeId: 34, typeName: 'Tritanium', quantity: 147 }),
  industryAction({ projectId: WIDOW_PROJECT_ID, projectName: 'Widow batch', code: 'record_sale', tab: 'sales', quantity: 3 }),
  industryAction({ projectId: HECATE_PROJECT_ID, projectName: 'Hecate batch', code: 'start_project', targetStatus: 'in_progress' }),
]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('industry actions store', () => {
  it('has no count before the queue is loaded, then counts the entries read from the actions endpoint', async () => {
    const store = useIndustryActionsStore()
    const countBeforeLoad = store.count
    vi.mocked(apiRequest).mockResolvedValueOnce(QUEUE)
    await store.fetchActions()
    const countOfThree = store.count
    const actionsOfThree = [...store.actions]
    vi.mocked(apiRequest).mockResolvedValueOnce([])
    await store.fetchActions()

    expect({
      countBeforeLoad,
      countOfThree,
      actionsOfThree,
      countOfEmptyQueue: store.count,
      isLoaded: store.isLoaded,
      error: store.error,
      endpoint: vi.mocked(apiRequest).mock.calls[0][0],
    }).toEqual({
      countBeforeLoad: null,
      countOfThree: 3,
      actionsOfThree: QUEUE,
      countOfEmptyQueue: 0,
      isLoaded: true,
      error: null,
      endpoint: ACTIONS_ENDPOINT,
    })
  })

  it('exposes the failure and no count, not even the previous one, when a refresh fails', async () => {
    const store = useIndustryActionsStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(QUEUE)
    await store.fetchActions()
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('Actions service unavailable'))

    await store.fetchActions()

    expect({ count: store.count, error: store.error }).toEqual({ count: null, error: 'Actions service unavailable' })
  })
})

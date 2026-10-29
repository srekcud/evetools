import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { CopyCosts, CostEstimation } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-189'

const COST_ESTIMATION: CostEstimation = {
  id: PROJECT_ID,
  materialCost: 48_200_000,
  jobInstallCost: 1_350_000,
  bpoCost: 0,
  totalCost: 49_550_000,
  perUnit: 24_775_000,
  outputQuantity: 2,
  materials: [],
  jobInstallSteps: [],
  failedProducts: [],
}

const COPY_COSTS: CopyCosts = {
  id: PROJECT_ID,
  copies: [
    { blueprintTypeId: 627, blueprintName: 'Vexor Blueprint', productTypeName: 'Vexor', runs: 10, cost: 288_000, depth: 1 },
    { blueprintTypeId: 2186, blueprintName: 'Hammerhead II Blueprint', productTypeName: 'Hammerhead II', runs: 4, cost: 124_000, depth: 2 },
  ],
  totalCopyCost: 412_000,
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('projects store cost estimation failure (issue 189)', () => {
  it('rethrows the failure of the cost estimation, forgets the previous estimation and leaves the page error untouched', async () => {
    const store = useProjectsStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(COST_ESTIMATION)
    await store.fetchCostEstimation(PROJECT_ID)
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('API error: 503', 503))

    await expect(store.fetchCostEstimation(PROJECT_ID)).rejects.toThrow('API error: 503')
    expect({ costEstimation: store.costEstimation, pageError: store.error }).toEqual({ costEstimation: null, pageError: null })
  })

  it('returns the cost estimation it loaded', async () => {
    const store = useProjectsStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(COST_ESTIMATION)

    const estimation = await store.fetchCostEstimation(PROJECT_ID)

    expect({ estimation, costEstimation: store.costEstimation }).toEqual({ estimation: COST_ESTIMATION, costEstimation: COST_ESTIMATION })
    expect(apiRequest).toHaveBeenCalledWith(`/industry/projects/${PROJECT_ID}/cost-estimation`)
  })

  it('rethrows the failure of the copy costs, forgets the previous copy costs and leaves the page error untouched', async () => {
    const store = useProjectsStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(COPY_COSTS)
    await store.fetchCopyCosts(PROJECT_ID)
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('Network down (fictif)'))

    await expect(store.fetchCopyCosts(PROJECT_ID)).rejects.toThrow('Network down (fictif)')
    expect({ copyCosts: store.copyCosts, pageError: store.error }).toEqual({ copyCosts: null, pageError: null })
  })

  it('returns the copy costs it loaded', async () => {
    const store = useProjectsStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(COPY_COSTS)

    const copyCosts = await store.fetchCopyCosts(PROJECT_ID)

    expect({ copyCosts, stored: store.copyCosts }).toEqual({ copyCosts: COPY_COSTS, stored: COPY_COSTS })
    expect(apiRequest).toHaveBeenCalledWith(`/industry/projects/${PROJECT_ID}/copy-costs`)
  })
})

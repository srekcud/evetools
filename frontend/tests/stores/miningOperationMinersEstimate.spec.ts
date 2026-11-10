import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useMiningOperationStore } from '@/stores/miningOperation'
import type { MiningMinersEstimate } from '@/stores/miningOperation'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OPERATION_ID = '0198c1f2-6a5e-7d11-9c44-5b2f3a0e9d71'
const MINERS_ESTIMATE_PATH = `/mining-operations/${OPERATION_ID}/miners-estimate`
const EMPTY_ESTIMATE: MiningMinersEstimate = { operationId: OPERATION_ID, startAt: '2026-11-11T09:40:00+00:00', types: [] }

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('mining operation store, miners estimate', () => {
  it('fetches the miners estimate of the operation and keeps it', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(EMPTY_ESTIMATE)
    const store = useMiningOperationStore()

    await store.fetchMinersEstimate(OPERATION_ID)

    expect({ calls: vi.mocked(apiRequest).mock.calls, kept: store.minersEstimate, error: store.minersEstimateError }).toEqual({
      calls: [[MINERS_ESTIMATE_PATH]],
      kept: EMPTY_ESTIMATE,
      error: null,
    })
  })

  it('drops the previous estimate and keeps the error message when the fetch fails', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(EMPTY_ESTIMATE).mockRejectedValueOnce(new Error('Mining operation not found'))
    const store = useMiningOperationStore()
    await store.fetchMinersEstimate(OPERATION_ID)

    await store.fetchMinersEstimate(OPERATION_ID)

    expect({ kept: store.minersEstimate, error: store.minersEstimateError }).toEqual({ kept: null, error: 'Mining operation not found' })
  })
})

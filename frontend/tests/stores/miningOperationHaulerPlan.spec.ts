import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useMiningOperationStore } from '@/stores/miningOperation'
import { HAULER_OPERATION_ID, knownHaulerPlan } from '../components/mining-operation/haulerPlanFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HAULER_PLAN_PATH = `/mining-operations/${HAULER_OPERATION_ID}/hauler-plan`

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('mining operation store, hauler plan', () => {
  it('fetches the hauler plan of the operation and keeps it, then posts the emptied declaration and keeps the returned plan', async () => {
    const emptied = knownHaulerPlan({ emptiedAt: '2026-11-10T20:56:00+00:00', originIsStart: false, fillM3: 0, fillRatio: 0, fullInMinutes: 8 })
    vi.mocked(apiRequest).mockResolvedValueOnce(knownHaulerPlan()).mockResolvedValueOnce(emptied)
    const store = useMiningOperationStore()

    await store.fetchHaulerPlan(HAULER_OPERATION_ID)
    const afterFetch = store.haulerPlan
    const declared = await store.declareHangarEmptied(HAULER_OPERATION_ID)

    expect({ calls: vi.mocked(apiRequest).mock.calls, afterFetch, declared, kept: store.haulerPlan }).toEqual({
      calls: [[HAULER_PLAN_PATH], [`${HAULER_PLAN_PATH}/emptied`, { method: 'POST' }]],
      afterFetch: knownHaulerPlan(),
      declared: emptied,
      kept: emptied,
    })
  })
})

import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useMiningOperationStore } from '@/stores/miningOperation'
import { OPERATION_O, startedSpodumainOperation } from '../views/MiningOperation/miningOperationLedgerFixtures'
import { FROZEN_AT, VALUATION_PATH, e1Valuation } from '../views/MiningOperation/miningOperationValuationFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('mining operation store, valuation and shares', () => {
  it('fetches the valuation of the operation and keeps it', async () => {
    vi.mocked(apiRequest).mockResolvedValue(e1Valuation())
    const store = useMiningOperationStore()

    await store.fetchValuation(OPERATION_O)

    expect([vi.mocked(apiRequest).mock.calls, store.valuation]).toEqual([[[VALUATION_PATH]], e1Valuation()])
  })

  it('patches only the given settings and keeps the recomputed valuation', async () => {
    const reprocessed = e1Valuation({ settings: { ...e1Valuation().settings, valuationForm: 'reprocessed', reprocessYield: 0.72358 } })
    vi.mocked(apiRequest).mockResolvedValue(reprocessed)
    const store = useMiningOperationStore()

    const updated = await store.updateValuation(OPERATION_O, { valuationForm: 'reprocessed', reprocessYield: 0.72358 })

    expect([vi.mocked(apiRequest).mock.calls, updated, store.valuation]).toEqual([
      [[VALUATION_PATH, { method: 'PATCH', body: JSON.stringify({ valuationForm: 'reprocessed', reprocessYield: 0.72358 }) }]],
      reprocessed,
      reprocessed,
    ])
  })

  it('freezes the shares and keeps the operation in shares', async () => {
    const frozen = startedSpodumainOperation({ status: 'shares', endedAt: '2026-11-05T21:30:00+00:00', sharesFrozenAt: FROZEN_AT })
    vi.mocked(apiRequest).mockResolvedValue(frozen)
    const store = useMiningOperationStore()

    const result = await store.freezeShares(OPERATION_O)

    expect([vi.mocked(apiRequest).mock.calls, result, store.currentOperation]).toEqual([
      [[`/mining-operations/${OPERATION_O}/freeze-shares`, { method: 'POST', body: JSON.stringify({}) }]],
      frozen,
      frozen,
    ])
  })

  it('keeps the refusal of the freeze and rethrows it', async () => {
    const refusal = new ApiError('Every mined quantity must be known before freezing the shares: Brann Holt', 400)
    vi.mocked(apiRequest).mockRejectedValue(refusal)
    const store = useMiningOperationStore()

    await expect(store.freezeShares(OPERATION_O)).rejects.toBe(refusal)
    expect(store.error).toBe('Every mined quantity must be known before freezing the shares: Brann Holt')
  })

  it('keeps the refusal of a settings update and rethrows it', async () => {
    const refusal = new ApiError('A structure market needs a structure', 400)
    vi.mocked(apiRequest).mockRejectedValue(refusal)
    const store = useMiningOperationStore()

    await expect(store.updateValuation(OPERATION_O, { valuationMarket: 'structure' })).rejects.toBe(refusal)
    expect(store.error).toBe('A structure market needs a structure')
  })
})

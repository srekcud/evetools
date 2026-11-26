import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryPendingStockStore } from '@/stores/planetaryPendingStock'
import { usePlanetarySupplyStore } from '@/stores/planetarySupply'
import { deferred } from '../deferred'
import { coolantFromAmarr, mechanicalPartsFromAmarr, pendingStockResponse } from '../planetaryPendingStockFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPendingStock store', () => {
  it('reads the pending stock once, shows it loading meanwhile and keeps the pending outputs', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise as never)
    const store = usePlanetaryPendingStockStore()

    const loading = store.fetchPendingStock()
    expect(store.isLoading).toBe(true)
    pending.resolve(pendingStockResponse([coolantFromAmarr(), mechanicalPartsFromAmarr()], false))
    await loading

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/pending-stock'])
    expect([store.isLoading, store.error]).toEqual([false, null])
    expect(store.data?.pendingOutputs).toEqual([coolantFromAmarr(), mechanicalPartsFromAmarr()])
    expect(store.data?.complete).toBe(false)
  })

  it('keeps the message of a failing read and clears it without touching the input needs error', async () => {
    const supplyStore = usePlanetarySupplyStore()
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('input needs unavailable'))
    await supplyStore.fetchInputNeeds()
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('pending stock unavailable'))
    const store = usePlanetaryPendingStockStore()

    await store.fetchPendingStock()

    expect([store.error, store.data, store.isLoading]).toEqual(['pending stock unavailable', null, false])
    expect(supplyStore.error).toBe('input needs unavailable')

    store.clearError()

    expect(store.error).toBeNull()
    expect(supplyStore.error).toBe('input needs unavailable')
  })
})

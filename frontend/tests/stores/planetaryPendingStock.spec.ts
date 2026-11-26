import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryPendingStockStore } from '@/stores/planetaryPendingStock'
import { usePlanetarySupplyStore } from '@/stores/planetarySupply'
import { deferred } from '../deferred'
import { coolantFromAmarr, customsOfficeStockOf, mechanicalPartsFromAmarr, pendingStockResponse, unlocatedWaterInCustomsOffice } from '../planetaryPendingStockFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPendingStock store', () => {
  it('reads the pending stock once, shows it loading meanwhile and keeps the pending outputs and the customs office stocks', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise as never)
    const store = usePlanetaryPendingStockStore()

    const loading = store.fetchPendingStock()
    expect(store.isLoading).toBe(true)
    pending.resolve(pendingStockResponse([coolantFromAmarr(), mechanicalPartsFromAmarr()], false, [customsOfficeStockOf(), unlocatedWaterInCustomsOffice()]))
    await loading

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/pending-stock'])
    expect([store.isLoading, store.error]).toEqual([false, null])
    expect(store.data?.pendingOutputs).toEqual([coolantFromAmarr(), mechanicalPartsFromAmarr()])
    expect(store.data?.customsOfficeStocks).toEqual([customsOfficeStockOf(), unlocatedWaterInCustomsOffice()])
    expect(store.data?.complete).toBe(false)
  })

  it('reports a response without a customs office stock list as a shape error and never defaults it to an empty list', async () => {
    const { customsOfficeStocks: _omitted, ...withoutCustomsOfficeStocks } = pendingStockResponse([coolantFromAmarr()])
    const store = usePlanetaryPendingStockStore()
    const outcomes: unknown[][] = []

    for (const response of [withoutCustomsOfficeStocks, { ...withoutCustomsOfficeStocks, customsOfficeStocks: null }, { ...withoutCustomsOfficeStocks, customsOfficeStocks: 'none' }]) {
      vi.mocked(apiRequest).mockResolvedValueOnce(response as never)
      await store.fetchPendingStock()
      outcomes.push([store.error !== null && store.error.length > 0, store.data])
    }

    expect(outcomes).toEqual([[true, null], [true, null], [true, null]])
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

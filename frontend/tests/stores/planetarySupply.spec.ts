import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetarySupplyStore } from '@/stores/planetarySupply'
import { deferred } from '../deferred'
import { referenceLaunchpad, supplyResponse } from '../planetarySupplyFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetarySupply store', () => {
  it('reads the input needs once, shows it loading meanwhile and keeps the launchpads', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise as never)
    const store = usePlanetarySupplyStore()

    const loading = store.fetchInputNeeds()
    expect(store.isLoading).toBe(true)
    pending.resolve(supplyResponse([referenceLaunchpad()]))
    await loading

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/input-needs'])
    expect([store.isLoading, store.error]).toEqual([false, null])
    expect(store.data?.launchpads).toEqual([referenceLaunchpad()])
    expect(store.data?.complete).toBe(true)
  })

  it('keeps the message of a failing read as the error and no data', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('input needs unavailable'))
    const store = usePlanetarySupplyStore()

    await store.fetchInputNeeds()

    expect([store.error, store.data, store.isLoading]).toEqual(['input needs unavailable', null, false])
  })

  it('clears the error of a failed read', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('input needs unavailable'))
    const store = usePlanetarySupplyStore()
    await store.fetchInputNeeds()

    store.clearError()

    expect(store.error).toBeNull()
  })

  it('clears the previous error when a new read succeeds', async () => {
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('input needs unavailable'))
    const store = usePlanetarySupplyStore()
    await store.fetchInputNeeds()
    vi.mocked(apiRequest).mockResolvedValueOnce(supplyResponse([referenceLaunchpad()]) as never)

    await store.fetchInputNeeds()

    expect([store.error, store.data?.launchpads.length, store.isLoading]).toEqual([null, 1, false])
  })
})

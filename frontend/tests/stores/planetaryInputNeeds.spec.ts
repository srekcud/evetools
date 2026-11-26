import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryStore, type PlanetaryInputNeeds } from '@/stores/planetary'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const INPUT_NEEDS: PlanetaryInputNeeds = {
  complete: true,
  launchpads: [
    {
      colonyId: 'colony-ilyra-barren',
      pinId: 1021,
      feeds: [
        { typeId: 2393, typeName: 'Bacteria', emptyAt: '2026-11-26T14:00:00+00:00' },
        { typeId: 2073, typeName: 'Microorganisms', emptyAt: null },
      ],
    },
  ],
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetary store input needs', () => {
  it('reads the input needs resource and keeps its launchpads with their feeds', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ ...INPUT_NEEDS, colonies: [], extractions: [], weekly: [] } as never)
    const store = usePlanetaryStore()

    await store.fetchInputNeeds()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/input-needs'])
    expect(store.inputNeeds?.launchpads).toEqual(INPUT_NEEDS.launchpads)
    expect(store.inputNeeds?.complete).toBe(true)
    expect(store.error).toBeNull()
  })

  it('keeps the message of a failing read as the store error and no input needs', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('input needs unavailable'))
    const store = usePlanetaryStore()

    await store.fetchInputNeeds()

    expect(store.inputNeeds).toBeNull()
    expect(store.error).toBe('input needs unavailable')
  })
})

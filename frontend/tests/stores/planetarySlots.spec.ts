import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryStore, type PlanetarySlots } from '@/stores/planetary'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const KNOWN_SLOTS: PlanetarySlots = {
  characters: [
    { characterId: 2113840001, characterName: 'Ilyra Castell', isMain: true, coloniesUsed: 2, consolidationLevel: 4, maxColonies: 5 },
    { characterId: 2113840002, characterName: 'Sera Idle', isMain: false, coloniesUsed: 0, consolidationLevel: 0, maxColonies: 1 },
  ],
  totalUsed: 2,
  totalMax: 6,
}

const UNKNOWN_MAXIMUM_SLOTS: PlanetarySlots = {
  characters: [
    { characterId: 2113840001, characterName: 'Ilyra Castell', isMain: true, coloniesUsed: 2, consolidationLevel: 4, maxColonies: 5 },
    { characterId: 2113840003, characterName: 'Kael Unsynced', isMain: false, coloniesUsed: 1, consolidationLevel: null, maxColonies: null },
  ],
  totalUsed: 3,
  totalMax: null,
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetary store slots', () => {
  it('reads the slots resource and keeps its characters and totals', async () => {
    vi.mocked(apiRequest).mockResolvedValue(KNOWN_SLOTS as never)
    const store = usePlanetaryStore()

    await store.fetchSlots()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/slots'])
    expect(store.slots).toEqual(KNOWN_SLOTS)
    expect(store.error).toBeNull()
  })

  it('keeps an unknown maximum as null instead of a number', async () => {
    vi.mocked(apiRequest).mockResolvedValue(UNKNOWN_MAXIMUM_SLOTS as never)
    const store = usePlanetaryStore()

    await store.fetchSlots()

    expect(store.slots?.totalMax).toBeNull()
    expect(store.slots?.characters[1].maxColonies).toBeNull()
    expect(store.slots?.characters[1].consolidationLevel).toBeNull()
  })

  it('keeps the message of a failing read as the store error and no slots', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('slots unavailable'))
    const store = usePlanetaryStore()

    await store.fetchSlots()

    expect(store.error).toBe('slots unavailable')
    expect(store.slots).toBeNull()
  })
})

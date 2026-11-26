import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryLayoutLibraryStore } from '@/stores/planetaryLayoutLibrary'
import { LIBRARY_COOLANT, LIBRARY_OXYGEN, LIBRARY_ROBOTICS } from '../planetaryLayoutLibraryFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryLayoutLibrary store', () => {
  it('loads the public library with a single GET and keeps the rows in the order of the response', async () => {
    vi.mocked(apiRequest).mockResolvedValue([LIBRARY_ROBOTICS, LIBRARY_OXYGEN, LIBRARY_COOLANT] as never)
    const store = usePlanetaryLayoutLibraryStore()

    await store.load()

    expect(vi.mocked(apiRequest).mock.calls.map(call => [call[0], (call[1] as RequestInit | undefined)?.method ?? 'GET'])).toEqual([
      ['/planetary/layout-library', 'GET'],
    ])
    expect(store.entries.map(entry => [entry.id, entry.mine])).toEqual([
      ['library-robotics', false],
      ['library-oxygen', true],
      ['library-coolant', false],
    ])
  })
})

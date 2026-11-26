import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryLayoutsStore, type ColonyLayout } from '@/stores/planetaryLayouts'
import { COOLANT_LAYOUT, ROBOTICS_LAYOUT } from '../planetaryLayoutsFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LAYOUTS_ENDPOINT = '/planetary/layouts'
const PUBLISHED_COOLANT: ColonyLayout = { ...COOLANT_LAYOUT, public: true, publishedAt: '2026-11-26T09:15:00+00:00' }

async function storeHolding(layouts: ColonyLayout[], onPatch: () => unknown) {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    const method = options?.method ?? 'GET'
    if (endpoint === LAYOUTS_ENDPOINT && method === 'GET') return layouts
    if (method === 'PATCH') return onPatch()
    return null
  }) as never)
  const store = usePlanetaryLayoutsStore()
  await store.load()
  vi.mocked(apiRequest).mockClear()
  return store
}

function patchRequests() {
  return vi.mocked(apiRequest).mock.calls
    .filter(call => (call[1] as RequestInit | undefined)?.method === 'PATCH')
    .map(call => ({ endpoint: call[0], body: JSON.parse(String((call[1] as RequestInit).body)) }))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryLayouts store publication', () => {
  it('patches the public flag alone, swaps in the layout of the response and returns true', async () => {
    const store = await storeHolding([ROBOTICS_LAYOUT, COOLANT_LAYOUT], () => PUBLISHED_COOLANT)

    const done = await store.setPublic('layout-coolant', true)

    expect(done).toBe(true)
    expect(patchRequests()).toEqual([{ endpoint: `${LAYOUTS_ENDPOINT}/layout-coolant`, body: { public: true } }])
    expect(store.layouts.map(layout => [layout.id, layout.public, layout.publishedAt])).toEqual([
      ['layout-robotics', false, null],
      ['layout-coolant', true, '2026-11-26T09:15:00+00:00'],
    ])
  })

  it('returns false and leaves the layouts as they were when the request fails', async () => {
    const store = await storeHolding([PUBLISHED_COOLANT, ROBOTICS_LAYOUT], () => {
      throw new Error('boom')
    })

    const done = await store.setPublic('layout-coolant', false)

    expect(done).toBe(false)
    expect(patchRequests()).toEqual([{ endpoint: `${LAYOUTS_ENDPOINT}/layout-coolant`, body: { public: false } }])
    expect(store.layouts.map(layout => [layout.id, layout.public])).toEqual([['layout-coolant', true], ['layout-robotics', false]])
  })
})

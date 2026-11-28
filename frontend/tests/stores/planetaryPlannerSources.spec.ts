import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { COOLANT, PLANNER_CATALOG, PLANNER_HUB } from '../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPlanner store purchase and sale sources', () => {
  it('sets the purchase source and the sale source independently and recomputes the plan from each', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ id: 'planner', ...PLANNER_CATALOG, hub: PLANNER_HUB } as never)
    const store = usePlanetaryPlannerStore()
    await store.fetchCatalog()
    store.addProduction(COOLANT)
    expect(store.plan?.summary.unitCost[0]).toBeCloseTo(1640, 6)

    store.setPurchaseSource('jita-buy')

    expect(store.settings).toEqual({ purchaseSource: 'jita-buy', saleSource: 'jita-sell', taxPercent: 4, daysBetweenRounds: 4 })
    expect(store.plan?.summary.unitCost[0]).toBeCloseTo(1400, 6)

    store.setSaleSource('jita-buy')

    expect(store.settings).toEqual({ purchaseSource: 'jita-buy', saleSource: 'jita-buy', taxPercent: 4, daysBetweenRounds: 4 })
    expect(store.plan?.summary.unitCost[0]).toBeCloseTo(1396, 6)
  })

  it('keeps the hub of the planner response, and none before the read', async () => {
    const store = usePlanetaryPlannerStore()
    expect(store.hub).toBeNull()
    vi.mocked(apiRequest).mockResolvedValue({ id: 'planner', ...PLANNER_CATALOG, hub: PLANNER_HUB } as never)

    await store.fetchCatalog()

    expect(store.hub).toEqual({ structureId: 1_040_000_000_001, structureName: 'Keepstar Test Hub', hasData: true })

    vi.mocked(apiRequest).mockResolvedValue({ id: 'planner', ...PLANNER_CATALOG, hub: { ...PLANNER_HUB, hasData: false } } as never)
    await store.fetchCatalog()

    expect(store.hub?.hasData).toBe(false)
  })
})

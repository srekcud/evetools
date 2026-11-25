import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { deferred } from '../deferred'
import { COOLANT, MECHANICAL_PARTS, PLANNER_CATALOG, ROBOTICS } from '../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const EMPTY_CATALOG_KEY = 'pi.planner.errors.emptyCatalog'

async function loadedStore() {
  vi.mocked(apiRequest).mockResolvedValue({ id: 'planner', ...PLANNER_CATALOG } as never)
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  return store
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPlanner store loading', () => {
  it('reads the planner resource once, shows it loading meanwhile and keeps its recipes, types and prices', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise as never)
    const store = usePlanetaryPlannerStore()

    const loading = store.fetchCatalog()
    expect(store.isLoading).toBe(true)
    pending.resolve({ id: 'planner', ...PLANNER_CATALOG })
    await loading

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/planner'])
    expect([store.isLoading, store.error]).toEqual([false, null])
    expect(store.catalog?.recipes).toEqual(PLANNER_CATALOG.recipes)
    expect(store.catalog?.types).toEqual(PLANNER_CATALOG.types)
    expect(store.catalog?.prices).toEqual(PLANNER_CATALOG.prices)
  })

  it('keeps the message of a failing read as the error and no catalog', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('planner unavailable'))
    const store = usePlanetaryPlannerStore()

    await store.fetchCatalog()

    expect([store.error, store.catalog, store.isLoading]).toEqual(['planner unavailable', null, false])
  })

  it('reports an empty SDE as an explicit error instead of an empty planner', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ id: 'planner', recipes: [], types: [], prices: [] } as never)
    const store = usePlanetaryPlannerStore()

    await store.fetchCatalog()

    expect(store.error).not.toBeNull()
    expect([EMPTY_CATALOG_KEY, i18n.global.t(EMPTY_CATALOG_KEY)]).toContain(store.error)
  })
})

describe('planetaryPlanner store actions', () => {
  it('adds a production at the output of one factory for a day and adds the same product again as a separate production', async () => {
    const store = await loadedStore()

    store.addProduction(COOLANT)
    store.addProduction(COOLANT)
    store.addProduction(ROBOTICS)

    expect(store.productions).toEqual([
      { typeId: COOLANT, targetPerDay: 120 },
      { typeId: COOLANT, targetPerDay: 120 },
      { typeId: ROBOTICS, targetPerDay: 72 },
    ])
  })

  it('refuses a target at zero, below or not a number and leaves the production and the plan unchanged', async () => {
    const store = await loadedStore()
    store.addProduction(COOLANT)

    const refused = [store.setTarget(0, 0), store.setTarget(0, -3), store.setTarget(0, Number.NaN)]
    const unchanged = store.productions
    const accepted = store.setTarget(0, 60)

    expect(refused).toEqual([false, false, false])
    expect(unchanged).toEqual([{ typeId: COOLANT, targetPerDay: 60 }])
    expect(accepted).toBe(true)
    expect(store.plan?.trees.map(tree => tree.perDay)).toEqual([60])
  })

  it('refuses a POCO tax outside 0 to 100 and keeps the previous value', async () => {
    const store = await loadedStore()

    const refused = [store.setTaxPercent(150), store.setTaxPercent(-1), store.setTaxPercent(Number.NaN)]
    const kept = store.settings.taxPercent
    const accepted = [store.setTaxPercent(7.5), store.setTaxPercent(0)]

    expect(refused).toEqual([false, false, false])
    expect(kept).toBe(4)
    expect(accepted).toEqual([true, true])
    expect(store.settings.taxPercent).toBe(0)
  })

  it('recomputes the plan from the other price source and keeps the explicit choices', async () => {
    const store = await loadedStore()
    store.addProduction(COOLANT)
    store.setChoice(MECHANICAL_PARTS, 'buy')
    expect(store.plan?.summary.unitCost[0]).toBeCloseTo(1640, 6)

    store.setPriceSource('jita-buy')

    expect(store.settings.priceSource).toBe('jita-buy')
    expect(store.choices).toEqual({ [MECHANICAL_PARTS]: 'buy' })
    expect(store.plan?.summary.unitCost[0]).toBeCloseTo(1396, 6)
  })
})

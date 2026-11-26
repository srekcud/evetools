import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import {
  BROADCAST_NODE,
  MECHANICAL_PARTS,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  PLANNER_MARKET,
  ROBOTICS,
} from '../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type Store = ReturnType<typeof usePlanetaryPlannerStore>

async function storeOnFreePlanets(): Promise<Store> {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    if (endpoint === '/planetary/planner/market') return { id: 'market', ...PLANNER_MARKET }
    return { id: 'planner', ...PLANNER_CATALOG }
  }) as never)
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  await store.fetchAccount()
  await store.fetchMarket()
  store.setRankingBase('free')
  return store
}

function rowOf(store: Store, typeId: number) {
  const row = store.ranking.find(candidate => candidate.typeId === typeId)
  if (!row) throw new Error(`No ranking row for ${typeId}`)
  return row
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPlanner store ranking on the free planets', () => {
  it('counts one planet less once a production of the ranking is added to the plan: 10 free planets, then 9, and a maximum output of 3,120 then 2,808', async () => {
    const store = await storeOnFreePlanets()
    expect([rowOf(store, ROBOTICS).baseCount, rowOf(store, ROBOTICS).maxOutputPerDay]).toEqual([10, expect.closeTo(3_120, 6)])

    store.addProduction(MECHANICAL_PARTS, rowOf(store, MECHANICAL_PARTS).planTarget as number)

    expect(store.rankingBase).toBe('free')
    expect([rowOf(store, ROBOTICS).baseCount, rowOf(store, ROBOTICS).maxOutputPerDay]).toEqual([9, expect.closeTo(2_808, 6)])
  })

  it('gives back the free planet when the production is removed from the plan', async () => {
    const store = await storeOnFreePlanets()
    store.addProduction(MECHANICAL_PARTS, rowOf(store, MECHANICAL_PARTS).planTarget as number)

    store.removeProduction(0)

    expect(rowOf(store, ROBOTICS).baseCount).toBe(10)
  })

  it('offers no plan target for a product absent from the market', async () => {
    const store = await storeOnFreePlanets()

    expect(rowOf(store, BROADCAST_NODE).averageDailyVolume).toBeNull()
    expect(rowOf(store, BROADCAST_NODE).planTarget).toBeNull()
  })

  it('keeps the output of a product absent from the market uncapped and offers 23 for Robotics, whose volume is known', async () => {
    const store = await storeOnFreePlanets()

    expect(rowOf(store, BROADCAST_NODE).capped).toBe(false)
    expect(rowOf(store, ROBOTICS).planTarget).toBe(23)
  })
})

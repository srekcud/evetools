import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { PlannerMarket } from '@/utils/planetaryRanking'
import {
  BROADCAST_NODE,
  COOLANT,
  MECHANICAL_PARTS,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  PLANNER_MARKET,
  ROBOTICS,
  THE_FORGE_REGION_ID,
  WATER,
} from '../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MARKET_ERROR_KEY = 'pi.planner.errors.market'

type Store = ReturnType<typeof usePlanetaryPlannerStore>

function serve(market: PlannerMarket | Record<string, unknown> | Error) {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    if (endpoint === '/planetary/planner/market') {
      if (market instanceof Error) throw market
      return { id: 'market', ...market }
    }
    return { id: 'planner', ...PLANNER_CATALOG }
  }) as never)
}

async function loadedStore(market: PlannerMarket | Record<string, unknown> | Error = PLANNER_MARKET): Promise<Store> {
  serve(market)
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  await store.fetchMarket()
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

describe('planetaryPlanner store market', () => {
  it('reads the market endpoint and keeps the region and the products of the response', async () => {
    const store = await loadedStore()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/planner', '/planetary/planner/market'])
    expect(store.market).toEqual({ regionId: THE_FORGE_REGION_ID, products: PLANNER_MARKET.products })
    expect(store.error).toBeNull()
  })

  it('drops the market and reports an explicit error when the market endpoint fails, the catalog staying usable', async () => {
    const store = await loadedStore(new Error('market unavailable'))

    expect(store.market).toBeNull()
    expect(store.catalog).not.toBeNull()
    expect(store.error).toBe(i18n.global.t(MARKET_ERROR_KEY))
    expect(store.error).not.toBe(MARKET_ERROR_KEY)
  })

  it('does not replace the error of a failing catalog with the market error', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('planner unavailable'))
    const store = usePlanetaryPlannerStore()
    await store.fetchCatalog()

    await store.fetchMarket()

    expect([store.error, store.market]).toEqual(['planner unavailable', null])
  })

  it('refuses a market response whose product list is missing or is not a list, with the same explicit error', async () => {
    for (const response of [{ regionId: THE_FORGE_REGION_ID }, { regionId: THE_FORGE_REGION_ID, products: null }, { regionId: THE_FORGE_REGION_ID, products: 'none' }]) {
      setActivePinia(createPinia())
      const store = await loadedStore(response)

      expect(store.market).toBeNull()
      expect(store.error).toBe(i18n.global.t(MARKET_ERROR_KEY))
      expect(store.error).not.toBe(MARKET_ERROR_KEY)
    }
  })
})

describe('planetaryPlanner store ranking', () => {
  it('starts on one planet, at 5 % of the volume, for every tier', () => {
    const store = usePlanetaryPlannerStore()

    expect([store.rankingBase, store.volumeSharePercent, store.rankingTier]).toEqual(['planet', 5, 'all'])
    expect(store.ranking).toEqual([])
  })

  it('ranks the P2, P3 and P4 by profit a day once the catalog and the market are loaded, the unknown volume last', async () => {
    const store = await loadedStore()

    expect(store.ranking.map(row => row.typeId)).toEqual([ROBOTICS, MECHANICAL_PARTS, COOLANT, BROADCAST_NODE])
    expect(rowOf(store, ROBOTICS).profitPerDay).toBeCloseTo(496_186.6667, 2)
    expect(rowOf(store, BROADCAST_NODE).profitPerDay).toBeNull()
  })

  it('shows an unknown volume and profit on every row while the market failed to load', async () => {
    const store = await loadedStore(new Error('market unavailable'))

    expect(store.ranking).toHaveLength(4)
    expect(store.ranking.map(row => [row.averageDailyVolume, row.profitPerDay])).toEqual([[null, null], [null, null], [null, null], [null, null]])
  })

  it('takes the volume share the player sets: 10 % doubles the capped output of Robotics from 23 to 46', async () => {
    const store = await loadedStore()
    expect(rowOf(store, ROBOTICS).outputPerDay).toBeCloseTo(23, 8)

    const accepted = store.setVolumeShare(10)

    expect(accepted).toBe(true)
    expect(store.volumeSharePercent).toBe(10)
    expect(rowOf(store, ROBOTICS).outputPerDay).toBeCloseTo(46, 8)
  })

  it('accepts 0 and 100 and refuses a share below 0, above 100 or not a number, keeping the previous value', async () => {
    const store = await loadedStore()
    store.setVolumeShare(10)

    const refused = [store.setVolumeShare(-0.1), store.setVolumeShare(100.1), store.setVolumeShare(Number.NaN)]

    expect(refused).toEqual([false, false, false])
    expect(store.volumeSharePercent).toBe(10)
    expect([store.setVolumeShare(0), store.setVolumeShare(100)]).toEqual([true, true])
    expect(store.volumeSharePercent).toBe(100)
  })

  it('counts six planets for a character and one again for a planet', async () => {
    const store = await loadedStore()

    expect(store.setRankingBase('character')).toBe(true)
    expect([rowOf(store, ROBOTICS).baseCount, rowOf(store, ROBOTICS).maxOutputPerDay]).toEqual([6, expect.closeTo(1_872, 6)])
    expect(store.setRankingBase('planet')).toBe(true)
    expect(rowOf(store, ROBOTICS).baseCount).toBe(1)
  })

  it('refuses the free planets while the maximum of the account is unknown and takes the 10 free planets once the account is loaded', async () => {
    const store = await loadedStore()

    expect(store.setRankingBase('free')).toBe(false)
    expect(store.rankingBase).toBe('planet')

    await store.fetchAccount()

    expect(store.setRankingBase('free')).toBe(true)
    expect(store.rankingBase).toBe('free')
    expect(rowOf(store, ROBOTICS).baseCount).toBe(10)
    expect(rowOf(store, ROBOTICS).maxOutputPerDay).toBeCloseTo(3_120, 6)
  })

  it('keeps only the products of the tier the player picks', async () => {
    const store = await loadedStore()

    store.setRankingTier('P2')
    expect(store.ranking.map(row => row.typeId)).toEqual([MECHANICAL_PARTS, COOLANT])
    store.setRankingTier('P4')
    expect(store.ranking.map(row => row.typeId)).toEqual([BROADCAST_NODE])
    store.setRankingTier('all')
    expect(store.ranking).toHaveLength(4)
  })

  it('does not follow the productions and the choices of the plan: the cheapest chain is its own', async () => {
    const store = await loadedStore()
    const before = JSON.parse(JSON.stringify(store.ranking))
    expect(before).toHaveLength(4)

    store.addProduction(ROBOTICS, 72)
    store.setChoice(COOLANT, 'produce')
    store.setChoice(WATER, 'produce')

    expect(JSON.parse(JSON.stringify(store.ranking))).toEqual(before)
  })
})

describe('planetaryPlanner store addProduction with a target', () => {
  it('adds the production with the target of the ranking and selects it', async () => {
    const store = await loadedStore()

    store.addProduction(ROBOTICS, 23)

    expect(store.productions).toEqual([{ typeId: ROBOTICS, targetPerDay: 23 }])
    expect(store.selectedIndex).toBe(0)
  })

  it('adds nothing for a target that is zero, negative or not finite', async () => {
    const store = await loadedStore()

    for (const target of [0, -5, Number.NaN, Number.POSITIVE_INFINITY]) store.addProduction(ROBOTICS, target)

    expect(store.productions).toEqual([])
    expect(store.selectedIndex).toBeNull()
  })

  it('still adds the output of one factory for one day without a target', async () => {
    const store = await loadedStore()

    store.addProduction(ROBOTICS)

    expect(store.productions).toEqual([{ typeId: ROBOTICS, targetPerDay: 72 }])
  })
})

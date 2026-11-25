import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { ExtractionPlanetRow, FactoryPlanetRow } from '@/utils/planetaryPlanets'
import {
  AQUEOUS_LIQUIDS,
  BASE_METALS,
  BROADCAST_NODE,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  REACTIVE_METALS,
  ROBOTICS,
  TOXIC_METALS,
  WATER,
} from '../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ACCOUNT_ERROR_KEY = 'pi.planner.errors.account'

function serve(account: 'ok' | Error) {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/planner/account') {
      if (account instanceof Error) throw account
      return { id: 'account', ...PLANNER_ACCOUNT }
    }
    return { id: 'planner', ...PLANNER_CATALOG }
  }) as never)
}

async function loadedStore() {
  serve('ok')
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  return store
}

function factoryRow(store: ReturnType<typeof usePlanetaryPlannerStore>, tier: string): FactoryPlanetRow {
  const row = store.planetPlan?.rows.find((candidate): candidate is FactoryPlanetRow => candidate.role === 'factory' && candidate.tier === tier)
  if (!row) throw new Error(`No factory row for ${tier}`)
  return row
}

function extractionRow(store: ReturnType<typeof usePlanetaryPlannerStore>, typeId: number): ExtractionPlanetRow {
  const row = store.planetPlan?.rows.find((candidate): candidate is ExtractionPlanetRow => candidate.role === 'extraction' && candidate.typeId === typeId)
  if (!row) throw new Error(`No extraction row for ${typeId}`)
  return row
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPlanner store account', () => {
  it('reads the account endpoint and keeps the planets in use, the characters and the extraction yields', async () => {
    const store = await loadedStore()

    await store.fetchAccount()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/planner', '/planetary/planner/account'])
    expect(store.account).toMatchObject(PLANNER_ACCOUNT)
    expect(store.error).toBeNull()
  })

  it('drops the account and reports an explicit error when the account endpoint fails', async () => {
    serve(new Error('account unavailable'))
    const store = usePlanetaryPlannerStore()
    await store.fetchCatalog()

    await store.fetchAccount()

    expect(store.account).toBeNull()
    expect(store.catalog).not.toBeNull()
    expect([ACCOUNT_ERROR_KEY, i18n.global.t(ACCOUNT_ERROR_KEY)]).toContain(store.error)
  })

  it('does not replace the error of a failing catalog with the account error', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('planner unavailable'))
    const store = usePlanetaryPlannerStore()
    await store.fetchCatalog()

    await store.fetchAccount()

    expect([store.error, store.account]).toEqual(['planner unavailable', null])
  })
})

describe('planetaryPlanner store extraction yield', () => {
  it('keeps the yield the player types per raw resource and feeds the planets of that resource', async () => {
    const store = await loadedStore()
    await store.fetchAccount()
    store.addProduction(ROBOTICS)
    store.setChoice(WATER, 'produce')
    store.setChoice(REACTIVE_METALS, 'produce')
    store.setChoice(TOXIC_METALS, 'produce')
    expect(extractionRow(store, AQUEOUS_LIQUIDS).planets).toBe(4)

    const accepted = store.setExtractionYield(AQUEOUS_LIQUIDS, 9_000)

    expect(accepted).toBe(true)
    expect(store.extractionYieldOverrides).toEqual({ [AQUEOUS_LIQUIDS]: 9_000 })
    expect([extractionRow(store, AQUEOUS_LIQUIDS).yieldSource, extractionRow(store, AQUEOUS_LIQUIDS).planets]).toEqual(['override', 3])
    expect([extractionRow(store, BASE_METALS).yieldSource, extractionRow(store, BASE_METALS).planets]).toEqual(['account', 3])
  })

  it('refuses a yield that is zero, negative or not a number and keeps the previous value', async () => {
    const store = await loadedStore()
    store.setExtractionYield(AQUEOUS_LIQUIDS, 9_000)

    const refused = [store.setExtractionYield(AQUEOUS_LIQUIDS, 0), store.setExtractionYield(AQUEOUS_LIQUIDS, -3), store.setExtractionYield(AQUEOUS_LIQUIDS, Number.NaN)]

    expect(refused).toEqual([false, false, false])
    expect(store.extractionYieldOverrides).toEqual({ [AQUEOUS_LIQUIDS]: 9_000 })
  })
})

describe('planetaryPlanner store planet plan', () => {
  it('has no planet plan before the catalog is loaded', () => {
    const store = usePlanetaryPlannerStore()

    expect(store.planetPlan).toBeNull()
  })

  it('follows the days between rounds: more days need more launchpads of room and more planets', async () => {
    const store = await loadedStore()
    store.addProduction(BROADCAST_NODE)
    store.setTarget(0, 144)
    expect([store.planetPlan?.totalPlanets, factoryRow(store, 'P2').layout?.days, factoryRow(store, 'P2').layout?.factories]).toEqual([7, 4, 13])

    const accepted = store.setDaysBetweenRounds(8)

    expect(accepted).toBe(true)
    expect([store.planetPlan?.totalPlanets, factoryRow(store, 'P2').layout?.days, factoryRow(store, 'P2').layout?.factories]).toEqual([13, 8, 6])
  })
})

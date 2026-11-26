import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiStockCoverageGrid from '@/components/planetary/PiStockCoverageGrid.vue'
import PiStockTab from '@/components/planetary/PiStockTab.vue'
import PiSupplyTab from '@/components/planetary/PiSupplyTab.vue'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'
import type { PlannerCatalog } from '@/utils/planetaryPlanner'
import { COOLANT as STOCK_COOLANT, PLANNER_CATALOG, ROBOTICS } from '../../planetaryPlannerFixtures'
import { PROJECT_NEEDS_WITH_THREE_PRODUCTS } from '../../planetaryProjectNeedsFixtures'
import { AMARR_CANDIDATE, ME_WITH_TWO_FAVORITES, SETTINGS_WITH_AMARR_HOME, apiCalls, serve } from '../../planetarySettingsFixtures'
import { COOLANT_ROW, PLASMA_ROW, ROBOTICS_ROW, stockOf } from '../../planetaryStockFixtures'
import { pendingStockResponse } from '../../planetaryPendingStockFixtures'
import {
  COOLANT as SUPPLY_COOLANT,
  FERTILIZER,
  MECHANICAL_PARTS,
  feedOf,
  launchpadOf,
  supplyResponse,
} from '../../planetarySupplyFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PLANNER_ENDPOINT = '/planetary/planner'

function i18nPlugin() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function supplyCatalog(): PlannerCatalog {
  return {
    ...PLANNER_CATALOG,
    types: [
      { typeId: SUPPLY_COOLANT, name: 'Coolant', tier: 'P2', volume: 1.5 },
      { typeId: MECHANICAL_PARTS, name: 'Mechanical Parts', tier: 'P2', volume: 1.5 },
    ],
  }
}

async function mountSupply(catalog: PlannerCatalog | null) {
  setActivePinia(createPinia())
  usePlanetaryPlannerStore().catalog = catalog
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/input-needs') {
      return supplyResponse([launchpadOf({ feeds: [feedOf(SUPPLY_COOLANT), feedOf(MECHANICAL_PARTS), feedOf(FERTILIZER)] })], true)
    }
    if (endpoint === '/planetary/pending-stock') return pendingStockResponse([])
    return new Promise(() => {})
  }) as never)
  const wrapper = mount(PiSupplyTab, { global: { plugins: [i18nPlugin()] } })
  await flushPromises()
  return wrapper
}

function headerOf(wrapper: Awaited<ReturnType<typeof mountSupply>>, typeId: number) {
  const index = wrapper.findAll('[data-testid="pi-supply-cell"]').findIndex(cell => cell.attributes('data-type-id') === String(typeId))
  return wrapper.findAll('[data-testid="pi-supply-grid"] thead th')[index + 1]
}

enableAutoUnmount(afterEach)

describe('tier badge on the stock grid', () => {
  function mountGrid(catalog: PlannerCatalog | null) {
    setActivePinia(createPinia())
    usePlanetaryStockStore().stock = stockOf([ROBOTICS_ROW, COOLANT_ROW, PLASMA_ROW])
    usePlanetaryPlannerStore().catalog = catalog
    return mount(PiStockCoverageGrid, { global: { plugins: [i18nPlugin()] } })
  }

  it('shows the tier of each type on its row', () => {
    const wrapper = mountGrid(PLANNER_CATALOG)

    expect(wrapper.get(`[data-testid="stock-row-${ROBOTICS}"] [data-testid="tier-badge"]`).text()).toBe('P3')
    expect(wrapper.get(`[data-testid="stock-row-${STOCK_COOLANT}"] [data-testid="tier-badge"]`).text()).toBe('P2')
  })

  it('shows no badge on a row whose type is not in the catalog', () => {
    const wrapper = mountGrid(PLANNER_CATALOG)

    expect(wrapper.find(`[data-testid="stock-row-${PLASMA_ROW.typeId}"] [data-testid="tier-badge"]`).exists()).toBe(false)
  })

  it('shows no badge on any row while the catalog is not loaded, and keeps the rows', () => {
    const wrapper = mountGrid(null)

    expect(wrapper.findAll('[data-testid="tier-badge"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid^="stock-row-"]')).toHaveLength(3)
  })
})

describe('tier badge on the supply grid', () => {
  it('shows the tier of each fed type in its column header', async () => {
    const wrapper = await mountSupply(supplyCatalog())

    expect(headerOf(wrapper, SUPPLY_COOLANT).get('[data-testid="tier-badge"]').text()).toBe('P2')
    expect(headerOf(wrapper, MECHANICAL_PARTS).get('[data-testid="tier-badge"]').text()).toBe('P2')
  })

  it('shows no badge on a column whose type is not in the catalog', async () => {
    const wrapper = await mountSupply(supplyCatalog())

    expect(headerOf(wrapper, FERTILIZER).find('[data-testid="tier-badge"]').exists()).toBe(false)
  })

  it('loads the planner catalog when it is missing and badges the columns once it arrives', async () => {
    setActivePinia(createPinia())
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
      if (endpoint === '/planetary/input-needs') return supplyResponse([launchpadOf({ feeds: [feedOf(SUPPLY_COOLANT)] })], true)
      if (endpoint === '/planetary/pending-stock') return pendingStockResponse([])
      if (endpoint === PLANNER_ENDPOINT) return supplyCatalog()
      throw new Error(`Unexpected request ${endpoint}`)
    }) as never)
    const wrapper = mount(PiSupplyTab, { global: { plugins: [i18nPlugin()] } })
    await flushPromises()

    expect(headerOf(wrapper, SUPPLY_COOLANT).get('[data-testid="tier-badge"]').text()).toBe('P2')
  })

  it('does not request the planner catalog again when it is already loaded', async () => {
    await mountSupply(supplyCatalog())

    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === PLANNER_ENDPOINT)).toHaveLength(0)
  })
})

describe('tier badge on the stock tab', () => {
  it('loads the planner catalog when it is missing and badges the rows once it arrives', async () => {
    setActivePinia(createPinia())
    serve({
      'GET /planetary/settings': SETTINGS_WITH_AMARR_HOME,
      'GET /planetary/settings/locations': { id: 'locations', locations: [AMARR_CANDIDATE] },
      'GET /me/settings': ME_WITH_TWO_FAVORITES,
      'GET /planetary/stock': stockOf([ROBOTICS_ROW]),
      'GET /planetary/project-needs': PROJECT_NEEDS_WITH_THREE_PRODUCTS,
      [`GET ${PLANNER_ENDPOINT}`]: PLANNER_CATALOG as never,
    })
    const wrapper = mount(PiStockTab, { global: { plugins: [i18nPlugin()], stubs: { RouterLink: true } } })
    await flushPromises()

    expect(apiCalls().filter(call => call.endpoint === PLANNER_ENDPOINT)).toHaveLength(1)
    expect(wrapper.get(`[data-testid="stock-row-${ROBOTICS}"] [data-testid="tier-badge"]`).text()).toBe('P3')
  })
})

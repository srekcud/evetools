import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiStockCoverageGrid from '@/components/planetary/PiStockCoverageGrid.vue'
import PiStockPriceGrid from '@/components/planetary/PiStockPriceGrid.vue'
import PiSupplyTab from '@/components/planetary/PiSupplyTab.vue'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'
import { usePlanetaryStockPricesStore } from '@/stores/planetaryStockPrices'
import type { PlannerCatalog } from '@/utils/planetaryPlanner'
import { COOLANT, PLANNER_CATALOG, ROBOTICS, WATER } from '../../planetaryPlannerFixtures'
import { COOLANT_ROW, ROBOTICS_ROW, UNKNOWN_EXTRACTION_ROW, WATER_ROW, stockOf, stockRow } from '../../planetaryStockFixtures'
import { priceRow, pricesOf } from '../../planetaryStockPricesFixtures'
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

const RED_BAR = 'bg-red-500'
const GREEN_BAR = 'bg-emerald-500'

function i18nPlugin() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function mountCoverage(rows = [ROBOTICS_ROW, COOLANT_ROW, WATER_ROW, UNKNOWN_EXTRACTION_ROW]) {
  setActivePinia(createPinia())
  usePlanetaryPlannerStore().catalog = PLANNER_CATALOG
  usePlanetaryStockStore().stock = stockOf(rows)
  return mount(PiStockCoverageGrid, { global: { plugins: [i18nPlugin()] } })
}

function mountPrices() {
  setActivePinia(createPinia())
  usePlanetaryPlannerStore().catalog = PLANNER_CATALOG
  usePlanetaryStockStore().stock = stockOf([])
  usePlanetaryStockPricesStore().prices = pricesOf([
    priceRow(WATER, 'Water'),
    priceRow(COOLANT, 'Coolant'),
    priceRow(ROBOTICS, 'Robotics'),
    priceRow(UNKNOWN_EXTRACTION_ROW.typeId, 'Bacteria'),
  ])
  return mount(PiStockPriceGrid, { global: { plugins: [i18nPlugin()] } })
}

function supplyCatalog(): PlannerCatalog {
  return {
    ...PLANNER_CATALOG,
    types: [
      { typeId: SUPPLY_COOLANT, name: 'Coolant', tier: 'P2', volume: 1.5 },
      { typeId: MECHANICAL_PARTS, name: 'Mechanical Parts', tier: 'P2', volume: 1.5 },
      { typeId: FERTILIZER, name: 'Fertilizer', tier: 'P1', volume: 0.38 },
    ],
  }
}

async function mountSupply() {
  setActivePinia(createPinia())
  usePlanetaryPlannerStore().catalog = supplyCatalog()
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

async function pickTier(wrapper: VueWrapper, filterTestId: string, tier: string) {
  await wrapper.get(`[data-testid="${filterTestId}"] [data-tier="${tier}"]`).trigger('click')
}

enableAutoUnmount(afterEach)

describe('tier filter on the coverage grid', () => {
  it('shows every type by default, only the picked tier when one is picked, and every type again on All', async () => {
    const wrapper = mountCoverage()
    expect(wrapper.findAll('[data-testid^="stock-row-"]')).toHaveLength(4)

    await pickTier(wrapper, 'stock-tier-filter', 'P2')
    expect(wrapper.findAll('[data-testid^="stock-row-"]').map(row => row.attributes('data-testid'))).toEqual([`stock-row-${COOLANT}`])

    await pickTier(wrapper, 'stock-tier-filter', 'all')
    expect(wrapper.findAll('[data-testid^="stock-row-"]')).toHaveLength(4)
  })

  it('marks the picked tier as pressed', async () => {
    const wrapper = mountCoverage()

    await pickTier(wrapper, 'stock-tier-filter', 'P3')

    expect(wrapper.get('[data-testid="stock-tier-filter"] [data-tier="P3"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-testid="stock-tier-filter"] [data-tier="all"]').attributes('aria-pressed')).toBe('false')
  })
})

describe('tier filter on prices and signals', () => {
  it('shows only the types of the picked tier', async () => {
    const wrapper = mountPrices()
    expect(wrapper.findAll('[data-testid^="price-row-"]')).toHaveLength(4)

    await pickTier(wrapper, 'price-tier-filter', 'P1')

    expect(wrapper.findAll('[data-testid^="price-row-"]').map(row => row.attributes('data-testid'))).toEqual([`price-row-${WATER}`])
  })
})

describe('tier filter on the launchpad table', () => {
  it('shows only the input columns of the picked tier', async () => {
    const wrapper = await mountSupply()
    expect(wrapper.findAll('[data-testid="pi-supply-cell"]')).toHaveLength(3)

    await pickTier(wrapper, 'supply-tier-filter', 'P1')

    const cells = wrapper.findAll('[data-testid="pi-supply-cell"]')
    expect(cells.map(cell => cell.attributes('data-type-id'))).toEqual([String(FERTILIZER)])
    expect(wrapper.findAll('[data-testid="pi-supply-total-cell"]').map(cell => cell.attributes('data-type-id'))).toEqual([String(FERTILIZER)])
  })
})

describe('coverage bar on the coverage with orders', () => {
  it('draws the bar against the target, green at the target and red below it', () => {
    const below = stockRow(WATER, 'Water', { coverageWeeks: 1, coverageWithOrdersWeeks: 1 })
    const wrapper = mountCoverage([COOLANT_ROW, below])

    expect(wrapper.get(`[data-testid="stock-coverage-orders-bar-${COOLANT}"]`).classes()).toContain(GREEN_BAR)
    expect(wrapper.get(`[data-testid="stock-coverage-orders-bar-${WATER}"]`).classes()).toContain(RED_BAR)
  })

  it('draws no bar when the coverage with orders is unknown', () => {
    const unknown = stockRow(WATER, 'Water', { coverageWeeks: null, coverageWithOrdersWeeks: null })
    const wrapper = mountCoverage([unknown])

    expect(wrapper.find(`[data-testid="stock-coverage-orders-bar-${WATER}"]`).exists()).toBe(false)
  })
})

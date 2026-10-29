import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingTab from '@/components/industry/ShoppingTab.vue'
import { apiRequest } from '@/services/api'
import type { ShoppingListItem, ShoppingListResponse } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-194'
const SHOPPING_LIST_URL = `/industry/projects/${PROJECT_ID}/shopping-list`
const SYNC_STRUCTURE_MARKET_URL = '/shopping-list/sync-structure-market'
const STRUCTURE_STORAGE_KEY = 'industry_shopping_structure'
const PREFERRED_MARKET_STRUCTURE_ID = 1_000_000_000_002
const PREFERRED_MARKET_STRUCTURE_NAME = 'Preferred Market'
const REQUESTED_MARKET_STRUCTURE_ID = 1_000_000_000_003
const PICKED_MARKET_STRUCTURE_ID = 1_000_000_000_004
const TRANSPORT_COST_PER_M3_OF_THE_SETTINGS = 800
const TYPED_TRANSPORT_COST_PER_M3 = 650

const NITROGEN_ISOTOPES: ShoppingListItem = {
  typeId: 17888,
  typeName: 'Nitrogen Isotopes',
  quantity: 405,
  volume: 0.5,
  totalVolume: 202.5,
  jitaUnitPrice: 500,
  jitaTotal: 202_500,
  importCost: 162_000,
  jitaWithImport: 364_500,
  structureUnitPrice: 1_200,
  structureTotal: 486_000,
  bestLocation: 'jita',
  bestPrice: 364_500,
  savings: 121_500,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

const SHOPPING_LIST_AT_THE_PREFERRED_MARKET_STRUCTURE: ShoppingListResponse = {
  materials: [NITROGEN_ISOTOPES],
  structureId: PREFERRED_MARKET_STRUCTURE_ID,
  structureName: PREFERRED_MARKET_STRUCTURE_NAME,
  structureAccessible: true,
  structureFromCache: true,
  structureLastSync: null,
  transportCostPerM3: TRANSPORT_COST_PER_M3_OF_THE_SETTINGS,
  totals: {
    jita: 202_500,
    import: 162_000,
    jitaWithImport: 364_500,
    structure: 486_000,
    volume: 202.5,
    best: 364_500,
    savingsVsJitaWithImport: 0,
    savingsVsStructure: 121_500,
  },
  priceError: null,
  missingVolumeTypeIds: [],
  failedProducts: [],
  missingPriceTypeIds: [],
}

interface ShoppingTabExposed {
  activate: () => Promise<void>
}

async function mountActivatedShoppingTab() {
  vi.mocked(apiRequest).mockImplementation(async (url: string) => {
    if (url === SYNC_STRUCTURE_MARKET_URL) return { status: 'queued' }
    if (url.startsWith(SHOPPING_LIST_URL)) return SHOPPING_LIST_AT_THE_PREFERRED_MARKET_STRUCTURE
    throw new Error(`Unexpected request ${url}`)
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ShoppingTab, {
    global: {
      plugins: [i18n],
      stubs: { ShoppingConfigPanel: true, ShoppingTotalsSummary: true, ShoppingMaterialsTable: true, ShoppingPurchasesPanel: true },
    },
    props: { projectId: PROJECT_ID },
  })
  await (wrapper.vm as unknown as ShoppingTabExposed).activate()
  await flushPromises()
  return wrapper
}

function configPanelOf(wrapper: VueWrapper) {
  return wrapper.findComponent({ name: 'ShoppingConfigPanel' })
}

async function emitFromConfigPanel(wrapper: VueWrapper, event: string, ...payload: unknown[]): Promise<void> {
  configPanelOf(wrapper).vm.$emit(event, ...payload)
  await flushPromises()
}

function shoppingListUrls(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([url]) => url)
    .filter(url => url.startsWith(SHOPPING_LIST_URL))
}

function lastShoppingListUrl(): string | undefined {
  const urls = shoppingListUrls()
  return urls[urls.length - 1]
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  localStorage.clear()
})

describe('ShoppingTab market structure and transport cost per m3 without a one-off choice', () => {
  it('asks the shopping list without market structure nor transport cost and shows the ones of the response', async () => {
    const wrapper = await mountActivatedShoppingTab()

    expect(shoppingListUrls()).toEqual([SHOPPING_LIST_URL])
    expect(configPanelOf(wrapper).props('transportCostPerM3')).toBe(TRANSPORT_COST_PER_M3_OF_THE_SETTINGS)
    expect(configPanelOf(wrapper).props('effectiveStructureName')).toBe(PREFERRED_MARKET_STRUCTURE_NAME)
    expect(configPanelOf(wrapper).props('selectedStructure')).toEqual({ id: null, name: '' })
  })

  it('syncs the structure market without a market structure', async () => {
    const wrapper = await mountActivatedShoppingTab()

    await emitFromConfigPanel(wrapper, 'sync-structure')

    expect(vi.mocked(apiRequest).mock.calls.filter(([url]) => url === SYNC_STRUCTURE_MARKET_URL)).toEqual([
      [SYNC_STRUCTURE_MARKET_URL, { method: 'POST', body: '{}' }],
    ])
  })
})

describe('ShoppingTab one-off market structure and transport cost per m3', () => {
  it('asks the shopping list at the market structure stored in the browser without transport cost', async () => {
    localStorage.setItem(STRUCTURE_STORAGE_KEY, JSON.stringify({ id: REQUESTED_MARKET_STRUCTURE_ID, name: 'Requested Market' }))

    await mountActivatedShoppingTab()

    expect(shoppingListUrls()).toEqual([`${SHOPPING_LIST_URL}?structureId=${REQUESTED_MARKET_STRUCTURE_ID}`])
  })

  it('asks the shopping list with the typed transport cost only and keeps showing it after the response', async () => {
    const wrapper = await mountActivatedShoppingTab()

    await emitFromConfigPanel(wrapper, 'update:transportCostPerM3', TYPED_TRANSPORT_COST_PER_M3)
    expect(configPanelOf(wrapper).props('transportCostPerM3')).toBe(TYPED_TRANSPORT_COST_PER_M3)
    await emitFromConfigPanel(wrapper, 'recalculate')

    expect(lastShoppingListUrl()).toBe(`${SHOPPING_LIST_URL}?transportCost=${TYPED_TRANSPORT_COST_PER_M3}`)
    expect(configPanelOf(wrapper).props('transportCostPerM3')).toBe(TYPED_TRANSPORT_COST_PER_M3)
  })

  it('asks the shopping list at the picked market structure only and stores it in the browser', async () => {
    const wrapper = await mountActivatedShoppingTab()
    const pickedMarketStructure = { id: PICKED_MARKET_STRUCTURE_ID, name: 'Picked Market' }

    await emitFromConfigPanel(wrapper, 'update:selectedStructure', pickedMarketStructure)
    await emitFromConfigPanel(wrapper, 'recalculate')

    expect(lastShoppingListUrl()).toBe(`${SHOPPING_LIST_URL}?structureId=${PICKED_MARKET_STRUCTURE_ID}`)
    expect(JSON.parse(localStorage.getItem(STRUCTURE_STORAGE_KEY) ?? 'null')).toEqual(pickedMarketStructure)
  })

  it('asks the shopping list without parameter once the picked market structure is cleared and forgets it in the browser', async () => {
    const wrapper = await mountActivatedShoppingTab()

    await emitFromConfigPanel(wrapper, 'update:selectedStructure', { id: PICKED_MARKET_STRUCTURE_ID, name: 'Picked Market' })
    await emitFromConfigPanel(wrapper, 'recalculate')
    await emitFromConfigPanel(wrapper, 'update:selectedStructure', { id: null, name: '' })
    await emitFromConfigPanel(wrapper, 'recalculate')

    expect(lastShoppingListUrl()).toBe(SHOPPING_LIST_URL)
    expect(localStorage.getItem(STRUCTURE_STORAGE_KEY)).toBeNull()
  })
})

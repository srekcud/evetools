import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ShoppingTab from '@/components/industry/ShoppingTab.vue'
import { apiRequest } from '@/services/api'
import type { ShoppingListItem, ShoppingListResponse, ShoppingListTotals } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-8'
const STOCK_STORAGE_KEY = `industry_stock_${PROJECT_ID}`
const NITROGEN_ISOTOPES_TYPE_ID = 17888
const ROBOTICS_TYPE_ID = 9848
const COOLANT_TYPE_ID = 9832
const MARKET_UNREACHABLE = 'Jita market unreachable'

const NITROGEN_ISOTOPES: ShoppingListItem = {
  typeId: NITROGEN_ISOTOPES_TYPE_ID,
  typeName: 'Nitrogen Isotopes',
  quantity: 405,
  volume: 0.03,
  totalVolume: 12.15,
  jitaUnitPrice: 500,
  jitaTotal: 202_500,
  importCost: 14_580,
  jitaWithImport: 217_080,
  structureUnitPrice: 560,
  structureTotal: 226_800,
  bestLocation: 'jita',
  bestPrice: 217_080,
  savings: 9_720,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

const ROBOTICS_WITHOUT_PRICE: ShoppingListItem = {
  typeId: ROBOTICS_TYPE_ID,
  typeName: 'Robotics',
  quantity: 1,
  volume: 6,
  totalVolume: 6,
  jitaUnitPrice: null,
  jitaTotal: null,
  importCost: 7_200,
  jitaWithImport: null,
  structureUnitPrice: null,
  structureTotal: null,
  bestLocation: null,
  bestPrice: null,
  savings: null,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

const COOLANT_WITHOUT_STRUCTURE_PRICE: ShoppingListItem = {
  typeId: COOLANT_TYPE_ID,
  typeName: 'Coolant',
  quantity: 9,
  volume: 0.75,
  totalVolume: 6.75,
  jitaUnitPrice: 150,
  jitaTotal: 1_350,
  importCost: 8_100,
  jitaWithImport: 9_450,
  structureUnitPrice: null,
  structureTotal: null,
  bestLocation: 'jita',
  bestPrice: 9_450,
  savings: null,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

const TOTALS_WITH_ROBOTICS_WITHOUT_PRICE: ShoppingListTotals = {
  jita: null,
  import: 29_880,
  jitaWithImport: null,
  structure: null,
  volume: 24.9,
  best: null,
  savingsVsJitaWithImport: 0,
  savingsVsStructure: 9_720,
}

function shoppingListResponse(materials: ShoppingListItem[], totals: ShoppingListTotals, missingPriceTypeIds: number[], priceError: string | null = null): ShoppingListResponse {
  return {
    materials,
    structureId: 1049588174021,
    structureName: 'C-J6MT - 1st Taj Mahgoon',
    structureAccessible: true,
    structureFromCache: false,
    structureLastSync: null,
    transportCostPerM3: 1200,
    totals,
    priceError,
    missingVolumeTypeIds: [],
    failedProducts: [],
    missingPriceTypeIds,
  }
}

const SHOPPING_LIST_WITH_ROBOTICS_WITHOUT_PRICE = shoppingListResponse(
  [NITROGEN_ISOTOPES, ROBOTICS_WITHOUT_PRICE, COOLANT_WITHOUT_STRUCTURE_PRICE],
  TOTALS_WITH_ROBOTICS_WITHOUT_PRICE,
  [ROBOTICS_TYPE_ID],
)

const SHOPPING_LIST_WITHOUT_MARKET = shoppingListResponse(
  [
    { ...NITROGEN_ISOTOPES, jitaUnitPrice: null, jitaTotal: null, jitaWithImport: null, structureUnitPrice: null, structureTotal: null, bestLocation: null, bestPrice: null, savings: null },
    ROBOTICS_WITHOUT_PRICE,
  ],
  { jita: null, import: 21_780, jitaWithImport: null, structure: null, volume: 18.15, best: null, savingsVsJitaWithImport: null, savingsVsStructure: null },
  [ROBOTICS_TYPE_ID, NITROGEN_ISOTOPES_TYPE_ID],
  MARKET_UNREACHABLE,
)

interface ShoppingTabExposed {
  loadShoppingList: () => Promise<void>
  loadPersistedStock: () => void
}

function persistPastedStock(stock: { name: string; quantity: number }[]): void {
  localStorage.setItem(STOCK_STORAGE_KEY, JSON.stringify({
    raw: stock.map(({ name, quantity }) => `${name}\t${quantity}`).join('\n'),
    parsed: stock,
  }))
}

async function mountLoadedShoppingTab(shoppingList: ShoppingListResponse, locale: 'en' | 'fr' = 'en') {
  vi.mocked(apiRequest).mockImplementation(async (url: string) => {
    if (url.includes('/shopping-list')) return shoppingList
    throw new Error(`Unexpected request ${url}`)
  })
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ShoppingTab, {
    global: {
      plugins: [i18n],
      stubs: { ShoppingConfigPanel: true, ShoppingMaterialsTable: true, ShoppingPurchasesPanel: true },
    },
    props: { projectId: PROJECT_ID },
  })
  const exposed = wrapper.vm as unknown as ShoppingTabExposed
  exposed.loadPersistedStock()
  await exposed.loadShoppingList()
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

function totalCell(wrapper: VueWrapper, label: string): DOMWrapper<Element> {
  const labelElement = wrapper.findAll('div').find(element => element.text() === label)
  expect(labelElement, `total ${label}`).toBeDefined()
  return new DOMWrapper(labelElement!.element.parentElement!)
}

function expectUnknownAmount(cell: DOMWrapper<Element>, unknownValueTitle: string): void {
  expect(cell.get(`[title="${unknownValueTitle}"]`).text()).toBe('—')
  expect(cell.text()).not.toMatch(/\d/)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  localStorage.clear()
})

describe('ShoppingTab totals of the missing materials with a material without a price', () => {
  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows the Jita + import, structure and best totals as unknown when a missing material has no price (%s)', async (locale, unknownValueTitle) => {
    persistPastedStock([{ name: 'Nitrogen Isotopes', quantity: 105 }])
    const { wrapper, t } = await mountLoadedShoppingTab(SHOPPING_LIST_WITH_ROBOTICS_WITHOUT_PRICE, locale)

    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.jitaImport')), unknownValueTitle)
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.structure')), unknownValueTitle)
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.bestTotal')), unknownValueTitle)
  })

  it('knows the best and Jita + import totals once the material without a price is in stock, and leaves the structure total unknown for a missing material without structure price', async () => {
    persistPastedStock([{ name: 'Nitrogen Isotopes', quantity: 105 }, { name: 'Robotics', quantity: 1 }])
    const { wrapper, t } = await mountLoadedShoppingTab(SHOPPING_LIST_WITH_ROBOTICS_WITHOUT_PRICE)

    expect(totalCell(wrapper, t('industry.shoppingTab.bestTotal')).text()).toContain('170.25 K')
    expect(totalCell(wrapper, t('industry.shoppingTab.jitaImport')).text()).toContain('170.25 K')
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.structure')), 'Unknown value')
  })
})

describe('ShoppingTab warning of the materials without a price', () => {
  it.each([
    ['en'],
    ['fr'],
  ] as const)('names the materials without a price (%s)', async locale => {
    const { wrapper, t } = await mountLoadedShoppingTab(SHOPPING_LIST_WITH_ROBOTICS_WITHOUT_PRICE, locale)

    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missingPriceTypes', { types: 'Robotics' }))
  })

  it('does not list every material without a price when the market is unreachable, and keeps the best total unknown', async () => {
    const { wrapper, t } = await mountLoadedShoppingTab(SHOPPING_LIST_WITHOUT_MARKET)

    expect(wrapper.text()).not.toContain(t('industry.inventionUnknown.missingPriceTypes', { types: '' }).trim())
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.bestTotal')), 'Unknown value')
  })
})

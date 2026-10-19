import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingTab from '@/components/industry/ShoppingTab.vue'
import { apiRequest } from '@/services/api'
import type { ShoppingListFailedProduct, ShoppingListItem, ShoppingListResponse } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1'
const UNNAMED_TYPE_ID = 9848
const FAILED_REASON = `No blueprint or reaction found for product type ID ${UNNAMED_TYPE_ID}`

const TRITANIUM: ShoppingListItem = {
  typeId: 34,
  typeName: 'Tritanium',
  quantity: 100,
  volume: 0.01,
  totalVolume: 1,
  jitaUnitPrice: 5,
  jitaTotal: 500,
  importCost: 1200,
  jitaWithImport: 1700,
  structureUnitPrice: null,
  structureTotal: null,
  bestLocation: 'jita',
  bestPrice: 1700,
  savings: null,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

function shoppingListResponse(failedProducts: ShoppingListFailedProduct[]): ShoppingListResponse {
  return {
    materials: [TRITANIUM],
    structureId: 1049588174021,
    structureName: 'C-J6MT - 1st Taj Mahgoon',
    structureAccessible: true,
    structureFromCache: false,
    structureLastSync: null,
    transportCostPerM3: 1200,
    totals: {
      jita: 500,
      import: 1200,
      jitaWithImport: 1700,
      structure: 0,
      volume: 1,
      best: 0,
      savingsVsJitaWithImport: null,
      savingsVsStructure: 0,
    },
    priceError: null,
    missingVolumeTypeIds: [],
    failedProducts,
  }
}

async function mountLoadedShoppingTab(failedProducts: ShoppingListFailedProduct[]) {
  vi.mocked(apiRequest).mockResolvedValue(shoppingListResponse(failedProducts))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ShoppingTab, {
    global: {
      plugins: [i18n],
      stubs: { ShoppingConfigPanel: true, ShoppingTotalsSummary: true, ShoppingMaterialsTable: true, ShoppingPurchasesPanel: true },
    },
    props: { projectId: PROJECT_ID },
  })
  await (wrapper.vm as unknown as { loadShoppingList: () => Promise<void> }).loadShoppingList()
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ShoppingTab', () => {
  it('shows the failed products returned by the shopping list', async () => {
    const wrapper = await mountLoadedShoppingTab([{ typeId: UNNAMED_TYPE_ID, reason: FAILED_REASON }])

    expect(apiRequest).toHaveBeenCalledWith(expect.stringContaining(`/industry/projects/${PROJECT_ID}/shopping-list`))
    const warning = wrapper.find('[data-testid="failed-products-warning"]')
    expect(warning.exists()).toBe(true)
    expect(warning.text()).toContain(String(UNNAMED_TYPE_ID))
    expect(warning.text()).toContain(FAILED_REASON)
  })

  it('shows no failed products warning when every product is in the shopping list', async () => {
    const wrapper = await mountLoadedShoppingTab([])

    expect(wrapper.findComponent({ name: 'ShoppingMaterialsTable' }).exists()).toBe(true)
    expect(wrapper.find('[data-testid="failed-products-warning"]').exists()).toBe(false)
  })
})

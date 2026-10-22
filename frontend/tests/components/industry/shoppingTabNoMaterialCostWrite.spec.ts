import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingTab from '@/components/industry/ShoppingTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, ShoppingListFailedProduct, ShoppingListItem, ShoppingListResponse } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-177'
const STOCK_STORAGE_KEY = `industry_stock_${PROJECT_ID}`
const SHOPPING_LIST_BEST_TOTAL = 123456789
const ROUNDED_MATERIAL_COST = 124000000

const PYERITE: ShoppingListItem = {
  typeId: 35,
  typeName: 'Pyerite',
  quantity: 700,
  volume: 0.01,
  totalVolume: 7,
  jitaUnitPrice: 176366.84,
  jitaTotal: SHOPPING_LIST_BEST_TOTAL,
  importCost: 0,
  jitaWithImport: SHOPPING_LIST_BEST_TOTAL,
  structureUnitPrice: null,
  structureTotal: null,
  bestLocation: 'jita',
  bestPrice: SHOPPING_LIST_BEST_TOTAL,
  savings: null,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

const SHOPPING_LIST: ShoppingListResponse = {
  materials: [PYERITE],
  structureId: 1049588174021,
  structureName: 'C-J6MT - 1st Taj Mahgoon',
  structureAccessible: true,
  structureFromCache: false,
  structureLastSync: null,
  transportCostPerM3: 1200,
  totals: {
    jita: SHOPPING_LIST_BEST_TOTAL,
    import: 0,
    jitaWithImport: SHOPPING_LIST_BEST_TOTAL,
    structure: 0,
    volume: 7,
    best: SHOPPING_LIST_BEST_TOTAL,
    savingsVsJitaWithImport: null,
    savingsVsStructure: 0,
  },
  priceError: null,
  missingVolumeTypeIds: [],
  failedProducts: [],
}

function activeProject(materialCost: number | null): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Ishtar',
    productTypeId: 12005,
    displayName: null,
    runs: 1,
    meLevel: 10,
    teLevel: 20,
    maxJobDurationDays: 2,
    status: 'active',
    personalUse: false,
    isT2: false,
    bpoCost: null,
    materialCost,
    transportCost: null,
    jobsCost: null,
    taxAmount: null,
    sellPrice: null,
    totalCost: null,
    estimatedJobCost: null,
    estimatedSellPrice: null,
    estimatedSellPriceSource: null,
    estimatedTaxAmount: null,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-10-22T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps: [],
    tree: null,
  }
}

interface ShoppingTabExposed {
  loadShoppingList: () => Promise<void>
  loadPersistedStock: () => void
  reanalyzeStock: () => Promise<void>
}

const PYERITE_FOR_100_RUNS: ShoppingListItem = { ...PYERITE, quantity: 100 }

const TRITANIUM_WITHOUT_PRICE: ShoppingListItem = {
  ...PYERITE,
  typeId: 34,
  typeName: 'Tritanium',
  quantity: 250,
  jitaUnitPrice: null,
  jitaTotal: null,
  jitaWithImport: null,
  structureTotal: null,
  bestLocation: null,
  bestPrice: null,
}

const FAILED_PRODUCT: ShoppingListFailedProduct = { typeId: 12005, reason: 'blueprint not found' }

function shoppingListOf(materials: ShoppingListItem[], failedProducts: ShoppingListFailedProduct[] = []): ShoppingListResponse {
  return { ...SHOPPING_LIST, materials, failedProducts }
}

function mountShoppingTab(materialCost: number | null, shoppingList: ShoppingListResponse = SHOPPING_LIST) {
  vi.mocked(apiRequest).mockImplementation(async (url: string, options?: RequestInit) => {
    if (url.includes('/shopping-list')) return shoppingList
    if (options?.method === 'PATCH') return { ...activeProject(null), ...JSON.parse(String(options.body)) }
    throw new Error(`Unexpected request ${url}`)
  })
  useProjectsStore().currentProject = activeProject(materialCost)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ShoppingTab, {
    global: {
      plugins: [i18n],
      stubs: { ShoppingConfigPanel: true, ShoppingTotalsSummary: true, ShoppingMaterialsTable: true, ShoppingPurchasesPanel: true },
    },
    props: { projectId: PROJECT_ID },
  })
  return { wrapper, exposed: wrapper.vm as unknown as ShoppingTabExposed }
}

function applyButton(wrapper: ReturnType<typeof mountShoppingTab>['wrapper']) {
  const buttons = wrapper.findAll('button').filter(button => button.text() === en.industry.shoppingTab.applyAsMaterialCost)
  expect(buttons).toHaveLength(1)
  return buttons[0]
}

function materialCostUnknownReason(): string {
  const reason = (en.industry.shoppingTab as Record<string, string>).materialCostUnknown
  expect(reason).toBeTruthy()
  return reason
}

function projectWrites() {
  return vi.mocked(apiRequest).mock.calls.filter(([url, options]) =>
    url === `/industry/projects/${PROJECT_ID}` && options?.method !== undefined && options.method !== 'GET',
  )
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  localStorage.clear()
})

describe('ShoppingTab material cost', () => {
  it('does not write the material cost when the shopping list loads on a project without material cost', async () => {
    const { exposed } = mountShoppingTab(null)

    await exposed.loadShoppingList()
    await flushPromises()

    expect(projectWrites()).toEqual([])
  })

  it('does not write the material cost when the shopping list loads on a project with a zero material cost', async () => {
    const { exposed } = mountShoppingTab(0)

    await exposed.loadShoppingList()
    await flushPromises()

    expect(projectWrites()).toEqual([])
  })

  it('does not write the material cost when the persisted stock reloads the shopping list', async () => {
    localStorage.setItem(STOCK_STORAGE_KEY, JSON.stringify({
      raw: 'Pyerite\t10',
      parsed: [{ name: 'Pyerite', quantity: 10 }],
    }))
    const { exposed } = mountShoppingTab(null)

    exposed.loadPersistedStock()
    await exposed.reanalyzeStock()
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(expect.stringContaining(`/industry/projects/${PROJECT_ID}/shopping-list`))
    expect(projectWrites()).toEqual([])
  })

  it('writes the rounded shopping list total as material cost when the user applies it', async () => {
    const { wrapper, exposed } = mountShoppingTab(null)
    await exposed.loadShoppingList()
    await flushPromises()
    vi.mocked(apiRequest).mockClear()

    const applyButtons = wrapper.findAll('button').filter(button => button.text() === en.industry.shoppingTab.applyAsMaterialCost)
    expect(applyButtons).toHaveLength(1)
    await applyButtons[0].trigger('click')
    await flushPromises()

    const writes = projectWrites()
    expect(writes).toHaveLength(1)
    expect(writes[0][1]?.method).toBe('PATCH')
    expect(JSON.parse(String(writes[0][1]?.body))).toEqual({ materialCost: ROUNDED_MATERIAL_COST })
  })

  it('writes the full rounded total as material cost when 99 of 100 Pyerite are pasted in stock', async () => {
    localStorage.setItem(STOCK_STORAGE_KEY, JSON.stringify({ raw: 'Pyerite\t99', parsed: [{ name: 'Pyerite', quantity: 99 }] }))
    const { wrapper, exposed } = mountShoppingTab(null, shoppingListOf([PYERITE_FOR_100_RUNS]))
    exposed.loadPersistedStock()
    await exposed.loadShoppingList()
    await flushPromises()
    vi.mocked(apiRequest).mockClear()

    await applyButton(wrapper).trigger('click')
    await flushPromises()

    expect(projectWrites()).toHaveLength(1)
    expect(JSON.parse(String(projectWrites()[0][1]?.body))).toEqual({ materialCost: ROUNDED_MATERIAL_COST })
  })

  it('writes the full rounded total as material cost when 100 of 100 Pyerite are pasted in stock', async () => {
    localStorage.setItem(STOCK_STORAGE_KEY, JSON.stringify({ raw: 'Pyerite\t100', parsed: [{ name: 'Pyerite', quantity: 100 }] }))
    const { wrapper, exposed } = mountShoppingTab(null, shoppingListOf([PYERITE_FOR_100_RUNS]))
    exposed.loadPersistedStock()
    await exposed.loadShoppingList()
    await flushPromises()
    vi.mocked(apiRequest).mockClear()

    await applyButton(wrapper).trigger('click')
    await flushPromises()

    expect(projectWrites()).toHaveLength(1)
    expect(JSON.parse(String(projectWrites()[0][1]?.body))).toEqual({ materialCost: ROUNDED_MATERIAL_COST })
  })

  it('disables the apply button with the unknown cost reason when a material has no best price', async () => {
    const { wrapper, exposed } = mountShoppingTab(null, shoppingListOf([PYERITE_FOR_100_RUNS, TRITANIUM_WITHOUT_PRICE]))
    await exposed.loadShoppingList()
    await flushPromises()
    vi.mocked(apiRequest).mockClear()

    const button = applyButton(wrapper)
    await button.trigger('click')
    await flushPromises()

    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('title')).toBe(materialCostUnknownReason())
    expect(projectWrites()).toEqual([])
  })

  it('disables the apply button with the unknown cost reason when a product failed to resolve', async () => {
    const { wrapper, exposed } = mountShoppingTab(null, shoppingListOf([PYERITE_FOR_100_RUNS], [FAILED_PRODUCT]))
    await exposed.loadShoppingList()
    await flushPromises()
    vi.mocked(apiRequest).mockClear()

    const button = applyButton(wrapper)
    await button.trigger('click')
    await flushPromises()

    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('title')).toBe(materialCostUnknownReason())
    expect(projectWrites()).toEqual([])
  })
})

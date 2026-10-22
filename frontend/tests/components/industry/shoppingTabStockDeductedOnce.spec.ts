import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingTab from '@/components/industry/ShoppingTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, IndustryProjectStep, ShoppingListItem, ShoppingListResponse } from '@/stores/industry/types'
import type { EnrichedShoppingItem } from '@/components/industry/ShoppingTab.vue'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-173'
const ROOT_TYPE_ID = 12005
const ROOT_NAME = 'Ishtar'
const INTERMEDIATE_TYPE_ID = 11694
const INTERMEDIATE_BLUEPRINT_TYPE_ID = 17330
const INTERMEDIATE_NAME = 'Fusion Reactor Unit'
const RAW_MATERIAL_TYPE_ID = 34
const RAW_MATERIAL_NAME = 'Tritanium'

function step(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth'>): IndustryProjectStep {
  return {
    activityType: 'manufacturing',
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 10,
    teLevel: 20,
    jobMatchMode: 'auto',
    structureConfigId: null,
    structureConfigName: null,
    structureMaterialBonus: null,
    structureTimeBonus: null,
    favoriteSystemWithoutSuitableStructure: false,
    timePerRun: null,
    recommendedCharacterName: null,
    splitGroupId: null,
    splitIndex: 0,
    totalGroupRuns: null,
    facilityInfoType: null,
    actualFacilityName: null,
    bestStructureName: null,
    bestMaterialBonus: null,
    jobMatches: [],
    jobsCost: null,
    purchases: [],
    purchasesCost: null,
    purchasedQuantity: 0,
    inStock: false,
    esiJobId: null,
    esiJobCost: null,
    esiJobStatus: null,
    esiJobEndDate: null,
    esiJobRuns: null,
    esiJobCharacterName: null,
    esiJobsCount: null,
    esiJobsTotalRuns: null,
    esiJobsActiveRuns: null,
    esiJobsDeliveredRuns: null,
    manualJobData: false,
    recommendedStructureName: null,
    structureBonus: null,
    similarJobs: [],
    ...overrides,
  }
}

const ROOT_STEP = step({
  id: 'step-root',
  blueprintTypeId: 12006,
  productTypeId: ROOT_TYPE_ID,
  productTypeName: ROOT_NAME,
  quantity: 1,
  runs: 1,
  depth: 0,
})

function intermediateStep(overrides: Partial<IndustryProjectStep>): IndustryProjectStep {
  return step({
    id: 'step-intermediate',
    blueprintTypeId: INTERMEDIATE_BLUEPRINT_TYPE_ID,
    productTypeId: INTERMEDIATE_TYPE_ID,
    productTypeName: INTERMEDIATE_NAME,
    quantity: 100,
    runs: 100,
    depth: 1,
    ...overrides,
  })
}

function project(steps: IndustryProjectStep[]): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: ROOT_NAME,
    productTypeId: ROOT_TYPE_ID,
    displayName: null,
    runs: 1,
    meLevel: 10,
    teLevel: 20,
    maxJobDurationDays: 2,
    status: 'active',
    personalUse: false,
    isT2: false,
    bpoCost: null,
    materialCost: null,
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
    steps,
    tree: null,
  }
}

function shoppingLine(typeId: number, typeName: string, quantity: number, unitPrice: number): ShoppingListItem {
  return {
    typeId,
    typeName,
    quantity,
    volume: 0.01,
    totalVolume: quantity * 0.01,
    jitaUnitPrice: unitPrice,
    jitaTotal: unitPrice * quantity,
    importCost: 0,
    jitaWithImport: unitPrice * quantity,
    structureUnitPrice: null,
    structureTotal: null,
    bestLocation: 'jita',
    bestPrice: unitPrice * quantity,
    savings: null,
    purchasedQuantity: 0,
    extraQuantity: 0,
    jitaWeightedUnitPrice: null,
    jitaWeightedTotal: null,
    jitaCoverage: null,
  }
}

function shoppingListResponse(materials: ShoppingListItem[]): ShoppingListResponse {
  return {
    materials,
    structureId: 1049588174021,
    structureName: 'C-J6MT - 1st Taj Mahgoon',
    structureAccessible: true,
    structureFromCache: false,
    structureLastSync: null,
    transportCostPerM3: 1200,
    totals: {
      jita: 0,
      import: 0,
      jitaWithImport: 0,
      structure: 0,
      volume: 0,
      best: 0,
      savingsVsJitaWithImport: null,
      savingsVsStructure: 0,
    },
    priceError: null,
    missingVolumeTypeIds: [],
    failedProducts: [],
  }
}

interface ShoppingTabExposed {
  loadShoppingList: () => Promise<void>
  loadPersistedStock: () => void
  enrichedShoppingList: EnrichedShoppingItem[]
}

async function mountShoppingTab(steps: IndustryProjectStep[], materials: ShoppingListItem[]) {
  vi.mocked(apiRequest).mockImplementation(async (url: string) => {
    if (url.includes('/shopping-list')) return shoppingListResponse(materials)
    throw new Error(`Unexpected request ${url}`)
  })
  useProjectsStore().currentProject = project(steps)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ShoppingTab, {
    global: {
      plugins: [i18n],
      stubs: { ShoppingConfigPanel: true, ShoppingTotalsSummary: true, ShoppingMaterialsTable: true, ShoppingPurchasesPanel: true },
    },
    props: { projectId: PROJECT_ID },
  })
  const exposed = wrapper.vm as unknown as ShoppingTabExposed
  await exposed.loadShoppingList()
  await flushPromises()
  return { wrapper, exposed }
}

function persistPastedStock(name: string, quantity: number) {
  localStorage.setItem(`industry_stock_${PROJECT_ID}`, JSON.stringify({
    raw: `${name}\t${quantity}`,
    parsed: [{ name, quantity }],
  }))
}

function missingOf(exposed: ShoppingTabExposed, typeId: number): number | undefined {
  return exposed.enrichedShoppingList.find(line => line.typeId === typeId)?.missing
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  localStorage.clear()
})

describe('ShoppingTab stock deduction', () => {
  it('does not deduct the pasted stock of a blacklisted intermediate already deducted by the backend', async () => {
    persistPastedStock(INTERMEDIATE_NAME, 40)
    const { exposed } = await mountShoppingTab(
      [ROOT_STEP, intermediateStep({ inStockQuantity: 40 })],
      [shoppingLine(INTERMEDIATE_TYPE_ID, INTERMEDIATE_NAME, 60, 1_250_000), shoppingLine(RAW_MATERIAL_TYPE_ID, RAW_MATERIAL_NAME, 500, 4.5)],
    )

    exposed.loadPersistedStock()
    await flushPromises()

    expect(missingOf(exposed, INTERMEDIATE_TYPE_ID)).toBe(60)
  })

  it('deducts the pasted stock of a raw material from its shopping list line', async () => {
    persistPastedStock(RAW_MATERIAL_NAME, 40)
    const { exposed } = await mountShoppingTab(
      [ROOT_STEP, intermediateStep({})],
      [shoppingLine(RAW_MATERIAL_TYPE_ID, RAW_MATERIAL_NAME, 100, 5.25)],
    )

    exposed.loadPersistedStock()
    await flushPromises()

    expect(missingOf(exposed, RAW_MATERIAL_TYPE_ID)).toBe(60)
  })

  it('deducts the pasted stock of a purchased intermediate without updating its step', async () => {
    const { wrapper, exposed } = await mountShoppingTab(
      [ROOT_STEP, intermediateStep({ purchased: true })],
      [shoppingLine(INTERMEDIATE_TYPE_ID, INTERMEDIATE_NAME, 100, 1_310_000), shoppingLine(RAW_MATERIAL_TYPE_ID, RAW_MATERIAL_NAME, 700, 4.75)],
    )
    const configPanel = wrapper.findComponent({ name: 'ShoppingConfigPanel' })

    configPanel.vm.$emit('update:pasted-stock', `${INTERMEDIATE_NAME}\t40`)
    await flushPromises()
    configPanel.vm.$emit('analyze-stock')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method === 'PATCH')).toEqual([])
    expect(missingOf(exposed, INTERMEDIATE_TYPE_ID)).toBe(60)
  })
})

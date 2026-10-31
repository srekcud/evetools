import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { defineComponent, h } from 'vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ShoppingTab from '@/components/industry/ShoppingTab.vue'
import { ApiError, apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, IndustryProjectStep, PurchaseSuggestion, ShoppingListItem, ShoppingListResponse, StepPurchase } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-205'
const ROOT_STEP_ID = 'step-root-205'
const PURCHASES_URL = `/industry/projects/${PROJECT_ID}/steps/${ROOT_STEP_ID}/purchases`
const SUGGESTIONS_URL = `/industry/projects/${PROJECT_ID}/purchase-suggestions`
const PROJECT_URL = `/industry/projects/${PROJECT_ID}`

const ALREADY_LINKED_EN = 'This transaction is already linked to a purchase. Suggestions have been refreshed.'
const NOT_FOUND_EN = 'The transaction or the step no longer exists. Suggestions have been refreshed.'
const FAILED_EN = 'Failed to link the purchase.'
const ALREADY_LINKED_FR = 'Cette transaction est déjà rattachée à un achat. Les suggestions ont été rafraîchies.'
const NOT_FOUND_FR = 'La transaction ou l\'étape n\'existe plus. Les suggestions ont été rafraîchies.'
const FAILED_FR = 'Échec du rattachement de l\'achat.'

const ROOT_STEP: IndustryProjectStep = {
  id: ROOT_STEP_ID,
  blueprintTypeId: 9200,
  productTypeId: 9000,
  productTypeName: 'Prototype Hull',
  quantity: 4,
  runs: 4,
  depth: 0,
  activityType: 'manufacturing',
  runsFixed: false,
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
  deletable: true,
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
}

const PROJECT: IndustryProject = {
  id: PROJECT_ID,
  productTypeName: 'Prototype Hull',
  productTypeId: 9000,
  displayName: null,
  runs: 4,
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
  steps: [ROOT_STEP],
  tree: null,
}

const T1: PurchaseSuggestion = {
  transactionId: 6_400_000_001,
  transactionUuid: '0192f0c4-7b1e-7a3c-9d2e-205000000101',
  typeId: 9101,
  typeName: 'Reinforced Plating',
  quantity: 120,
  unitPrice: 5.25,
  totalPrice: 630,
  date: '2026-10-20T09:30:00+00:00',
  characterName: 'Pilot One',
  locationId: 60003760,
  alreadyLinked: false,
}

const T2: PurchaseSuggestion = {
  transactionId: 6_400_000_002,
  transactionUuid: '0192f0c4-7b1e-7a3c-9d2e-205000000102',
  typeId: 9102,
  typeName: 'Tuned Capacitor',
  quantity: 60,
  unitPrice: 14.5,
  totalPrice: 870,
  date: '2026-10-21T14:10:00+00:00',
  characterName: 'Pilot Two',
  locationId: 60003760,
  alreadyLinked: false,
}

const LINKED_PURCHASE: StepPurchase = {
  id: 'purchase-205-1',
  typeId: 9101,
  typeName: 'Reinforced Plating',
  quantity: 120,
  unitPrice: 5.25,
  totalPrice: 630,
  source: 'esi_wallet',
  transactionId: T1.transactionUuid,
  createdAt: '2026-10-29T10:00:00+00:00',
}

const PLATING_LINE: ShoppingListItem = {
  typeId: 9101,
  typeName: 'Reinforced Plating',
  quantity: 240,
  volume: 0.5,
  totalVolume: 120,
  jitaUnitPrice: 5.25,
  jitaTotal: 1260,
  importCost: 144000,
  jitaWithImport: 145260,
  structureUnitPrice: null,
  structureTotal: null,
  bestLocation: 'jita',
  bestPrice: 145260,
  savings: null,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

const SHOPPING_LIST: ShoppingListResponse = {
  materials: [PLATING_LINE],
  structureId: 1049588174021,
  structureName: 'C-J6MT - 1st Taj Mahgoon',
  structureAccessible: true,
  structureFromCache: false,
  structureLastSync: null,
  transportCostPerM3: 1200,
  totals: {
    jita: 1260,
    import: 144000,
    jitaWithImport: 145260,
    structure: 0,
    volume: 120,
    best: 145260,
    savingsVsJitaWithImport: null,
    savingsVsStructure: 0,
  },
  priceError: null,
  missingVolumeTypeIds: [],
  failedProducts: [],
  missingPriceTypeIds: [],
}

const resetLinkingId = vi.fn()

const ShoppingPurchasesPanelStub = defineComponent({
  name: 'ShoppingPurchasesPanel',
  props: ['suggestions', 'projectPurchases', 'projectPurchasesTotalCost', 'materialCost', 'purchasesLoading', 'canFindStepForType', 'linkError'],
  emits: ['loadSuggestions', 'linkSuggestion', 'removePurchase'],
  setup(_props, { expose }) {
    expose({ resetLinkingId })
    return () => h('div')
  },
})

let uncaughtErrors: unknown[] = []
let linkOutcomes: Array<() => StepPurchase> = []

async function mountShoppingTab(locale: 'en' | 'fr' = 'en') {
  vi.mocked(apiRequest).mockImplementation(async (url: string, options?: RequestInit) => {
    if (url.includes('/shopping-list')) return SHOPPING_LIST
    if (url === PURCHASES_URL && options?.method === 'POST') {
      const outcome = linkOutcomes.shift()
      if (!outcome) throw new Error(`Unexpected link ${String(options.body)}`)
      return outcome()
    }
    if (url === SUGGESTIONS_URL) return { suggestions: [{ ...T1 }, { ...T2, alreadyLinked: true }], totalCount: 2 }
    if (url === PROJECT_URL) return { ...PROJECT, steps: [{ ...ROOT_STEP }] }
    throw new Error(`Unexpected request ${url}`)
  })
  useProjectsStore().currentProject = { ...PROJECT, steps: [{ ...ROOT_STEP }] }
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ShoppingTab, {
    global: {
      plugins: [i18n],
      stubs: { ShoppingConfigPanel: true, ShoppingTotalsSummary: true, ShoppingMaterialsTable: true, ShoppingPurchasesPanel: ShoppingPurchasesPanelStub },
      config: { errorHandler: (error: unknown) => { uncaughtErrors.push(error) } },
    },
    props: { projectId: PROJECT_ID },
  })
  await (wrapper.vm as unknown as { loadShoppingList: () => Promise<void> }).loadShoppingList()
  await flushPromises()
  return wrapper
}

function purchasesPanelOf(wrapper: Awaited<ReturnType<typeof mountShoppingTab>>) {
  return wrapper.findComponent(ShoppingPurchasesPanelStub)
}

async function clickLink(wrapper: Awaited<ReturnType<typeof mountShoppingTab>>, suggestion: PurchaseSuggestion) {
  purchasesPanelOf(wrapper).vm.$emit('link-suggestion', {
    transactionUuid: suggestion.transactionUuid,
    typeId: suggestion.typeId,
    transactionId: suggestion.transactionId,
  })
  await flushPromises()
}

function requestsOtherThanTheShoppingList() {
  return vi.mocked(apiRequest).mock.calls.filter(([url]) => !url.includes('/shopping-list'))
}

function linkRequestOf(suggestion: PurchaseSuggestion) {
  return [PURCHASES_URL, { method: 'POST', body: JSON.stringify({ transactionId: suggestion.transactionUuid }) }]
}

function rejectWith(error: Error): () => StepPurchase {
  return () => { throw error }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  resetLinkingId.mockReset()
  localStorage.clear()
  uncaughtErrors = []
  linkOutcomes = []
})

describe('ShoppingTab purchase link error', () => {
  it('shows the already linked message, refreshes only the suggestions and re-enables the link button on a 409', async () => {
    const wrapper = await mountShoppingTab()
    linkOutcomes = [rejectWith(new ApiError('This transaction is already linked to a purchase.', 409))]

    await clickLink(wrapper, T2)

    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(ALREADY_LINKED_EN)
    expect(requestsOtherThanTheShoppingList()).toEqual([linkRequestOf(T2), [SUGGESTIONS_URL]])
    expect(resetLinkingId).toHaveBeenCalledTimes(1)
    expect(uncaughtErrors).toEqual([])
  })

  it('shows the not found message and refreshes the suggestions on a 404', async () => {
    const wrapper = await mountShoppingTab()
    linkOutcomes = [rejectWith(new ApiError('Transaction not found', 404))]

    await clickLink(wrapper, T2)

    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(NOT_FOUND_EN)
    expect(requestsOtherThanTheShoppingList()).toEqual([linkRequestOf(T2), [SUGGESTIONS_URL]])
    expect(uncaughtErrors).toEqual([])
  })

  it.each([
    ['an ApiError 500', new ApiError('Internal Server Error', 500)],
    ['a network failure', new TypeError('Failed to fetch')],
  ])('shows the generic link failure message and refreshes the suggestions on %s', async (_label, error) => {
    const wrapper = await mountShoppingTab()
    linkOutcomes = [rejectWith(error)]

    await clickLink(wrapper, T2)

    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(FAILED_EN)
    expect(requestsOtherThanTheShoppingList()).toEqual([linkRequestOf(T2), [SUGGESTIONS_URL]])
    expect(resetLinkingId).toHaveBeenCalledTimes(1)
    expect(uncaughtErrors).toEqual([])
  })

  it.each([
    ['a 404', new ApiError('Step not found', 404), NOT_FOUND_FR],
    ['a 500', new ApiError('Internal Server Error', 500), FAILED_FR],
  ])('shows the link error in French on %s', async (_label, error, expectedMessage) => {
    const wrapper = await mountShoppingTab('fr')
    linkOutcomes = [rejectWith(error)]

    await clickLink(wrapper, T2)

    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(expectedMessage)
  })

  it('sends no request and keeps the previous link error when no step matches the type of the suggestion', async () => {
    const wrapper = await mountShoppingTab()
    linkOutcomes = [rejectWith(new ApiError('This transaction is already linked to a purchase.', 409))]
    await clickLink(wrapper, T2)
    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(ALREADY_LINKED_EN)
    useProjectsStore().currentProject = { ...PROJECT, steps: [{ ...ROOT_STEP, activityType: 'copy' }] }
    vi.mocked(apiRequest).mockClear()

    await clickLink(wrapper, T1)

    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(ALREADY_LINKED_EN)
    expect(vi.mocked(apiRequest).mock.calls).toEqual([])
    expect(uncaughtErrors).toEqual([])
  })

  it('shows the already linked message in French on a 409', async () => {
    const wrapper = await mountShoppingTab('fr')
    linkOutcomes = [rejectWith(new ApiError('This transaction is already linked to a purchase.', 409))]

    await clickLink(wrapper, T2)

    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(ALREADY_LINKED_FR)
  })

  it('clears the link error on the next successful link and reloads the project and the suggestions', async () => {
    const wrapper = await mountShoppingTab()
    linkOutcomes = [rejectWith(new ApiError('This transaction is already linked to a purchase.', 409)), () => ({ ...LINKED_PURCHASE })]
    await clickLink(wrapper, T2)
    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(ALREADY_LINKED_EN)
    vi.mocked(apiRequest).mockClear()

    await clickLink(wrapper, T1)

    expect(purchasesPanelOf(wrapper).props('linkError')).toBeNull()
    expect(requestsOtherThanTheShoppingList().map(([url]) => url).sort()).toEqual([PROJECT_URL, SUGGESTIONS_URL, PURCHASES_URL])
  })

  it('clears the link error when the suggestions are refreshed', async () => {
    const wrapper = await mountShoppingTab()
    linkOutcomes = [rejectWith(new ApiError('This transaction is already linked to a purchase.', 409))]
    await clickLink(wrapper, T2)
    expect(purchasesPanelOf(wrapper).props('linkError')).toBe(ALREADY_LINKED_EN)

    purchasesPanelOf(wrapper).vm.$emit('load-suggestions')
    await flushPromises()

    expect(purchasesPanelOf(wrapper).props('linkError')).toBeNull()
  })
})

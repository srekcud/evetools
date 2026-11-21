import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, PurchaseSuggestion } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1180'
const SUGGESTIONS_URL = `/industry/projects/${PROJECT_ID}/purchase-suggestions`
const WALLET_FIRST = '[data-testid="wallet-first"]'
const WALLET_FIRST_ROW = '[data-testid="wallet-first-transaction"]'
const WALLET_FIRST_LINK = '[data-testid="wallet-first-link"]'
const WALLET_FIRST_ERROR = '[data-testid="wallet-first-load-error"]'
const STOCK_DUPLICATE_HINT = '[data-testid="stock-duplicate-hint"]'
const TRITANIUM_TYPE_ID = 34
const PYERITE_TYPE_ID = 35

function bomLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'bom-tritanium',
    typeId: TRITANIUM_TYPE_ID,
    typeName: 'Tritanium',
    requiredQuantity: 100,
    fulfilledQuantity: 0,
    remainingQuantity: 100,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

function suggestion(overrides: Partial<PurchaseSuggestion>): PurchaseSuggestion {
  return {
    transactionId: 1_180_000_001,
    transactionUuid: '0192f0c4-7b1e-7a3c-9d2e-118000000001',
    typeId: TRITANIUM_TYPE_ID,
    typeName: 'Tritanium',
    quantity: 40,
    unitPrice: 5,
    totalPrice: 200,
    date: '2026-11-20T19:30:00+00:00',
    characterName: 'Cora Vance',
    locationId: 60003760,
    alreadyLinked: false,
    ...overrides,
  }
}

const FORTY_UNITS = suggestion({})
const SIXTY_UNITS = suggestion({
  transactionId: 1_180_000_002,
  transactionUuid: '0192f0c4-7b1e-7a3c-9d2e-118000000002',
  quantity: 60,
  totalPrice: 300,
})

function answerSuggestionsWith(suggestions: PurchaseSuggestion[]): void {
  vi.mocked(apiRequest).mockImplementation(((endpoint: string) => {
    if (endpoint === SUGGESTIONS_URL) return Promise.resolve({ suggestions, totalCount: suggestions.length, walletReadings: [] })
    if (endpoint === `/industry/projects/${PROJECT_ID}/bom`) return Promise.resolve([bomLine({})])
    return Promise.resolve({})
  }) as typeof apiRequest)
}

function suggestionRequests(): unknown[][] {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SUGGESTIONS_URL)
}

async function openModal(line: BomItem): Promise<VueWrapper> {
  useGroupProjectStore().bomItems = [line]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem: line },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('contribute modal offers the wallet transactions before the stock', () => {
  it('lists the unlinked wallet transactions of a material line and warns about duplicating them', async () => {
    answerSuggestionsWith([FORTY_UNITS, SIXTY_UNITS])

    const wrapper = await openModal(bomLine({}))

    expect(wrapper.findAll(WALLET_FIRST_ROW)).toHaveLength(2)
    expect(wrapper.get(WALLET_FIRST).text()).toContain(en.groupIndustry.contribute.walletFirst.title)
    expect(wrapper.get(STOCK_DUPLICATE_HINT).text()).toBe(en.groupIndustry.contribute.walletFirst.stockHint)
  })

  it('places the wallet transactions before the value source and the quantity', async () => {
    answerSuggestionsWith([FORTY_UNITS])

    const wrapper = await openModal(bomLine({}))

    const markup = wrapper.html()
    expect(markup.indexOf('data-testid="wallet-first"')).toBeGreaterThan(-1)
    expect(markup.indexOf('data-testid="wallet-first"')).toBeLessThan(markup.indexOf('data-testid="value-source-jita_price"'))
  })

  it('ignores the transactions of other items and the ones already linked', async () => {
    answerSuggestionsWith([
      FORTY_UNITS,
      suggestion({ transactionId: 1_180_000_003, transactionUuid: 'other-item', typeId: PYERITE_TYPE_ID, typeName: 'Pyerite' }),
      suggestion({ transactionId: 1_180_000_004, transactionUuid: 'already-linked', alreadyLinked: true }),
    ])

    const wrapper = await openModal(bomLine({}))

    expect(wrapper.findAll(WALLET_FIRST_ROW)).toHaveLength(1)
  })

  it('links a transaction to the line and drops it from the list', async () => {
    answerSuggestionsWith([FORTY_UNITS, SIXTY_UNITS])
    const wrapper = await openModal(bomLine({}))

    await wrapper.findAll(WALLET_FIRST_LINK)[0]!.trigger('click')
    await flushPromises()

    const linking = vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method === 'POST')
    expect(linking).toHaveLength(1)
    expect(linking[0]![0]).toBe(`/industry/projects/${PROJECT_ID}/bom/bom-tritanium/purchases`)
    expect(JSON.parse(String(linking[0]![1]?.body))).toEqual({ transactionId: FORTY_UNITS.transactionUuid })
    expect(wrapper.findAll(WALLET_FIRST_ROW)).toHaveLength(1)
  })

  it('removes the section and the warning once every transaction is linked', async () => {
    answerSuggestionsWith([FORTY_UNITS])
    const wrapper = await openModal(bomLine({}))

    await wrapper.get(WALLET_FIRST_LINK).trigger('click')
    await flushPromises()

    expect(wrapper.find(WALLET_FIRST).exists()).toBe(false)
    expect(wrapper.find(STOCK_DUPLICATE_HINT).exists()).toBe(false)
  })

  it('keeps the modal usable and says so when the transactions cannot be loaded', async () => {
    vi.mocked(apiRequest).mockImplementation(((endpoint: string) =>
      endpoint === SUGGESTIONS_URL ? Promise.reject(new Error('boom')) : Promise.resolve({})) as typeof apiRequest)

    const wrapper = await openModal(bomLine({}))

    expect(wrapper.get(WALLET_FIRST_ERROR).text()).toBe(en.groupIndustry.contribute.walletFirst.loadError)
    expect(wrapper.find('[data-testid="value-source-jita_price"]').exists()).toBe(true)
  })
})

describe('contribute modal stays unchanged where no wallet transaction applies', () => {
  it('shows no section when the item has no unlinked transaction', async () => {
    answerSuggestionsWith([])

    const wrapper = await openModal(bomLine({}))

    expect(wrapper.find(WALLET_FIRST).exists()).toBe(false)
    expect(wrapper.find(STOCK_DUPLICATE_HINT).exists()).toBe(false)
  })

  it('asks for no suggestions on a job line', async () => {
    answerSuggestionsWith([FORTY_UNITS])

    const wrapper = await openModal(bomLine({ id: 'job-plates', isJob: true, jobGroup: 'component', activityType: 'manufacturing' }))

    expect(suggestionRequests()).toHaveLength(0)
    expect(wrapper.find(WALLET_FIRST).exists()).toBe(false)
  })
})

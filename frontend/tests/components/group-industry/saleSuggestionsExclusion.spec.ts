import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import { apiRequest } from '@/services/api'
import type { GroupSale, GroupSaleSuggestion, GroupSaleSuggestionList } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const SUGGESTIONS_PATH = `/industry/projects/${PROJECT_ID}/sale-suggestions`
const SALES_PATH = `/industry/projects/${PROJECT_ID}/sales`
const SALE_FROM_WALLET_TRANSACTION_PATH = `/industry/projects/${PROJECT_ID}/sales/from-wallet-transaction`
const SALES_FROM_WALLET_TRANSACTIONS_PATH = `/industry/projects/${PROJECT_ID}/sales/from-wallet-transactions`
const T1_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e01'
const T3_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e03'
const T4_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e04'
const T7_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e07'

const RECORD_ALL = '[data-testid="sales-record-all"]'
const RECORD_ALL_COUNT = '[data-testid="sales-record-all-count"]'
const RECORD_ALL_QUANTITY = '[data-testid="sales-record-all-quantity"]'
const RECORD_ALL_LEFT_OUT = '[data-testid="sales-record-all-left-out"]'
const RECORD_ALL_CONFIRM = '[data-testid="sales-record-all-confirm"]'
const INCLUDE_CHECKBOX = 'input[type="checkbox"]'

const T7_SUGGESTION: GroupSaleSuggestion = {
  transactionId: T7_ID,
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 12,
  unitPrice: 60_000_000,
  totalPrice: 720_000_000,
  date: '2026-10-26T11:45:00+00:00',
  sellerCharacterId: 2_120_000_002,
  sellerCharacterName: 'O-Alt',
  locationId: 60_004_588,
  possibleDuplicateSaleIds: [],
}

const T3_SUGGESTION: GroupSaleSuggestion = {
  transactionId: T3_ID,
  typeId: 11_202,
  typeName: 'Ares',
  quantity: 3,
  unitPrice: 61_250_000,
  totalPrice: 183_750_000,
  date: '2026-10-24T21:05:00+00:00',
  sellerCharacterId: 2_120_000_001,
  sellerCharacterName: 'O-Main',
  locationId: 60_003_760,
  possibleDuplicateSaleIds: [],
}

const T4_SUGGESTION: GroupSaleSuggestion = {
  transactionId: T4_ID,
  typeId: 22_460,
  typeName: 'Heretic',
  quantity: 2,
  unitPrice: 15_500_000,
  totalPrice: 31_000_000,
  date: '2026-10-24T21:05:00+00:00',
  sellerCharacterId: 2_120_000_003,
  sellerCharacterName: 'O-Third',
  locationId: 60_003_760,
  possibleDuplicateSaleIds: [],
}

const T1_SUGGESTION: GroupSaleSuggestion = {
  transactionId: T1_ID,
  typeId: 22_452,
  typeName: 'Eris',
  quantity: 7,
  unitPrice: 9_000_000,
  totalPrice: 63_000_000,
  date: '2026-10-25T08:30:00+00:00',
  sellerCharacterId: 2_120_000_004,
  sellerCharacterName: 'O-Fourth',
  locationId: 60_003_760,
  possibleDuplicateSaleIds: ['sale-v'],
}

const RECORDED_SALE: GroupSale = {
  id: 'sale-recorded',
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 5,
  unitPrice: 60_000_000,
  totalPrice: 300_000_000,
  venue: null,
  channel: 'market',
  soldAt: '2026-10-26T11:45:00+00:00',
  recordedByCharacterName: 'O-Main',
  sellerCharacterId: 2_120_000_002,
  sellerCharacterName: 'O-Alt',
  linkedToWalletTransaction: true,
  createdAt: '2026-10-29T08:00:00+00:00',
}

function suggestionList(suggestions: GroupSaleSuggestion[]): GroupSaleSuggestionList {
  return { id: PROJECT_ID, suggestions, walletReadings: [] }
}

async function mountPanelWith(suggestions: GroupSaleSuggestion[], locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === SUGGESTIONS_PATH) return suggestionList(suggestions)
    if (endpoint === SALES_PATH) return []
    if (endpoint === SALE_FROM_WALLET_TRANSACTION_PATH && options?.method === 'POST') return RECORDED_SALE
    if (endpoint === SALES_FROM_WALLET_TRANSACTIONS_PATH && options?.method === 'POST') {
      return { id: 'batch', recorded: [RECORDED_SALE], failures: [] }
    }
    throw new Error(`unexpected request ${options?.method ?? 'GET'} ${endpoint}`)
  })
  const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(SaleSuggestionsPanel, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID },
  })
  await flushPromises()
  return wrapper
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function rowSoldBy(wrapper: VueWrapper, characterName: string): DOMWrapper<Element> {
  const characterColumn = wrapper.findAll('thead th').findIndex(cell => normalized(cell.text()) === 'Character')
  const row = wrapper.findAll('tbody tr').find(candidate => normalized(candidate.findAll('td')[characterColumn].text()) === characterName)
  if (row === undefined) throw new Error(`no suggestion sold by "${characterName}"`)
  return row
}

function checkboxOf(wrapper: VueWrapper, characterName: string): Omit<DOMWrapper<HTMLInputElement>, 'exists'> {
  return rowSoldBy(wrapper, characterName).get<HTMLInputElement>(INCLUDE_CHECKBOX)
}

async function toggle(wrapper: VueWrapper, characterName: string): Promise<void> {
  await checkboxOf(wrapper, characterName).setValue(!checkboxOf(wrapper, characterName).element.checked)
}

function postedBatches(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([endpoint, options]) => endpoint === SALES_FROM_WALLET_TRANSACTIONS_PATH && options?.method === 'POST')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

function postedSingles(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([endpoint, options]) => endpoint === SALE_FROM_WALLET_TRANSACTION_PATH && options?.method === 'POST')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('SaleSuggestionsPanel exclusion from record all', () => {
  it('checks the include box of every recordable suggestion by default and leaves the possible duplicate unchecked and disabled', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION, T1_SUGGESTION])

    expect(checkboxOf(wrapper, 'O-Alt').element.checked).toBe(true)
    expect(checkboxOf(wrapper, 'O-Main').element.checked).toBe(true)
    expect(checkboxOf(wrapper, 'O-Fourth').element.checked).toBe(false)
    expect(checkboxOf(wrapper, 'O-Fourth').element.disabled).toBe(true)
  })

  it('counts only the two checked transactions when T3 is unchecked among T7, T3 and T4', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION, T4_SUGGESTION])

    await toggle(wrapper, 'O-Main')
    await wrapper.get(RECORD_ALL).trigger('click')
    await flushPromises()

    expect(wrapper.get(RECORD_ALL).text()).toContain('2')
    expect(wrapper.get(RECORD_ALL_COUNT).text()).toContain('2')
    expect(wrapper.get(RECORD_ALL_QUANTITY).text()).toContain('14')
    expect(wrapper.find('[data-testid="sales-record-all-product-11202"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="sales-record-all-product-22456"]').exists()).toBe(true)
  })

  it('sends only the checked transactions in the single batch request', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION, T4_SUGGESTION])

    await toggle(wrapper, 'O-Main')
    await wrapper.get(RECORD_ALL).trigger('click')
    await flushPromises()
    await wrapper.get(RECORD_ALL_CONFIRM).trigger('click')
    await flushPromises()

    expect(postedBatches()).toEqual([{ transactions: [{ transactionId: T4_ID }, { transactionId: T7_ID }] }])
    expect(postedSingles()).toEqual([])
  })

  it('does not report a transaction the manager unchecked as left out', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION, T4_SUGGESTION])

    await toggle(wrapper, 'O-Main')
    await wrapper.get(RECORD_ALL).trigger('click')
    await flushPromises()

    expect(wrapper.find(RECORD_ALL_LEFT_OUT).exists()).toBe(false)
  })

  it('keeps the include boxes when only one transaction stays checked and brings record all back once it is checked again', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION])

    await toggle(wrapper, 'O-Main')
    expect(wrapper.find(RECORD_ALL).exists()).toBe(false)
    expect(checkboxOf(wrapper, 'O-Main').element.checked).toBe(false)

    await toggle(wrapper, 'O-Main')
    expect(wrapper.find(RECORD_ALL).exists()).toBe(true)
  })

  it('still records an unchecked transaction on its own with its own Record as sale button', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION, T4_SUGGESTION])
    await toggle(wrapper, 'O-Main')

    const recordButton = rowSoldBy(wrapper, 'O-Main').findAll<HTMLButtonElement>('button').find(button => normalized(button.text()) === 'Record as sale')
    if (recordButton === undefined) throw new Error('no Record as sale button')
    await recordButton.trigger('click')
    await flushPromises()

    expect(postedSingles()).toEqual([{ transactionId: T3_ID }])
  })

  it('labels the include box in French with its translated text rather than the raw key', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION], 'fr')

    const label = wrapper.get(INCLUDE_CHECKBOX).attributes('aria-label') ?? ''

    expect(label).not.toBe('')
    expect(label).not.toContain('groupIndustry.saleSuggestions')
  })
})

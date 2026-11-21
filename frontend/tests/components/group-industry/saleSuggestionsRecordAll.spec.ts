import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import { ApiError, apiRequest } from '@/services/api'
import type { GroupSale, GroupSaleSuggestion, GroupSaleSuggestionList } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const SUGGESTIONS_PATH = `/industry/projects/${PROJECT_ID}/sale-suggestions`
const SALES_PATH = `/industry/projects/${PROJECT_ID}/sales`
const SALE_FROM_WALLET_TRANSACTION_PATH = `/industry/projects/${PROJECT_ID}/sales/from-wallet-transaction`
const T1_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e01'
const T3_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e03'
const T4_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e04'
const T7_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e07'
const ALREADY_LINKED = 'This transaction is already linked to a sale.'

const RECORD_ALL = '[data-testid="sales-record-all"]'
const RECORD_ALL_CONFIRMATION = '[data-testid="sales-record-all-confirmation"]'
const RECORD_ALL_COUNT = '[data-testid="sales-record-all-count"]'
const RECORD_ALL_QUANTITY = '[data-testid="sales-record-all-quantity"]'
const RECORD_ALL_TOTAL = '[data-testid="sales-record-all-total"]'
const RECORD_ALL_LEFT_OUT = '[data-testid="sales-record-all-left-out"]'
const RECORD_ALL_CONFIRM = '[data-testid="sales-record-all-confirm"]'
const RECORD_ALL_CANCEL = '[data-testid="sales-record-all-cancel"]'
const RECORD_ALL_FAILURES = '[data-testid="sales-record-all-failures"]'
const RECORD_ALL_FAILURE = '[data-testid="sales-record-all-failure"]'

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

type Responses = {
  suggestions: () => Promise<unknown>
  recording: (body: { transactionId: string, quantity?: number }) => Promise<unknown>
}

let responses: Responses

function suggestionList(suggestions: GroupSaleSuggestion[]): GroupSaleSuggestionList {
  return { id: PROJECT_ID, suggestions, walletReadings: [] }
}

async function mountPanel(options: { locale?: 'en' | 'fr', recordingDisabled?: boolean } = {}): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale: options.locale ?? 'en', fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(SaleSuggestionsPanel, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID, recordingDisabled: options.recordingDisabled ?? false },
  })
  await flushPromises()
  return wrapper
}

async function mountPanelWith(suggestions: GroupSaleSuggestion[], options: { locale?: 'en' | 'fr', recordingDisabled?: boolean } = {}): Promise<VueWrapper> {
  responses.suggestions = async () => suggestionList(suggestions)
  return mountPanel(options)
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function columnIndex(wrapper: VueWrapper, header: string): number {
  const index = wrapper.findAll('thead th').findIndex(cell => normalized(cell.text()) === header)
  if (index === -1) throw new Error(`no column "${header}"`)
  return index
}

function rowSoldBy(wrapper: VueWrapper, characterName: string): DOMWrapper<Element> {
  const characterColumn = columnIndex(wrapper, 'Character')
  const row = wrapper.findAll('tbody tr').find(candidate => normalized(candidate.findAll('td')[characterColumn].text()) === characterName)
  if (row === undefined) throw new Error(`no suggestion sold by "${characterName}"`)
  return row
}

function buttonNamed(scope: DOMWrapper<Element>, name: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll<HTMLButtonElement>('button').find(candidate => normalized(candidate.text()) === name)
  if (button === undefined) throw new Error(`no button "${name}"`)
  return button
}

async function enterQuantity(wrapper: VueWrapper, characterName: string, quantity: number): Promise<void> {
  await rowSoldBy(wrapper, characterName).find('input[type="number"]').setValue(quantity)
}

async function openConfirmation(wrapper: VueWrapper): Promise<void> {
  await wrapper.get(RECORD_ALL).trigger('click')
  await flushPromises()
}

async function confirmRecordAll(wrapper: VueWrapper): Promise<void> {
  await openConfirmation(wrapper)
  await wrapper.get(RECORD_ALL_CONFIRM).trigger('click')
  await flushPromises()
}

function postedBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([endpoint, options]) => endpoint === SALE_FROM_WALLET_TRANSACTION_PATH && options?.method === 'POST')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

function suggestionReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SUGGESTIONS_PATH).length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  responses = {
    suggestions: async () => suggestionList([T7_SUGGESTION, T3_SUGGESTION]),
    recording: async () => RECORDED_SALE,
  }
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === SUGGESTIONS_PATH) return responses.suggestions()
    if (endpoint === SALES_PATH) return []
    if (endpoint === SALE_FROM_WALLET_TRANSACTION_PATH && options?.method === 'POST') {
      return responses.recording(JSON.parse(String(options.body)))
    }
    throw new Error(`unexpected request ${options?.method ?? 'GET'} ${endpoint}`)
  })
})

describe('SaleSuggestionsPanel record all', () => {
  it('summarises the two planned transactions as 15 units for 903.8 M ISK before sending anything', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION])

    await openConfirmation(wrapper)

    expect(wrapper.find(RECORD_ALL_CONFIRMATION).exists()).toBe(true)
    expect(wrapper.get(RECORD_ALL_COUNT).text()).toContain('2')
    expect(wrapper.get(RECORD_ALL_QUANTITY).text()).toContain('15')
    expect(wrapper.get(RECORD_ALL_TOTAL).text()).toContain('903.8 M')
    expect(wrapper.find(RECORD_ALL_LEFT_OUT).exists()).toBe(false)
    expect(postedBodies()).toEqual([])
  })

  it('counts an entered quantity of 5 for T7 as 8 units for 483.8 M ISK at its unit price', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION])
    await enterQuantity(wrapper, 'O-Alt', 5)

    await openConfirmation(wrapper)

    expect(wrapper.get(RECORD_ALL_COUNT).text()).toContain('2')
    expect(wrapper.get(RECORD_ALL_QUANTITY).text()).toContain('8')
    expect(wrapper.get(RECORD_ALL_TOTAL).text()).toContain('483.8 M')
  })

  it('leaves out the possible duplicate T1, says so and records only T7 and T3', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION, T1_SUGGESTION])

    await openConfirmation(wrapper)

    expect(wrapper.get(RECORD_ALL).text()).toContain('2')
    expect(wrapper.get(RECORD_ALL_COUNT).text()).toContain('2')
    expect(wrapper.get(RECORD_ALL_QUANTITY).text()).toContain('15')
    expect(wrapper.get(RECORD_ALL_LEFT_OUT).text()).toContain('1')
    await wrapper.get(RECORD_ALL_CONFIRM).trigger('click')
    await flushPromises()
    expect(postedBodies()).toEqual([{ transactionId: T3_ID }, { transactionId: T7_ID }])
  })

  it('stops offering record all once recording T3 leaves T7 as the only transaction', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION])
    expect(wrapper.find(RECORD_ALL).exists()).toBe(true)
    responses.suggestions = async () => suggestionList([T7_SUGGESTION])

    await buttonNamed(rowSoldBy(wrapper, 'O-Main'), 'Record as sale').trigger('click')
    await flushPromises()

    expect(wrapper.find(RECORD_ALL).exists()).toBe(false)
  })

  it('drops T7 from the plan while its entered quantity of 13 exceeds its 12 units and brings it back with 5', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION])
    expect(wrapper.find(RECORD_ALL).exists()).toBe(true)

    await enterQuantity(wrapper, 'O-Alt', 13)
    expect(wrapper.find(RECORD_ALL).exists()).toBe(false)

    await enterQuantity(wrapper, 'O-Alt', 5)
    expect(wrapper.find(RECORD_ALL).exists()).toBe(true)
  })

  it('sends nothing and closes the confirmation when it is cancelled', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION])

    await openConfirmation(wrapper)
    await wrapper.get(RECORD_ALL_CANCEL).trigger('click')
    await flushPromises()

    expect(wrapper.find(RECORD_ALL_CONFIRMATION).exists()).toBe(false)
    expect(postedBodies()).toEqual([])
  })

  it('records oldest first by date then transaction id, sending a quantity only for a valid entry, with no failure block', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T4_SUGGESTION, T3_SUGGESTION])
    await enterQuantity(wrapper, 'O-Alt', 5)

    await confirmRecordAll(wrapper)

    expect(postedBodies()).toEqual([{ transactionId: T3_ID }, { transactionId: T4_ID }, { transactionId: T7_ID, quantity: 5 }])
    expect(wrapper.find(RECORD_ALL_CONFIRMATION).exists()).toBe(false)
    expect(wrapper.find(RECORD_ALL_FAILURES).exists()).toBe(false)
  })

  it('records T7 and T4 when T3 is refused, lists T3 as failed and reads the suggestions once more', async () => {
    responses.recording = async body => {
      if (body.transactionId === T3_ID) throw new ApiError(ALREADY_LINKED, 409)
      return RECORDED_SALE
    }
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION, T4_SUGGESTION])
    responses.suggestions = async () => suggestionList([T3_SUGGESTION])

    await confirmRecordAll(wrapper)

    expect(postedBodies()).toEqual([{ transactionId: T3_ID }, { transactionId: T4_ID }, { transactionId: T7_ID }])
    expect(suggestionReads()).toBe(2)
    expect(wrapper.find(RECORD_ALL_CONFIRMATION).exists()).toBe(false)
    const failures = wrapper.findAll(RECORD_ALL_FAILURE)
    expect(failures).toHaveLength(1)
    expect(failures[0].text()).toContain('O-Main')
    expect(failures[0].text()).toContain(ALREADY_LINKED)
  })

  it('disables the record all button when the project no longer accepts recordings', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION], { recordingDisabled: true })

    expect(wrapper.get<HTMLButtonElement>(RECORD_ALL).element.disabled).toBe(true)
  })

  it('shows the record all button in French with its translated label rather than the raw key', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION], { locale: 'fr' })

    expect(wrapper.get(RECORD_ALL).text()).not.toContain('groupIndustry.saleSuggestions.recordAll')
    expect(wrapper.get(RECORD_ALL).text()).toContain('2')
  })

  it('still records only T7 when its own Record as sale button is used next to the record all button', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION])
    await enterQuantity(wrapper, 'O-Alt', 10)

    await buttonNamed(rowSoldBy(wrapper, 'O-Alt'), 'Record as sale').trigger('click')
    await flushPromises()

    expect(wrapper.find(RECORD_ALL).exists()).toBe(true)
    expect(postedBodies()).toEqual([{ transactionId: T7_ID, quantity: 10 }])
  })

  it('still records the possible duplicate T1 on its own although record all leaves it out', async () => {
    const wrapper = await mountPanelWith([T7_SUGGESTION, T3_SUGGESTION, T1_SUGGESTION])

    await buttonNamed(rowSoldBy(wrapper, 'O-Fourth'), 'Record as sale').trigger('click')
    await flushPromises()

    expect(postedBodies()).toEqual([{ transactionId: T1_ID }])
  })
})

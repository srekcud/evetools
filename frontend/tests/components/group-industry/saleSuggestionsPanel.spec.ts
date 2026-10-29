import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import { dateFormat } from '@/composables/useFormatters'
import { ApiError, apiRequest } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupSale, GroupSaleSuggestion } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const SUGGESTIONS_PATH = `/group-industry/projects/${PROJECT_ID}/sale-suggestions`
const SALES_PATH = `/group-industry/projects/${PROJECT_ID}/sales`
const SALE_FROM_WALLET_TRANSACTION_PATH = `/group-industry/projects/${PROJECT_ID}/sales/from-wallet-transaction`
const T1_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e01'
const T7_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e07'
const ALREADY_LINKED = 'This transaction is already linked to a sale.'

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

const T1_SUGGESTION: GroupSaleSuggestion = {
  transactionId: T1_ID,
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 3,
  unitPrice: 61_250_000,
  totalPrice: 183_750_000,
  date: '2026-10-24T21:05:00+00:00',
  sellerCharacterId: 2_120_000_001,
  sellerCharacterName: 'O-Main',
  locationId: 60_003_760,
  possibleDuplicateSaleIds: ['sale-v'],
}

const SALE_OF_T7: GroupSale = {
  id: 'sale-t7',
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 10,
  unitPrice: 60_000_000,
  totalPrice: 600_000_000,
  venue: null,
  soldAt: '2026-10-26T11:45:00+00:00',
  recordedByCharacterName: 'O-Main',
  sellerCharacterId: 2_120_000_002,
  sellerCharacterName: 'O-Alt',
  linkedToWalletTransaction: true,
  createdAt: '2026-10-29T08:00:00+00:00',
}

function manualSale(id: string, soldAt: string): GroupSale {
  return {
    id,
    typeId: 0,
    typeName: 'Sabre',
    quantity: 3,
    unitPrice: 61_250_000,
    totalPrice: 183_750_000,
    venue: 'Jita 4-4',
    soldAt,
    recordedByCharacterName: 'O-Main',
    sellerCharacterId: null,
    sellerCharacterName: null,
    linkedToWalletTransaction: false,
    createdAt: soldAt,
  }
}

const MANUAL_SALE_V = manualSale('sale-v', '2026-10-24T12:00:00+00:00')
const MANUAL_SALE_V2 = manualSale('sale-v2', '2026-10-25T12:00:00+00:00')

type Responses = {
  sales: () => Promise<unknown>
  suggestions: () => Promise<unknown>
  recording: () => Promise<unknown>
}

let responses: Responses

async function mountPanel(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(SaleSuggestionsPanel, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID },
  })
  await flushPromises()
  return wrapper
}

async function listSalesOfTheProject(sales: GroupSale[]): Promise<void> {
  responses.sales = async () => sales
  await useGroupDistributionStore().fetchSales(PROJECT_ID)
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function columnIndex(wrapper: VueWrapper, header: string): number {
  const index = wrapper.findAll('thead th').findIndex(cell => normalized(cell.text()) === header)
  if (index === -1) throw new Error(`no column "${header}"`)
  return index
}

function suggestionRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr')
}

function shownSuggestions(wrapper: VueWrapper): string[][] {
  const columns = ['Character', 'Quantity', 'Unit Price', 'Total'].map(header => columnIndex(wrapper, header))
  return suggestionRows(wrapper).map(row => {
    const cells = row.findAll('td')
    return columns.map(column => normalized(cells[column].text()))
  })
}

function rowSoldBy(wrapper: VueWrapper, characterName: string): DOMWrapper<Element> {
  const characterColumn = columnIndex(wrapper, 'Character')
  const row = suggestionRows(wrapper).find(candidate => normalized(candidate.findAll('td')[characterColumn].text()) === characterName)
  if (row === undefined) throw new Error(`no suggestion sold by "${characterName}"`)
  return row
}

function buttonNamed(scope: DOMWrapper<Element>, name: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll<HTMLButtonElement>('button').find(candidate => normalized(candidate.text()) === name)
  if (button === undefined) throw new Error(`no button "${name}"`)
  return button
}

async function recordAsSale(wrapper: VueWrapper, characterName: string, quantity?: number): Promise<void> {
  const row = rowSoldBy(wrapper, characterName)
  if (quantity !== undefined) {
    await row.find('input[type="number"]').setValue(quantity)
  }
  await buttonNamed(row, 'Record as sale').trigger('click')
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

beforeEach(() => {
  setActivePinia(createPinia())
  dateFormat.value = 'eu'
  vi.mocked(apiRequest).mockReset()
  responses = {
    sales: async () => [],
    suggestions: async () => [T7_SUGGESTION, T1_SUGGESTION],
    recording: async () => SALE_OF_T7,
  }
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === SUGGESTIONS_PATH) return responses.suggestions()
    if (endpoint === SALES_PATH) return responses.sales()
    if (endpoint === SALE_FROM_WALLET_TRANSACTION_PATH && options?.method === 'POST') return responses.recording()
    throw new Error(`unexpected request ${options?.method ?? 'GET'} ${endpoint}`)
  })
})

describe('SaleSuggestionsPanel list', () => {
  it('lists the wallet transactions to link with their character, quantity, unit price and total', async () => {
    const wrapper = await mountPanel()

    expect(shownSuggestions(wrapper)).toEqual([
      ['O-Alt', '12', '60,000,000', '720,000,000'],
      ['O-Main', '3', '61,250,000', '183,750,000'],
    ])
  })

  it('is titled Transactions to link', async () => {
    const wrapper = await mountPanel()

    expect(normalized(wrapper.text())).toContain('Transactions to link')
  })

  it('flags as possible duplicate only the transaction that matches a manual sale', async () => {
    const wrapper = await mountPanel()

    expect([
      normalized(rowSoldBy(wrapper, 'O-Alt').text()).includes('Possible duplicate'),
      normalized(rowSoldBy(wrapper, 'O-Main').text()).includes('Possible duplicate'),
    ]).toEqual([false, true])
  })

  it('names the panel, the duplicate badge and the action in French', async () => {
    const wrapper = await mountPanel('fr')

    const text = normalized(wrapper.text())
    expect([text.includes('Transactions à rattacher'), text.includes('Doublon possible'), text.includes('Enregistrer comme vente')]).toEqual([true, true, true])
  })

  it('says there is no wallet transaction to link when the list is empty', async () => {
    responses.suggestions = async () => []

    const wrapper = await mountPanel()

    expect([suggestionRows(wrapper).length, normalized(wrapper.text()).includes('No wallet transaction to link')]).toEqual([0, true])
  })

  it('shows the refusal and never claims an empty list when the suggestions cannot be read', async () => {
    responses.suggestions = async () => {
      throw new ApiError('Only project owner or admin can perform this action', 403)
    }

    const wrapper = await mountPanel()

    const text = normalized(wrapper.text())
    expect([text.includes('Only project owner or admin can perform this action'), text.includes('No wallet transaction to link')]).toEqual([true, false])
  })
})

describe('SaleSuggestionsPanel recording', () => {
  it('records ten units of T7 as a sale', async () => {
    const wrapper = await mountPanel()

    await recordAsSale(wrapper, 'O-Alt', 10)

    expect(postedBodies()).toEqual([{ transactionId: T7_ID, quantity: 10 }])
  })

  it('records the whole transaction T1 when no quantity is entered', async () => {
    const wrapper = await mountPanel()

    await recordAsSale(wrapper, 'O-Main')

    expect(postedBodies()).toEqual([{ transactionId: T1_ID }])
  })

  it('reads the suggestions again once the sale is recorded', async () => {
    const wrapper = await mountPanel()
    responses.suggestions = async () => [T1_SUGGESTION]

    await recordAsSale(wrapper, 'O-Alt', 10)

    expect([suggestionReads(), shownSuggestions(wrapper)]).toEqual([2, [['O-Main', '3', '61,250,000', '183,750,000']]])
  })

  it('shows the conflict and keeps the suggestions when the transaction is already linked', async () => {
    responses.recording = async () => {
      throw new ApiError(ALREADY_LINKED, 409)
    }
    const wrapper = await mountPanel()

    await recordAsSale(wrapper, 'O-Main')

    expect([normalized(wrapper.text()).includes(ALREADY_LINKED), shownSuggestions(wrapper).length]).toEqual([true, 2])
  })
})

describe('SaleSuggestionsPanel possible duplicates', () => {
  it('names the manual sale V that T1 may duplicate, with its date and total', async () => {
    await listSalesOfTheProject([MANUAL_SALE_V])

    const wrapper = await mountPanel()

    expect(normalized(rowSoldBy(wrapper, 'O-Main').text())).toContain('Possible duplicate of the sale of 24/10/26, total 183,750,000 ISK')
  })

  it('names every sale that T1 may duplicate', async () => {
    await listSalesOfTheProject([MANUAL_SALE_V, MANUAL_SALE_V2])
    responses.suggestions = async () => [T7_SUGGESTION, { ...T1_SUGGESTION, possibleDuplicateSaleIds: ['sale-v', 'sale-v2'] }]

    const wrapper = await mountPanel()

    const t1Row = normalized(rowSoldBy(wrapper, 'O-Main').text())
    expect([
      t1Row.includes('Possible duplicate of the sale of 24/10/26, total 183,750,000 ISK'),
      t1Row.includes('Possible duplicate of the sale of 25/10/26, total 183,750,000 ISK'),
    ]).toEqual([true, true])
  })

  it('names the duplicated sale in French', async () => {
    await listSalesOfTheProject([MANUAL_SALE_V])

    const wrapper = await mountPanel('fr')

    expect(normalized(wrapper.text())).toContain('Doublon possible de la vente du 24/10/26, total 183 750 000 ISK')
  })

  it('names no sale it cannot find among the listed sales', async () => {
    await listSalesOfTheProject([])

    const wrapper = await mountPanel()

    const t1Row = normalized(rowSoldBy(wrapper, 'O-Main').text())
    expect([t1Row.includes('Possible duplicate'), t1Row.includes('of the sale of')]).toEqual([true, false])
  })
})

import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import { apiRequest } from '@/services/api'
import type { GroupSaleSuggestion } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const SUGGESTIONS_PATH = `/industry/projects/${PROJECT_ID}/sale-suggestions`
const CONFIRMATION = '[data-testid="sales-record-all-confirmation"]'
const BREAKDOWN = '[data-testid="sales-record-all-products"]'
const PRODUCT_LINE = '[data-testid^="sales-record-all-product-"]'

function suggestion(id: string, typeId: number, typeName: string, quantity: number, unitPrice: number, date: string, seller: string, duplicates: string[] = []): GroupSaleSuggestion {
  return {
    transactionId: id,
    typeId,
    typeName,
    quantity,
    unitPrice,
    totalPrice: quantity * unitPrice,
    date,
    sellerCharacterId: 2_120_000_001,
    sellerCharacterName: seller,
    locationId: 60_003_760,
    possibleDuplicateSaleIds: duplicates,
  }
}

const SABRE_FIRST = suggestion('t-sabre-1', 22_456, 'Sabre', 12, 60_000_000, '2026-10-26T11:45:00+00:00', 'O-Alt')
const SABRE_SECOND = suggestion('t-sabre-2', 22_456, 'Sabre', 3, 61_250_000, '2026-10-24T21:05:00+00:00', 'O-Main')
const HERETIC = suggestion('t-heretic', 22_460, 'Heretic', 2, 15_500_000, '2026-10-25T08:30:00+00:00', 'O-Third')
const ERIS_DUPLICATE = suggestion('t-eris', 22_452, 'Eris', 7, 9_000_000, '2026-10-25T09:00:00+00:00', 'O-Fourth', ['sale-v'])

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

async function mountPanelWith(suggestions: GroupSaleSuggestion[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SUGGESTIONS_PATH) return { id: PROJECT_ID, suggestions, walletReadings: [] }
    return []
  })
  const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(SaleSuggestionsPanel, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID },
  })
  await flushPromises()
  return wrapper
}

async function openConfirmation(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('[data-testid="sales-record-all"]').trigger('click')
  await flushPromises()
}

function productLines(wrapper: VueWrapper): string[] {
  return wrapper.get(CONFIRMATION).findAll(PRODUCT_LINE).map(line => normalized(line.text()))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('SaleSuggestionsPanel item', () => {
  it('shows the product of each suggestion in an Item column', async () => {
    const wrapper = await mountPanelWith([SABRE_FIRST, HERETIC])

    const headers = wrapper.findAll('thead th').map(cell => normalized(cell.text()))
    const itemIndex = headers.indexOf('Item')

    expect(itemIndex).toBeGreaterThanOrEqual(0)
    expect(wrapper.findAll('tbody tr').map(row => normalized(row.findAll('td')[itemIndex].text()))).toEqual(['Sabre', 'Heretic'])
  })

  it('keeps every row as wide as the header', async () => {
    const wrapper = await mountPanelWith([SABRE_FIRST, HERETIC])

    expect(wrapper.findAll('thead th').map(cell => normalized(cell.text()))).toContain('Item')
    const headerWidth = wrapper.findAll('thead th').length

    expect(wrapper.findAll('tbody tr').map(row => row.findAll('td').length)).toEqual([headerWidth, headerWidth])
  })
})

describe('SaleSuggestionsPanel record all confirmation by product', () => {
  it('lists each product once with its units and total, adding up the transactions of the same product', async () => {
    const wrapper = await mountPanelWith([SABRE_FIRST, SABRE_SECOND, HERETIC])

    await openConfirmation(wrapper)

    expect(wrapper.get(CONFIRMATION).find(BREAKDOWN).exists()).toBe(true)
    expect(productLines(wrapper)).toHaveLength(2)
    const sabre = productLines(wrapper).find(line => line.includes('Sabre'))
    const heretic = productLines(wrapper).find(line => line.includes('Heretic'))
    expect(sabre).toContain('15')
    expect(sabre).toContain('903.8 M')
    expect(heretic).toContain('2')
    expect(heretic).toContain('31.0 M')
  })

  it('leaves a product out of the breakdown when all its transactions are possible duplicates', async () => {
    const wrapper = await mountPanelWith([SABRE_FIRST, SABRE_SECOND, ERIS_DUPLICATE])

    await openConfirmation(wrapper)

    expect(productLines(wrapper)).toHaveLength(1)
    expect(productLines(wrapper)[0]).toContain('Sabre')
  })

  it('still states the transaction count, the units and the ISK total of the whole plan', async () => {
    const wrapper = await mountPanelWith([SABRE_FIRST, SABRE_SECOND, HERETIC])

    await openConfirmation(wrapper)

    expect(wrapper.get('[data-testid="sales-record-all-count"]').text()).toContain('3')
    expect(wrapper.get('[data-testid="sales-record-all-quantity"]').text()).toContain('17')
    expect(wrapper.get('[data-testid="sales-record-all-total"]').text()).toContain('934.8 M')
  })
})

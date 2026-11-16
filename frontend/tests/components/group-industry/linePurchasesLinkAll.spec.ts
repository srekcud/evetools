import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LinePurchases from '@/components/group-industry/LinePurchases.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject, PurchaseSuggestion, PurchaseSuggestions } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-titan-batch'
const LINE_ID = 'job-capital-armor-plates'
const SUGGESTIONS_URL = `/industry/projects/${PROJECT_ID}/purchase-suggestions`
const PURCHASES_URL = `/industry/projects/${PROJECT_ID}/bom/${LINE_ID}/purchases`
const OPEN_PURCHASES = '[data-testid="purchases-open"]'
const SUGGESTION_LINKED = '[data-testid="purchase-suggestion-linked"]'
const LINK_ALL = '[data-testid="purchases-link-all"]'
const LINK_ALL_CONFIRMATION = '[data-testid="purchases-link-all-confirmation"]'
const LINK_ALL_COUNT = '[data-testid="purchases-link-all-count"]'
const LINK_ALL_QUANTITY = '[data-testid="purchases-link-all-quantity"]'
const LINK_ALL_TOTAL = '[data-testid="purchases-link-all-total"]'
const LINK_ALL_LEFT_OUT = '[data-testid="purchases-link-all-left-out"]'
const LINK_ALL_CONFIRM = '[data-testid="purchases-link-all-confirm"]'
const LINK_ALL_CANCEL = '[data-testid="purchases-link-all-cancel"]'
const PURCHASE_ERROR = '[data-testid="purchase-error"]'
const PLATES_TYPE_ID = 21010
const UNIT_PRICE = 1_000_000

function suggestion(transactionId: number, day: number, quantity: number, alreadyLinked = false): PurchaseSuggestion {
  return {
    transactionId,
    transactionUuid: `0192f0c4-7b1e-7a3c-9d2e-${String(transactionId).padStart(12, '0')}`,
    typeId: PLATES_TYPE_ID,
    typeName: 'Capital Armor Plates',
    quantity,
    unitPrice: UNIT_PRICE,
    totalPrice: quantity * UNIT_PRICE,
    date: `2026-11-${String(day).padStart(2, '0')}T19:30:00+00:00`,
    characterName: 'Cora Vance',
    locationId: 60003760,
    alreadyLinked,
  }
}

function uuidOf(transactionId: number): string {
  return suggestion(transactionId, 1, 1).transactionUuid
}

function lineNeeding(requiredQuantity: number, purchasedQuantity: number): BomItem {
  return {
    id: LINE_ID,
    typeId: PLATES_TYPE_ID,
    typeName: 'Capital Armor Plates',
    requiredQuantity,
    purchasedQuantity,
    purchases: [],
  } as unknown as BomItem
}

function suggestionsOf(suggestions: PurchaseSuggestion[]): PurchaseSuggestions {
  return { totalCount: suggestions.length, walletReadings: [], suggestions }
}

async function openedModal(item: BomItem, suggestions: PurchaseSuggestion[], myRole: 'owner' | 'member' = 'owner'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((async (url: string) => (url === SUGGESTIONS_URL ? suggestionsOf(suggestions) : {})) as never)
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'Titan batch', status: 'in_progress', myRole, items: [] } as unknown as GroupProject
  const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(LinePurchases, { props: { item }, global: { plugins: [i18n], stubs: { teleport: true } } })
  await wrapper.get(OPEN_PURCHASES).trigger('click')
  await flushPromises()
  return wrapper
}

function postedTransactionIds(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([url, options]) => url === PURCHASES_URL && options?.method === 'POST')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

async function openConfirmation(wrapper: VueWrapper): Promise<void> {
  await wrapper.get(LINK_ALL).trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('LinePurchases link all', () => {
  it('summarises count, total quantity and total ISK of the transactions it will link before sending anything', async () => {
    const wrapper = await openedModal(lineNeeding(100, 30), [suggestion(1, 10, 20), suggestion(2, 11, 30)])

    await openConfirmation(wrapper)

    expect(wrapper.find(LINK_ALL_CONFIRMATION).exists()).toBe(true)
    expect(wrapper.get(LINK_ALL_COUNT).text()).toContain('2')
    expect(wrapper.get(LINK_ALL_QUANTITY).text()).toContain('50')
    expect(wrapper.get(LINK_ALL_TOTAL).text()).toContain('50.0 M')
    expect(postedTransactionIds()).toEqual([])
  })

  it('links every selected transaction oldest first with its transaction id only and flips the rows to linked', async () => {
    const wrapper = await openedModal(lineNeeding(100, 30), [suggestion(2, 12, 30), suggestion(1, 10, 20)])

    await openConfirmation(wrapper)
    await wrapper.get(LINK_ALL_CONFIRM).trigger('click')
    await flushPromises()

    expect(postedTransactionIds()).toEqual([{ transactionId: uuidOf(1) }, { transactionId: uuidOf(2) }])
    expect(wrapper.findAll(SUGGESTION_LINKED)).toHaveLength(2)
    expect(wrapper.find(LINK_ALL).exists()).toBe(false)
  })

  it('sends nothing when the confirmation is cancelled', async () => {
    const wrapper = await openedModal(lineNeeding(100, 30), [suggestion(1, 10, 20), suggestion(2, 11, 30)])

    await openConfirmation(wrapper)
    await wrapper.get(LINK_ALL_CANCEL).trigger('click')
    await flushPromises()

    expect(wrapper.find(LINK_ALL_CONFIRMATION).exists()).toBe(false)
    expect(postedTransactionIds()).toEqual([])
  })

  it('leaves out a transaction that would go beyond the quantity left to buy and says how many', async () => {
    const wrapper = await openedModal(lineNeeding(100, 30), [suggestion(1, 10, 20), suggestion(2, 11, 30), suggestion(3, 12, 40)])

    await openConfirmation(wrapper)

    expect(wrapper.get(LINK_ALL_COUNT).text()).toContain('2')
    expect(wrapper.get(LINK_ALL_QUANTITY).text()).toContain('50')
    expect(wrapper.get(LINK_ALL_LEFT_OUT).text()).toContain('1')
    await wrapper.get(LINK_ALL_CONFIRM).trigger('click')
    await flushPromises()
    expect(postedTransactionIds()).toEqual([{ transactionId: uuidOf(1) }, { transactionId: uuidOf(2) }])
  })

  it('skips a transaction that does not fit and still links a later, smaller one', async () => {
    const wrapper = await openedModal(lineNeeding(100, 30), [suggestion(1, 10, 50), suggestion(2, 11, 30), suggestion(3, 12, 10)])

    await openConfirmation(wrapper)
    await wrapper.get(LINK_ALL_CONFIRM).trigger('click')
    await flushPromises()

    expect(postedTransactionIds()).toEqual([{ transactionId: uuidOf(1) }, { transactionId: uuidOf(3) }])
  })

  it('does not mention a left-out transaction when every unlinked one fits', async () => {
    const wrapper = await openedModal(lineNeeding(100, 30), [suggestion(1, 10, 20), suggestion(2, 11, 30)])

    await openConfirmation(wrapper)

    expect(wrapper.find(LINK_ALL_LEFT_OUT).exists()).toBe(false)
  })

  it('does not count a transaction that is already linked', async () => {
    const wrapper = await openedModal(lineNeeding(100, 0), [suggestion(1, 10, 20, true), suggestion(2, 11, 30), suggestion(3, 12, 10)])

    await openConfirmation(wrapper)
    await wrapper.get(LINK_ALL_CONFIRM).trigger('click')
    await flushPromises()

    expect(postedTransactionIds()).toEqual([{ transactionId: uuidOf(2) }, { transactionId: uuidOf(3) }])
  })

  it('offers no link all when a single transaction would be linked', async () => {
    const wrapper = await openedModal(lineNeeding(100, 30), [suggestion(1, 10, 80), suggestion(2, 11, 10)])

    expect(wrapper.find(LINK_ALL).exists()).toBe(false)
  })

  it('offers no link all when the line has nothing left to buy', async () => {
    const wrapper = await openedModal(lineNeeding(100, 100), [suggestion(1, 10, 20), suggestion(2, 11, 30)])

    expect(wrapper.find(LINK_ALL).exists()).toBe(false)
  })

  it('offers link all to a member who is not the owner', async () => {
    const wrapper = await openedModal(lineNeeding(100, 0), [suggestion(1, 10, 20), suggestion(2, 11, 30)], 'member')

    expect(wrapper.find(LINK_ALL).exists()).toBe(true)
  })

  it('stops at the first failure, keeps what is already linked and shows the error', async () => {
    const wrapper = await openedModal(lineNeeding(100, 0), [suggestion(1, 10, 20), suggestion(2, 11, 30), suggestion(3, 12, 10)])
    vi.mocked(apiRequest).mockImplementation((async (url: string, options?: RequestInit) => {
      if (url === SUGGESTIONS_URL) return suggestionsOf([suggestion(1, 10, 20), suggestion(2, 11, 30), suggestion(3, 12, 10)])
      if (url === PURCHASES_URL && String(options?.body).includes(uuidOf(2))) throw new Error('Transaction already linked')
      return {}
    }) as never)

    await openConfirmation(wrapper)
    await wrapper.get(LINK_ALL_CONFIRM).trigger('click')
    await flushPromises()

    expect(postedTransactionIds()).toEqual([{ transactionId: uuidOf(1) }, { transactionId: uuidOf(2) }])
    expect(wrapper.findAll(SUGGESTION_LINKED)).toHaveLength(1)
    expect(wrapper.get(PURCHASE_ERROR).text()).toContain('Transaction already linked')
    expect(wrapper.find(LINK_ALL_CONFIRMATION).exists()).toBe(false)
  })
})

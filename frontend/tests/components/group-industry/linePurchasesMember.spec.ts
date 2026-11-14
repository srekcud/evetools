import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LinePurchases from '@/components/group-industry/LinePurchases.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupMemberRole, GroupProject, ProjectPurchase, PurchaseSuggestions } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-titan-batch'
const SUGGESTIONS_URL = `/industry/projects/${PROJECT_ID}/purchase-suggestions`
const OPEN_PURCHASES = '[data-testid="purchases-open"]'
const SUGGESTION = '[data-testid="purchase-suggestion"]'
const SUGGESTION_LINK = '[data-testid="purchase-suggestion-link"]'
const PURCHASE = '[data-testid="purchase"]'
const PURCHASE_DELETE = '[data-testid="purchase-delete"]'
const MANUAL_QUANTITY = '[data-testid="manual-purchase-quantity"]'
const MANUAL_SUBMIT = '[data-testid="manual-purchase-submit"]'
const PLATES_TYPE_ID = 21010
const MEMBER_SUGGESTION_UUID = '0192f0c4-7b1e-7a3c-9d2e-981000000201'

const MEMBER_SUGGESTIONS: PurchaseSuggestions = {
  totalCount: 1,
  walletReadings: [],
  suggestions: [
    {
      transactionId: 6_981_000_201,
      transactionUuid: MEMBER_SUGGESTION_UUID,
      typeId: PLATES_TYPE_ID,
      typeName: 'Capital Armor Plates',
      quantity: 14,
      unitPrice: 1_070_000,
      totalPrice: 14_980_000,
      date: '2026-11-13T19:30:00+00:00',
      characterName: 'Cora Vance',
      locationId: 60003760,
      alreadyLinked: false,
    },
  ],
}

function purchase(overrides: Partial<ProjectPurchase>): ProjectPurchase {
  return {
    id: 'purchase',
    bomLineId: 'job-capital-armor-plates',
    typeId: PLATES_TYPE_ID,
    quantity: 1,
    unitPrice: 1_000_000,
    totalPrice: 1_000_000,
    source: 'esi_wallet',
    transactionId: 'transaction-uuid',
    createdAt: '2026-11-13T09:00:00+00:00',
    isMine: false,
    buyerName: null,
    ...overrides,
  }
}

function lineWith(purchases: ProjectPurchase[]): BomItem {
  return {
    id: 'job-capital-armor-plates',
    typeId: PLATES_TYPE_ID,
    typeName: 'Capital Armor Plates',
    purchases,
  } as unknown as BomItem
}

function mountPurchases(myRole: GroupMemberRole, item: BomItem): VueWrapper {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'Titan batch', status: 'in_progress', myRole, items: [] } as unknown as GroupProject
  const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })
  return mount(LinePurchases, { props: { item }, global: { plugins: [i18n], stubs: { teleport: true } } })
}

async function openPurchases(wrapper: VueWrapper): Promise<void> {
  await wrapper.get(OPEN_PURCHASES).trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (url: string) => (url === SUGGESTIONS_URL ? MEMBER_SUGGESTIONS : {})) as never)
})

describe('LinePurchases for a participant who is not the owner', () => {
  it('opens the purchase window for a member even when the line has no purchase yet and lets him link his own transaction', async () => {
    const wrapper = mountPurchases('member', lineWith([]))

    expect(wrapper.find(OPEN_PURCHASES).exists()).toBe(true)
    await openPurchases(wrapper)

    expect(wrapper.findAll(SUGGESTION)).toHaveLength(1)
    await wrapper.get(SUGGESTION_LINK).trigger('click')
    await flushPromises()
    const linkCall = vi.mocked(apiRequest).mock.calls.find(([url]) => url === `/industry/projects/${PROJECT_ID}/bom/job-capital-armor-plates/purchases`)
    expect(JSON.parse(String(linkCall?.[1]?.body))).toEqual({ transactionId: MEMBER_SUGGESTION_UUID })
  })

  it('shows the manual entry to the owner and hides it from a member', async () => {
    const manualEntryOf = async (myRole: GroupMemberRole) => {
      const wrapper = mountPurchases(myRole, lineWith([]))
      const canOpen = wrapper.find(OPEN_PURCHASES).exists()
      if (canOpen) await openPurchases(wrapper)
      return { canOpen, quantity: wrapper.find(MANUAL_QUANTITY).exists(), submit: wrapper.find(MANUAL_SUBMIT).exists() }
    }

    expect({ owner: await manualEntryOf('owner'), member: await manualEntryOf('member') }).toEqual({
      owner: { canOpen: true, quantity: true, submit: true },
      member: { canOpen: true, quantity: false, submit: false },
    })
  })

  it('offers the deletion of a purchase to a member for his own purchases only', () => {
    const wrapper = mountPurchases('member', lineWith([
      purchase({ id: 'purchase-aria', quantity: 60, buyerName: 'Aria Solberg', isMine: false }),
      purchase({ id: 'purchase-cora', quantity: 40, buyerName: 'Cora Vance', isMine: true }),
    ]))

    const rows = wrapper.findAll(PURCHASE)
    expect(rows).toHaveLength(2)
    expect(rows.map(row => row.find(PURCHASE_DELETE).exists())).toEqual([false, true])
  })

  it('offers the deletion of every purchase to the owner, whoever bought it', () => {
    const wrapper = mountPurchases('owner', lineWith([
      purchase({ id: 'purchase-aria', quantity: 60, buyerName: 'Aria Solberg', isMine: true }),
      purchase({ id: 'purchase-cora', quantity: 40, buyerName: 'Cora Vance', isMine: false }),
    ]))

    expect(wrapper.findAll(PURCHASE).map(row => row.find(PURCHASE_DELETE).exists())).toEqual([true, true])
  })

  it('names the buyer after the quantity of a purchase that is not mine and no one for my own', () => {
    const wrapper = mountPurchases('member', lineWith([
      purchase({ id: 'purchase-aria', quantity: 60, buyerName: 'Aria Solberg', isMine: false }),
      purchase({ id: 'purchase-cora', quantity: 40, buyerName: 'Cora Vance', isMine: true }),
    ]))

    const rows = wrapper.findAll(PURCHASE).map(row => row.text())
    expect(rows[0]).toContain('Aria Solberg')
    expect(rows[1]).not.toContain('Cora Vance')
  })
})

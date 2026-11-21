import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LinePurchases from '@/components/group-industry/LinePurchases.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupMemberRole, GroupProject, GroupProjectStatus, ProjectPurchase } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OPEN_PURCHASES = '[data-testid="purchases-open"]'
const PURCHASE = '[data-testid="purchase"]'
const PLATES_TYPE_ID = 21010

function recordedPurchase(): ProjectPurchase {
  return {
    id: 'purchase-plates',
    bomLineId: 'job-capital-armor-plates',
    typeId: PLATES_TYPE_ID,
    quantity: 10,
    unitPrice: 1_000_000,
    totalPrice: 10_000_000,
    source: 'esi_wallet',
    transactionId: 'transaction-uuid',
    createdAt: '2026-11-13T09:00:00+00:00',
    isMine: true,
    buyerName: null,
  }
}

function line(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'job-capital-armor-plates',
    typeId: PLATES_TYPE_ID,
    typeName: 'Capital Armor Plates',
    requiredQuantity: 10,
    purchasedQuantity: 0,
    remainingQuantity: 10,
    isFulfilled: false,
    purchases: [],
    ...overrides,
  } as unknown as BomItem
}

function mountPurchases(status: GroupProjectStatus, item: BomItem, myRole: GroupMemberRole = 'owner'): VueWrapper {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: 'project-titan-batch', name: 'Titan batch', status, myRole, items: [] } as unknown as GroupProject
  const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })
  return mount(LinePurchases, { props: { item }, global: { plugins: [i18n], stubs: { teleport: true } } })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('LinePurchases button visibility', () => {
  it('shows the Purchases button on an open line of a project in progress', () => {
    const wrapper = mountPurchases('in_progress', line({}))

    expect(wrapper.find(OPEN_PURCHASES).exists()).toBe(true)
  })

  it('hides the Purchases button on a line the purchases already cover', () => {
    const wrapper = mountPurchases('in_progress', line({ isFulfilled: true, remainingQuantity: 0, purchasedQuantity: 10, purchases: [recordedPurchase()] }))

    expect(wrapper.find(OPEN_PURCHASES).exists()).toBe(false)
  })

  it('hides the Purchases button on every line once the project is completed', () => {
    const wrapper = mountPurchases('completed', line({}))

    expect(wrapper.find(OPEN_PURCHASES).exists()).toBe(false)
  })

  it('keeps listing the recorded purchases of a covered line so they can still be read', () => {
    const wrapper = mountPurchases('in_progress', line({ isFulfilled: true, remainingQuantity: 0, purchasedQuantity: 10, purchases: [recordedPurchase()] }))

    expect(wrapper.findAll(PURCHASE)).toHaveLength(1)
  })

  it('hides the Purchases button from a member on a covered line', () => {
    const wrapper = mountPurchases('in_progress', line({ isFulfilled: true, remainingQuantity: 0 }), 'member')

    expect(wrapper.find(OPEN_PURCHASES).exists()).toBe(false)
  })
})

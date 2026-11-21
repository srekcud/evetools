import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LinePurchases from '@/components/group-industry/LinePurchases.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupMemberRole, GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1180'
const OPEN_PURCHASES = '[data-testid="purchases-open"]'
const DECLARE_STOCK = '[data-testid="purchases-declare-stock"]'
const TRITANIUM_TYPE_ID = 34

function line(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'bom-tritanium',
    typeId: TRITANIUM_TYPE_ID,
    typeName: 'Tritanium',
    isJob: false,
    purchases: [],
    ...overrides,
  } as unknown as BomItem
}

function mountPurchases(myRole: GroupMemberRole | null, item: BomItem): VueWrapper {
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
  vi.mocked(apiRequest).mockResolvedValue({ suggestions: [], totalCount: 0, walletReadings: [] })
})

describe('purchases panel leads to the stock declaration', () => {
  it('offers to declare the stock at the Jita price, closes the panel and hands the line over', async () => {
    const item = line({})
    const wrapper = mountPurchases('member', item)
    await openPurchases(wrapper)

    expect(wrapper.get(DECLARE_STOCK).text()).toBe(en.groupIndustry.purchases.declareStock.button)
    await wrapper.get(DECLARE_STOCK).trigger('click')

    expect(wrapper.emitted('contribute')).toEqual([[item]])
    expect(wrapper.find(DECLARE_STOCK).exists()).toBe(false)
  })

  it('does not offer it on a job line', async () => {
    const wrapper = mountPurchases('member', line({ isJob: true }))
    await openPurchases(wrapper)

    expect(wrapper.find(DECLARE_STOCK).exists()).toBe(false)
  })

  it('still lists the wallet transactions panel for the line', async () => {
    const wrapper = mountPurchases('owner', line({}))
    await openPurchases(wrapper)

    expect(wrapper.text()).toContain(en.groupIndustry.purchases.suggestionsTitle)
  })
})

import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace })) as never,
}))

const RAVEN_TYPE_ID = 638
const RAVEN_NAVY_ISSUE_TYPE_ID = 17636
const SABRE_TYPE_ID = 22456

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  teleport: true,
  ErrorBanner: true,
  ProductSearch: true,
  BlacklistConfig: true,
  StructureConfig: true,
  SkillsConfig: true,
  FeesConfig: true,
  IndustryDashboard: true,
  IndustrySlotsSection: true,
  ProfitMarginTab: true,
  BatchScanTab: true,
  BuyVsBuildTab: true,
  PivotAdvisorTab: true,
  StockpileDashboard: true,
}

async function mountIndustryWithQuery(query: LocationQuery) {
  route.query = query
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

async function changeQuery(query: LocationQuery): Promise<void> {
  route.query = query
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  routerReplace.mockClear()
  useIndustryStore().navigationIntent = null
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry type in the URL', () => {
  it.each([
    ['margins', 'ProfitMarginTab', RAVEN_TYPE_ID],
    ['buy-vs-build', 'BuyVsBuildTab', RAVEN_NAVY_ISSUE_TYPE_ID],
    ['pivot', 'PivotAdvisorTab', SABRE_TYPE_ID],
  ] as const)('preselects the type of the query in the %s tab at mount', async (tab, tabComponentName, typeId) => {
    const wrapper = await mountIndustryWithQuery({ tab, typeId: String(typeId) })
    const tabComponent = wrapper.findComponent({ name: tabComponentName })

    expect(tabComponent.exists()).toBe(true)
    expect(tabComponent.props('initialTypeId')).toBe(typeId)
  })

  it('preselects the type of the query when it changes on a mounted screen', async () => {
    const wrapper = await mountIndustryWithQuery({})

    await changeQuery({ tab: 'buy-vs-build', typeId: String(RAVEN_NAVY_ISSUE_TYPE_ID) })

    expect(wrapper.findComponent(BuyVsBuildTab).props('initialTypeId')).toBe(RAVEN_NAVY_ISSUE_TYPE_ID)
  })

  it('preselects no type for a type id that is not a number', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'buy-vs-build', typeId: 'raven' })

    expect(wrapper.findComponent(BuyVsBuildTab).props('initialTypeId')).toBeUndefined()
  })
})

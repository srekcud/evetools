import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn((location: { query: LocationQuery }) => {
  route.query = location.query
})

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace, push: vi.fn() })) as never,
}))

const RAVEN_TYPE_ID = 638
const SCAN_SUB_TAB = 'Batch Scan'
const MARGINS_SUB_TAB = 'Margins'
const PIVOT_SUB_TAB = 'Pivot'

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  IndustryModuleTabs: true,
  teleport: true,
  ErrorBanner: true,
  DataFreshnessList: true,
  ProfitMarginTab: true,
  BatchScanTab: true,
  BuyVsBuildTab: true,
  PivotAdvisorTab: true,
}

async function mountAnalysisWithQuery(query: LocationQuery) {
  route.query = query
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { props: { page: 'analysis' }, global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountAnalysisWithQuery>>

async function clickSubTab(wrapper: Wrapper, label: string): Promise<void> {
  const subTab = wrapper.findAll('[role="tab"]').find(candidate => candidate.text().trim() === label)
  if (!subTab) throw new Error(`No sub-tab named ${label}`)
  await subTab.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  routerReplace.mockClear()
  useIndustryStore().navigationIntent = null
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry analysis sub-tabs and the type of the query', () => {
  it('keeps the type and every other parameter of the query when another sub-tab is clicked', async () => {
    const wrapper = await mountAnalysisWithQuery({ tab: 'margins', typeId: String(RAVEN_TYPE_ID), origin: 'dashboard' })

    await clickSubTab(wrapper, PIVOT_SUB_TAB)

    expect(route.query).toEqual({ tab: 'pivot', typeId: String(RAVEN_TYPE_ID), origin: 'dashboard' })
  })

  it('still has the type in the query and preselected after a round trip through the scan sub-tab', async () => {
    const wrapper = await mountAnalysisWithQuery({ tab: 'margins', typeId: String(RAVEN_TYPE_ID) })

    await clickSubTab(wrapper, SCAN_SUB_TAB)
    await clickSubTab(wrapper, MARGINS_SUB_TAB)

    expect(route.query).toEqual({ typeId: String(RAVEN_TYPE_ID) })
    expect(wrapper.findComponent(ProfitMarginTab).props('initialTypeId')).toBe(RAVEN_TYPE_ID)
  })
})

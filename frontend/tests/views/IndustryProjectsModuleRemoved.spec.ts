import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import IndustryWorkshop from '@/components/industry/IndustryWorkshop.vue'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'
import { industryPageOfTab, landingOf } from './industryPages'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()
const routerPush = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace, push: routerPush })) as never,
}))

const PROJECTS_PILL_LABEL = 'Projects'
const SABRE_TYPE_ID = 22456

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  IndustryModuleTabs: true,
  teleport: true,
  ErrorBanner: true,
  ProductSearch: true,
  BlacklistConfig: true,
  StructureConfig: true,
  SkillsConfig: true,
  FeesConfig: true,
  IndustryDashboard: true,
  IndustryWorkshop: true,
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
  const wrapper = mount(Industry, { props: { page: industryPageOfTab(query.tab) }, global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  routerReplace.mockClear()
  routerPush.mockClear()
  useIndustryStore().navigationIntent = null
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry without the solo projects module', () => {
  it('offers no Projects pill', async () => {
    const wrapper = await mountIndustryWithQuery({})

    expect(wrapper.findAll('button').map(button => button.text().trim())).not.toContain(PROJECTS_PILL_LABEL)
  })

  it('falls back to the workshop for the old projects tab of the query', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'projects', project: 'p1' })

    expect(wrapper.findComponent(IndustryWorkshop).exists()).toBe(true)
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('sends a projects navigation intent to the projects page and leaves the intent for it', async () => {
    await mountIndustryWithQuery({})
    const prefill = { typeId: SABRE_TYPE_ID, typeName: 'Sabre', runs: 7, me: 3 }

    useIndustryStore().navigationIntent = { target: 'projects', prefill }
    await flushPromises()

    expect(routerPush).toHaveBeenCalledTimes(1)
    expect(landingOf(routerPush.mock.calls[0][0])).toEqual({ path: '/industry/projects', query: {} })
    expect(useIndustryStore().navigationIntent).toEqual({ target: 'projects', prefill })
  })
})

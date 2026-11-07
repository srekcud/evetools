import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import IndustryWorkshop from '@/components/industry/IndustryWorkshop.vue'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace })) as never,
}))

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
  const wrapper = mount(Industry, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountIndustryWithQuery>>

function buttonNamed(wrapper: Wrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text().trim() === label)
  if (!button) throw new Error(`No button named ${label}`)
  return button
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  routerReplace.mockClear()
  useIndustryStore().navigationIntent = null
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry tab in the URL', () => {
  it('opens the workshop by default on its pill, the first of the overview group, and still opens the dashboard when the query names it', async () => {
    const wrapper = await mountIndustryWithQuery({})
    const firstPill = wrapper.findAll('button')[0]
    const defaultView = [wrapper.findComponent(IndustryWorkshop).exists(), wrapper.findComponent(IndustryDashboard).exists()]

    const dashboardWrapper = await mountIndustryWithQuery({ tab: 'dashboard' })
    const dashboardView = [dashboardWrapper.findComponent(IndustryWorkshop).exists(), dashboardWrapper.findComponent(IndustryDashboard).exists()]

    expect(firstPill.text().trim()).toBe(en.industry.tabs.workshop)
    expect(firstPill.classes()).toContain('nav-pill-active')
    expect(defaultView).toEqual([true, false])
    expect(dashboardView).toEqual([false, true])
    expect(buttonNamed(dashboardWrapper, en.industry.tabs.dashboard).classes()).toContain('nav-pill-active')
  })

  it('shows the batch scan tab when mounted with the batch tab in the query', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'batch' })

    expect(wrapper.findComponent(BatchScanTab).exists()).toBe(true)
    expect(buttonNamed(wrapper, en.industry.tabs.batch).classes()).toContain('nav-pill-active')
  })

  it('writes the pivot tab in the query when the pivot pill is clicked', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'batch' })

    await buttonNamed(wrapper, en.industry.tabs.pivot).trigger('click')
    await flushPromises()

    expect(routerReplace).toHaveBeenCalledTimes(1)
    expect(routerReplace).toHaveBeenCalledWith({ query: { tab: 'pivot' } })
  })
})

import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import BlueprintLibraryTab from '@/components/industry/BlueprintLibraryTab.vue'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import IndustryWorkshop from '@/components/industry/IndustryWorkshop.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import StockpileDashboard from '@/components/industry/StockpileDashboard.vue'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'
import type { IndustryTestPage } from './industryPages'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace, push: vi.fn() })) as never,
}))

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  IndustryModuleTabs: true,
  teleport: true,
  ErrorBanner: true,
  DataFreshnessList: true,
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
  BlueprintLibraryTab: true,
}

async function mountPage(page: IndustryTestPage, query: LocationQuery) {
  route.query = query
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { props: { page }, global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountPage>>

function activeModulePage(wrapper: Wrapper): unknown {
  const moduleTabs = wrapper.findComponent(IndustryModuleTabs)
  return moduleTabs.exists() ? moduleTabs.props('active') : 'no module tabs mounted'
}

function subTabs(wrapper: Wrapper) {
  return wrapper.findAll('[role="tab"]')
}

function subTabLabels(wrapper: Wrapper): string[] {
  return subTabs(wrapper).map(subTab => subTab.text().trim())
}

function selectedSubTabLabels(wrapper: Wrapper): string[] {
  return subTabs(wrapper).filter(subTab => subTab.attributes('aria-selected') === 'true').map(subTab => subTab.text().trim())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  routerReplace.mockClear()
  useIndustryStore().navigationIntent = null
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry pages and their sub-tabs', () => {
  it('lists the four analysis sub-tabs and opens the margins one by default', async () => {
    const wrapper = await mountPage('analysis', {})

    expect(subTabLabels(wrapper)).toEqual(['Margins', 'Batch Scan', 'Buy vs Build', 'Pivot'])
    expect(selectedSubTabLabels(wrapper)).toEqual(['Margins'])
    expect(wrapper.findComponent(ProfitMarginTab).exists()).toBe(true)
  })

  it('lists the three production sub-tabs and opens the slots one by default', async () => {
    const wrapper = await mountPage('production', {})

    expect(subTabLabels(wrapper)).toEqual(['Slots', 'Stockpile', 'Blueprints'])
    expect(selectedSubTabLabels(wrapper)).toEqual(['Slots'])
    expect(wrapper.findComponent(IndustrySlotsSection).exists()).toBe(true)
  })

  it.each([
    ['analysis', { tab: 'slots' }, ProfitMarginTab],
    ['production', { tab: 'margins' }, IndustrySlotsSection],
    ['analysis', { tab: 'unknown' }, ProfitMarginTab],
  ] as const)('falls back to the default sub-tab of the %s page for the query %j', async (page, query, defaultSubTab) => {
    const wrapper = await mountPage(page, query)

    expect(wrapper.findComponent(defaultSubTab).exists()).toBe(true)
  })

  it('opens the sub-tab named in the query, on the analysis page and on the production page', async () => {
    const analysis = await mountPage('analysis', { tab: 'batch' })

    expect(analysis.findComponent(BatchScanTab).exists()).toBe(true)
    expect(selectedSubTabLabels(analysis)).toEqual(['Batch Scan'])
    analysis.unmount()

    const production = await mountPage('production', { tab: 'blueprints' })

    expect(production.findComponent(BlueprintLibraryTab).exists()).toBe(true)
    expect(production.findComponent(StockpileDashboard).exists()).toBe(false)
    expect(selectedSubTabLabels(production)).toEqual(['Blueprints'])
  })

  it('writes the pivot sub-tab in the query when it is clicked', async () => {
    const wrapper = await mountPage('analysis', { tab: 'batch' })

    await subTabs(wrapper).find(subTab => subTab.text().trim() === 'Pivot')?.trigger('click')
    await flushPromises()

    expect(routerReplace).toHaveBeenCalledTimes(1)
    expect(routerReplace).toHaveBeenCalledWith({ query: { tab: 'pivot' } })
  })

  it.each([
    ['workshop', 'workshop'],
    ['analysis', 'analysis'],
    ['production', 'production'],
  ] as const)('marks the %s page as the active page of the module tabs', async (page, active) => {
    const wrapper = await mountPage(page, {})

    expect(activeModulePage(wrapper)).toBe(active)
  })

  it('shows the dashboard blocks then the workshop on the workshop page, with no sub-tab', async () => {
    const wrapper = await mountPage('workshop', {})

    const dashboard = wrapper.findComponent(IndustryDashboard)
    const workshop = wrapper.findComponent(IndustryWorkshop)
    expect(dashboard.exists()).toBe(true)
    expect(workshop.exists()).toBe(true)
    expect(dashboard.element.compareDocumentPosition(workshop.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(subTabs(wrapper)).toHaveLength(0)
  })

  it.each(['workshop', 'analysis', 'production'] as const)('keeps the configuration panel reachable on the %s page', async (page) => {
    const wrapper = await mountPage(page, {})

    await wrapper.get(`button[aria-label="${en.industry.tabs.config}"]`).trigger('click')

    expect(wrapper.text()).toContain(en.industry.config.maxJobDuration)
  })
})

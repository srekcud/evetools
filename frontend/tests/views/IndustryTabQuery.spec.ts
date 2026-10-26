import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import { useProjectsStore } from '@/stores/industry/projects'
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
  ProjectTable: true,
  ProjectDetail: true,
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
  vi.spyOn(useProjectsStore(), 'fetchProjects').mockResolvedValue(undefined as never)
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry tab in the URL', () => {
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

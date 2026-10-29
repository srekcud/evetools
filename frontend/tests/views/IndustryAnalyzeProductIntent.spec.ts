import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'

const route = reactive<{ query: LocationQuery }>({ query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const ORACLE_TYPE_ID = 4302
const NAGA_TYPE_ID = 4306
const CERBERUS_TYPE_ID = 11993
const MUNINN_TYPE_ID = 12015
const ISHTAR_TYPE_ID = 12005

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

async function mountIndustryOnTab(tab: string) {
  route.query = { tab }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.spyOn(useProjectsStore(), 'fetchProjects').mockResolvedValue(undefined as never)
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
  useIndustryStore().navigationIntent = null
})

describe('Industry analyze product links', () => {
  it.each([
    ['batch', 'margins', 'ProfitMarginTab', ORACLE_TYPE_ID],
    ['projects', 'margins', 'ProfitMarginTab', NAGA_TYPE_ID],
    ['batch', 'buy-vs-build', 'BuyVsBuildTab', CERBERUS_TYPE_ID],
    ['pivot', 'buy-vs-build', 'BuyVsBuildTab', MUNINN_TYPE_ID],
    ['margins', 'pivot', 'PivotAdvisorTab', ISHTAR_TYPE_ID],
  ] as const)('opens the %s link target %s with the analyzed product preselected', async (sourceTab, target, targetTabComponentName, typeId) => {
    const wrapper = await mountIndustryOnTab(sourceTab)

    useIndustryStore().navigationIntent = { target, typeId }
    await flushPromises()

    const targetTab = wrapper.findComponent({ name: targetTabComponentName })
    expect(targetTab.exists()).toBe(true)
    expect(targetTab.props('initialTypeId')).toBe(typeId)
  })

  it('preselects the newly analyzed product when a second link targets the margins tab already open', async () => {
    const wrapper = await mountIndustryOnTab('batch')
    useIndustryStore().navigationIntent = { target: 'margins', typeId: ORACLE_TYPE_ID }
    await flushPromises()

    useIndustryStore().navigationIntent = { target: 'margins', typeId: NAGA_TYPE_ID }
    await flushPromises()

    expect(wrapper.findComponent(ProfitMarginTab).props('initialTypeId')).toBe(NAGA_TYPE_ID)
  })
})

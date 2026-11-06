import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'

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

  it('falls back to the dashboard for the old projects tab of the query', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'projects', project: 'p1' })

    expect(wrapper.findComponent(IndustryDashboard).exists()).toBe(true)
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('sends a projects navigation intent to the group industry screen and leaves the intent for it', async () => {
    await mountIndustryWithQuery({})
    const prefill = { typeId: SABRE_TYPE_ID, typeName: 'Sabre', runs: 7, me: 3 }

    useIndustryStore().navigationIntent = { target: 'projects', prefill }
    await flushPromises()

    expect(routerPush).toHaveBeenCalledTimes(1)
    expect(routerPush).toHaveBeenCalledWith('/group-industry')
    expect(useIndustryStore().navigationIntent).toEqual({ target: 'projects', prefill })
  })
})

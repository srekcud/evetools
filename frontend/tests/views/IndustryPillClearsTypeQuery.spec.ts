import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn((location: { query: LocationQuery }) => {
  route.query = location.query
})

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

async function clickPill(wrapper: Wrapper, label: string): Promise<void> {
  const pill = wrapper.findAll('button').find(candidate => candidate.text().trim() === label)
  if (!pill) throw new Error(`No pill named ${label}`)
  await pill.trigger('click')
  await flushPromises()
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
  vi.spyOn(useProjectsStore(), 'fetchProjects').mockResolvedValue(undefined as never)
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry type in the URL when a tab pill is clicked', () => {
  it.each([
    [en.industry.tabs.pivot, { tab: 'pivot', projectTab: 'shopping' }],
    [en.industry.tabs.dashboard, { projectTab: 'shopping' }],
  ])('removes the type from the query and keeps the other parameters when the %s pill is clicked', async (pillLabel, expectedQuery) => {
    const wrapper = await mountIndustryWithQuery({ tab: 'margins', typeId: String(RAVEN_TYPE_ID), projectTab: 'shopping' })

    await clickPill(wrapper, pillLabel)

    expect(route.query).toEqual(expectedQuery)
  })

  it('opens the target tab with no preselected type after a pill click from a type of the query', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'margins', typeId: String(RAVEN_TYPE_ID) })
    expect(wrapper.findComponent(ProfitMarginTab).props('initialTypeId')).toBe(RAVEN_TYPE_ID)

    await clickPill(wrapper, en.industry.tabs.buyVsBuild)

    expect(wrapper.findComponent(BuyVsBuildTab).exists()).toBe(true)
    expect(wrapper.findComponent(BuyVsBuildTab).props('initialTypeId')).toBeUndefined()
  })

  it('opens the target tab with no preselected type after a pill click from a type of a navigation intent', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'pivot' })
    useIndustryStore().navigationIntent = { target: 'buy-vs-build', typeId: RAVEN_NAVY_ISSUE_TYPE_ID }
    await flushPromises()
    expect(wrapper.findComponent(BuyVsBuildTab).props('initialTypeId')).toBe(RAVEN_NAVY_ISSUE_TYPE_ID)

    await clickPill(wrapper, en.industry.tabs.margins)

    expect(wrapper.findComponent(ProfitMarginTab).exists()).toBe(true)
    expect(wrapper.findComponent(ProfitMarginTab).props('initialTypeId')).toBeUndefined()
  })

  it('preselects again the type of the query when arriving on a tab with a type after a pill click', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'margins', typeId: String(RAVEN_TYPE_ID) })
    await clickPill(wrapper, en.industry.tabs.batch)

    await changeQuery({ tab: 'pivot', typeId: String(SABRE_TYPE_ID) })

    expect(wrapper.findComponent(PivotAdvisorTab).props('initialTypeId')).toBe(SABRE_TYPE_ID)
  })

  it('preselects again the same type of the query when arriving with it after a pill click', async () => {
    const wrapper = await mountIndustryWithQuery({ tab: 'margins', typeId: String(RAVEN_TYPE_ID) })
    await clickPill(wrapper, en.industry.tabs.batch)

    await changeQuery({ tab: 'margins', typeId: String(RAVEN_TYPE_ID) })

    expect(wrapper.findComponent(ProfitMarginTab).props('initialTypeId')).toBe(RAVEN_TYPE_ID)
  })
})

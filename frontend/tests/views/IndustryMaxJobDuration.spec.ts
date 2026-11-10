import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import IndustrySettings from '@/components/industry/IndustrySettings.vue'
import Industry from '@/views/Industry.vue'
import { useAdminStore } from '@/stores/admin'
import { useProjectsStore } from '@/stores/industry/projects'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {} })) as never,
  useRouter: vi.fn(() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const MAX_JOB_DURATION_LABEL = 'Max job duration'
const DEFAULT_MAX_JOB_DURATION_DAYS = 2
const ENTERED_MAX_JOB_DURATION_DAYS = 3.5
const MINIMUM_MAX_JOB_DURATION_DAYS = 0.5

type MaxJobDurationStore = { defaultMaxJobDurationDays: number }

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

async function mountWithConfigPanelOpen(): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { props: { page: 'workshop' }, global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  await wrapper.get(`button[aria-label="${en.industry.tabs.config}"]`).trigger('click')
  return wrapper
}

function maxJobDurationInput(wrapper: VueWrapper) {
  const label = wrapper.findAll('label').find(candidate => candidate.text() === MAX_JOB_DURATION_LABEL)
  expect(label, `label ${MAX_JOB_DURATION_LABEL}`).toBeDefined()
  return wrapper.get(`[id="${label!.attributes('for')}"]`)
}

async function enterMaxJobDuration(wrapper: VueWrapper, days: string) {
  const input = maxJobDurationInput(wrapper)
  await input.setValue(days)
  await input.trigger('change')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry config panel max job duration', () => {
  it('keeps the max job duration field, applies the entered days to the store and never goes under the minimum', async () => {
    const store = useProjectsStore() as unknown as MaxJobDurationStore
    const wrapper = await mountWithConfigPanelOpen()

    expect(store.defaultMaxJobDurationDays).toBe(DEFAULT_MAX_JOB_DURATION_DAYS)
    expect((maxJobDurationInput(wrapper).element as HTMLInputElement).value).toBe('2.0')

    await enterMaxJobDuration(wrapper, String(ENTERED_MAX_JOB_DURATION_DAYS))
    expect(store.defaultMaxJobDurationDays).toBe(ENTERED_MAX_JOB_DURATION_DAYS)

    await enterMaxJobDuration(wrapper, '0.1')
    expect(store.defaultMaxJobDurationDays).toBe(MINIMUM_MAX_JOB_DURATION_DAYS)
  })

  it('renders its configuration through the IndustrySettings component shared with the settings page', async () => {
    const wrapper = await mountWithConfigPanelOpen()

    expect(wrapper.findComponent(IndustrySettings).exists()).toBe(true)
    expect(wrapper.findAll('label').filter(label => label.text() === MAX_JOB_DURATION_LABEL)).toHaveLength(1)
  })
})

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import { useAdminStore } from '@/stores/admin'
import type { SearchResult } from '@/stores/industry'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {} })) as never,
}))

const SABRE: SearchResult = { typeId: 22456, typeName: 'Sabre', isT2: true }
const SABRE_RUNS = 7
const ENTERED_ME = 3
const INVENTED_BLUEPRINT_ME = 2
const INVENTED_BLUEPRINT_TE = 4
const DEFAULT_MAX_JOB_DURATION_DAYS = 2

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

type Wrapper = Awaited<ReturnType<typeof mountWithCreateModalOpen>>

function buttonNamed(wrapper: Wrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text().trim() === label)
  if (!button) throw new Error(`No button named ${label}`)
  return button
}

async function mountWithCreateModalOpen() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  await buttonNamed(wrapper, en.industry.tabs.projects).trigger('click')
  await buttonNamed(wrapper, en.industry.createProject.newProject).trigger('click')
  return wrapper
}

async function chooseProduct(wrapper: Wrapper, product: SearchResult) {
  wrapper.findComponent(ProductSearch).vm.$emit('select', product)
  await flushPromises()
}

function meInput(wrapper: Wrapper) {
  return wrapper.find('input[type="number"][max="10"]')
}

function teInput(wrapper: Wrapper) {
  return wrapper.find('input[type="number"][max="20"]')
}

function runsInput(wrapper: Wrapper) {
  return wrapper.find('input[type="number"][min="1"]')
}

let createProject: ReturnType<typeof vi.fn>

beforeEach(() => {
  setActivePinia(createPinia())
  const projectsStore = useProjectsStore()
  createProject = vi.spyOn(projectsStore, 'createProject').mockResolvedValue({ id: 'sabre-project' } as never) as never
  vi.spyOn(projectsStore, 'fetchProjects').mockResolvedValue(undefined as never)
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry create project form', () => {
  it('prefills the invented blueprint ME 2 and TE 4 when a T2 product is chosen', async () => {
    const wrapper = await mountWithCreateModalOpen()

    await chooseProduct(wrapper, SABRE)

    expect((meInput(wrapper).element as HTMLInputElement).value).toBe(String(INVENTED_BLUEPRINT_ME))
    expect((teInput(wrapper).element as HTMLInputElement).value).toBe(String(INVENTED_BLUEPRINT_TE))
  })

  it('keeps ME and TE editable for a T2 product', async () => {
    const wrapper = await mountWithCreateModalOpen()

    await chooseProduct(wrapper, SABRE)

    expect(meInput(wrapper).attributes('readonly')).toBeUndefined()
    expect(teInput(wrapper).attributes('readonly')).toBeUndefined()
  })

  it('sends the ME entered for a T2 product to the project creation', async () => {
    const wrapper = await mountWithCreateModalOpen()
    await chooseProduct(wrapper, SABRE)

    await runsInput(wrapper).setValue(SABRE_RUNS)
    await meInput(wrapper).setValue(ENTERED_ME)
    await buttonNamed(wrapper, en.industry.createProject.create).trigger('click')
    await flushPromises()

    expect(createProject).toHaveBeenCalledTimes(1)
    expect(createProject).toHaveBeenCalledWith(
      SABRE.typeId,
      SABRE_RUNS,
      ENTERED_ME,
      INVENTED_BLUEPRINT_TE,
      DEFAULT_MAX_JOB_DURATION_DAYS,
      null,
    )
  })
})

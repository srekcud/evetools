import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import Industry from '@/views/Industry.vue'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore, type SearchResult } from '@/stores/industry'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {} })) as never,
  useRouter: vi.fn(() => ({ replace: vi.fn() })) as never,
}))

const MAUNDER: SearchResult = { typeId: 12023, typeName: 'Maunder', isT2: false }
const MAUNDER_RUNS = 4
const DEFAULT_MAX_JOB_DURATION_DAYS = 2
const DEFAULT_MINIMUM_OUTSIDE_SAVING = 500000
const ENTERED_MINIMUM_OUTSIDE_SAVING = 250000

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

async function mountWithCreateModalOpen(locale: 'en' | 'fr' = 'en') {
  const messages = locale === 'en' ? en : fr
  const i18n = createI18n({ legacy: false, locale, messages: { [locale]: messages } })
  const wrapper = mount(Industry, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  await buttonNamed(wrapper, messages.industry.tabs.projects).trigger('click')
  await buttonNamed(wrapper, messages.industry.createProject.newProject).trigger('click')
  return wrapper
}

async function chooseProduct(wrapper: Wrapper, product: SearchResult) {
  wrapper.findComponent(ProductSearch).vm.$emit('select', product)
  await flushPromises()
}

function outsideFavoriteSystemCheckbox(wrapper: Wrapper) {
  return wrapper.find('input[type="checkbox"]')
}

function minimumOutsideSavingInput(wrapper: Wrapper) {
  return wrapper.find('input[type="number"][min="0"]:not([max])')
}

function labelOf(wrapper: Wrapper, fieldId: string): string {
  return wrapper.find(`label[for="${fieldId}"]`).text()
}

let createProject: ReturnType<typeof vi.fn>

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  useIndustryStore().navigationIntent = null
  const projectsStore = useProjectsStore()
  createProject = vi.spyOn(projectsStore, 'createProject').mockResolvedValue({ id: 'maunder-project' } as never) as never
  vi.spyOn(projectsStore, 'fetchProjects').mockResolvedValue(undefined as never)
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry create project form structure outside the favorite system', () => {
  it.each(['en', 'fr'] as const)('offers an unchecked choice whose label mentions the transport in %s', async locale => {
    const wrapper = await mountWithCreateModalOpen(locale)

    const checkbox = outsideFavoriteSystemCheckbox(wrapper)

    expect(checkbox.exists()).toBe(true)
    expect((checkbox.element as HTMLInputElement).checked).toBe(false)
    expect(labelOf(wrapper, checkbox.attributes('id') ?? '')).toMatch(/transport/i)
  })

  it('disables the minimum saving, set to 500000, until the choice is checked', async () => {
    const wrapper = await mountWithCreateModalOpen()

    const savingInput = minimumOutsideSavingInput(wrapper)

    expect(savingInput.attributes('disabled')).toBeDefined()
    expect((savingInput.element as HTMLInputElement).value).toBe(String(DEFAULT_MINIMUM_OUTSIDE_SAVING))

    await outsideFavoriteSystemCheckbox(wrapper).setValue(true)

    expect(minimumOutsideSavingInput(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('sends the checked choice and the entered minimum saving to the project creation', async () => {
    const wrapper = await mountWithCreateModalOpen()
    await chooseProduct(wrapper, MAUNDER)
    await wrapper.find('input[type="number"][min="1"]').setValue(MAUNDER_RUNS)

    await outsideFavoriteSystemCheckbox(wrapper).setValue(true)
    await minimumOutsideSavingInput(wrapper).setValue(ENTERED_MINIMUM_OUTSIDE_SAVING)
    await buttonNamed(wrapper, en.industry.createProject.create).trigger('click')
    await flushPromises()

    expect(createProject).toHaveBeenCalledTimes(1)
    expect(createProject).toHaveBeenCalledWith(
      MAUNDER.typeId,
      MAUNDER_RUNS,
      0,
      0,
      DEFAULT_MAX_JOB_DURATION_DAYS,
      null,
      [],
      true,
      ENTERED_MINIMUM_OUTSIDE_SAVING,
    )
  })
})

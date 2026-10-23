import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore, type SearchResult } from '@/stores/industry'

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
const RIFTER: SearchResult = { typeId: 587, typeName: 'Rifter', isT2: false }
const RIFTER_RUNS = 3
const WIDOW_TYPE_ID = 22436
const WIDOW_RUNS = 10
const WIDOW_ME = 2
const WIDOW_TE = 6
const RIFTER_ME = 1
const SUSTAINED_SHIELD_EMITTER_TYPE_ID = 11558
const TITANIUM_CARBIDE_TYPE_ID = 16671
const BOUGHT_WIDOW_COMPONENT_TYPE_IDS = [SUSTAINED_SHIELD_EMITTER_TYPE_ID, TITANIUM_CARBIDE_TYPE_ID]
const MAXIMUM_PROJECT_RUNS = 100000

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

async function mountWithBuyVsBuildIntent(prefill: { typeId: number; typeName: string; runs: number; me: number; te?: number; isT2: boolean; excludedTypeIds: number[] }) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  useIndustryStore().navigationIntent = { target: 'projects', prefill }
  await flushPromises()
  return wrapper
}

function widowIntent() {
  return {
    typeId: WIDOW_TYPE_ID,
    typeName: 'Widow',
    runs: WIDOW_RUNS,
    me: WIDOW_ME,
    isT2: true,
    excludedTypeIds: BOUGHT_WIDOW_COMPONENT_TYPE_IDS,
  }
}

function isMarkedT2(wrapper: Wrapper): boolean {
  return wrapper.findAll('label span.text-blue-400').some(marker => marker.text() === 'T2')
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

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  useIndustryStore().navigationIntent = null
  const projectsStore = useProjectsStore()
  createProject = vi.spyOn(projectsStore, 'createProject').mockResolvedValue({ id: 'sabre-project' } as never) as never
  vi.spyOn(projectsStore, 'fetchProjects').mockResolvedValue(undefined as never)
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry create project form', () => {
  it('caps the project runs at 100000', async () => {
    const wrapper = await mountWithCreateModalOpen()

    expect(runsInput(wrapper).attributes('max')).toBe(String(MAXIMUM_PROJECT_RUNS))
  })

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
      [],
    )
  })

  it('sends no excluded component when the project is created without buy vs build intent', async () => {
    const wrapper = await mountWithCreateModalOpen()
    await chooseProduct(wrapper, RIFTER)

    await runsInput(wrapper).setValue(RIFTER_RUNS)
    await buttonNamed(wrapper, en.industry.createProject.create).trigger('click')
    await flushPromises()

    expect(createProject).toHaveBeenCalledTimes(1)
    expect(createProject).toHaveBeenCalledWith(RIFTER.typeId, RIFTER_RUNS, 0, 0, DEFAULT_MAX_JOB_DURATION_DAYS, null, [])
  })
})

describe('Industry create project form opened from buy vs build', () => {
  it('prefills a T2 product with the transmitted ME and the invented blueprint TE 4 when no TE is transmitted', async () => {
    const wrapper = await mountWithBuyVsBuildIntent(widowIntent())

    expect((runsInput(wrapper).element as HTMLInputElement).value).toBe(String(WIDOW_RUNS))
    expect((meInput(wrapper).element as HTMLInputElement).value).toBe(String(WIDOW_ME))
    expect((teInput(wrapper).element as HTMLInputElement).value).toBe(String(INVENTED_BLUEPRINT_TE))
    expect(isMarkedT2(wrapper)).toBe(true)
  })

  it('prefills the transmitted TE', async () => {
    const wrapper = await mountWithBuyVsBuildIntent({ ...widowIntent(), te: WIDOW_TE })

    expect((teInput(wrapper).element as HTMLInputElement).value).toBe(String(WIDOW_TE))
  })

  it('prefills TE 0 for a product that is not T2 when no TE is transmitted', async () => {
    const wrapper = await mountWithBuyVsBuildIntent({
      typeId: RIFTER.typeId,
      typeName: RIFTER.typeName,
      runs: RIFTER_RUNS,
      me: RIFTER_ME,
      isT2: false,
      excludedTypeIds: [],
    })

    expect((meInput(wrapper).element as HTMLInputElement).value).toBe(String(RIFTER_ME))
    expect((teInput(wrapper).element as HTMLInputElement).value).toBe('0')
    expect(isMarkedT2(wrapper)).toBe(false)
  })

  it('sends the components bought in buy vs build as excluded components of the project', async () => {
    const wrapper = await mountWithBuyVsBuildIntent(widowIntent())

    await buttonNamed(wrapper, en.industry.createProject.create).trigger('click')
    await flushPromises()

    expect(createProject).toHaveBeenCalledTimes(1)
    expect(createProject).toHaveBeenCalledWith(
      WIDOW_TYPE_ID,
      WIDOW_RUNS,
      WIDOW_ME,
      INVENTED_BLUEPRINT_TE,
      DEFAULT_MAX_JOB_DURATION_DAYS,
      null,
      BOUGHT_WIDOW_COMPONENT_TYPE_IDS,
    )
  })

  it('sends no excluded component once another product is chosen', async () => {
    const wrapper = await mountWithBuyVsBuildIntent(widowIntent())

    await chooseProduct(wrapper, SABRE)
    await buttonNamed(wrapper, en.industry.createProject.create).trigger('click')
    await flushPromises()

    expect(createProject).toHaveBeenCalledTimes(1)
    expect(createProject).toHaveBeenCalledWith(
      SABRE.typeId,
      WIDOW_RUNS,
      INVENTED_BLUEPRINT_ME,
      INVENTED_BLUEPRINT_TE,
      DEFAULT_MAX_JOB_DURATION_DAYS,
      null,
      [],
    )
  })

  it('sends no excluded component when a second intent transmits none', async () => {
    const wrapper = await mountWithBuyVsBuildIntent(widowIntent())
    useIndustryStore().navigationIntent = {
      target: 'projects',
      prefill: { typeId: SABRE.typeId, typeName: SABRE.typeName, runs: SABRE_RUNS, me: ENTERED_ME },
    }
    await flushPromises()

    await buttonNamed(wrapper, en.industry.createProject.create).trigger('click')
    await flushPromises()

    expect(createProject).toHaveBeenCalledTimes(1)
    expect(createProject).toHaveBeenCalledWith(SABRE.typeId, SABRE_RUNS, ENTERED_ME, 0, DEFAULT_MAX_JOB_DURATION_DAYS, null, [])
  })

  it('sends no excluded component once the form is closed and opened again', async () => {
    const wrapper = await mountWithBuyVsBuildIntent(widowIntent())

    await buttonNamed(wrapper, en.common.actions.cancel).trigger('click')
    await buttonNamed(wrapper, en.industry.createProject.newProject).trigger('click')
    await chooseProduct(wrapper, SABRE)
    await buttonNamed(wrapper, en.industry.createProject.create).trigger('click')
    await flushPromises()

    expect(createProject).toHaveBeenCalledTimes(1)
    expect(createProject).toHaveBeenCalledWith(
      SABRE.typeId,
      1,
      INVENTED_BLUEPRINT_ME,
      INVENTED_BLUEPRINT_TE,
      DEFAULT_MAX_JOB_DURATION_DAYS,
      null,
      [],
    )
  })
})

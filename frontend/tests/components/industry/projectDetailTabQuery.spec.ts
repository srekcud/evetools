import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import ProjectDetail from '@/components/industry/ProjectDetail.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject } from '@/stores/industry/types'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace })) as never,
}))

const PROJECT_ID = 'project-221'
const VEXOR_TYPE_ID = 626

const tabLoadCalls: string[] = []

function recordTabLoad(tabName: string, loadName: string) {
  return () => {
    tabLoadCalls.push(`${tabName}.${loadName}`)
  }
}

function projectTabStub(name: string) {
  return defineComponent({
    name,
    methods: {
      activate: recordTabLoad(name, 'activate'),
      loadSuggestions: recordTabLoad(name, 'loadSuggestions'),
      loadPersistedStock: recordTabLoad(name, 'loadPersistedStock'),
    },
    render: () => h('div'),
  })
}

function tabLoadCounts() {
  return tabLoadCalls.reduce<Record<string, number>>((counts, call) => {
    counts[call] = call in counts ? counts[call] + 1 : 1
    return counts
  }, {})
}

const stubbedChildren = {
  teleport: true,
  StepsTab: projectTabStub('StepsTab'),
  ShoppingTab: projectTabStub('ShoppingTab'),
  BpcKitTab: projectTabStub('BpcKitTab'),
  CostEstimationTab: projectTabStub('CostEstimationTab'),
}

function vexorProject(): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Vexor',
    productTypeId: VEXOR_TYPE_ID,
    displayName: null,
    runs: 3,
    meLevel: 8,
    teLevel: 16,
    maxJobDurationDays: 2,
    status: 'active',
    personalUse: false,
    isT2: false,
    bpoCost: null,
    materialCost: null,
    transportCost: null,
    jobsCost: null,
    taxAmount: null,
    sellPrice: null,
    totalCost: null,
    estimatedJobCost: null,
    estimatedSellPrice: null,
    estimatedSellPriceSource: null,
    estimatedTaxAmount: null,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-10-24T09:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps: [],
    tree: null,
  }
}

async function mountProjectDetailWithQuery(query: LocationQuery) {
  route.query = query
  const projectsStore = useProjectsStore()
  projectsStore.currentProject = vexorProject()
  vi.spyOn(projectsStore, 'fetchProject').mockResolvedValue(undefined as never)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ProjectDetail, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [i18n], stubs: stubbedChildren },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountProjectDetailWithQuery>>

function buttonNamed(wrapper: Wrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text().trim() === label)
  if (!button) throw new Error(`No button named ${label}`)
  return button
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  routerReplace.mockClear()
  tabLoadCalls.length = 0
})

describe('ProjectDetail tab in the URL', () => {
  it('shows the costs tab when mounted with the costs projectTab in the query', async () => {
    const wrapper = await mountProjectDetailWithQuery({ tab: 'projects', projectTab: 'costs' })

    expect(buttonNamed(wrapper, en.industry.costEstimation.tabLabel).classes()).toContain('bg-cyan-600')
    expect(buttonNamed(wrapper, en.industry.project.steps).classes()).not.toContain('bg-cyan-600')
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('writes the shopping projectTab and keeps the projects tab when the procurement pill is clicked', async () => {
    const wrapper = await mountProjectDetailWithQuery({ tab: 'projects' })

    await buttonNamed(wrapper, en.industry.projectDetail.procurement).trigger('click')
    await flushPromises()

    expect(routerReplace).toHaveBeenCalledTimes(1)
    expect(routerReplace).toHaveBeenCalledWith({ query: { tab: 'projects', projectTab: 'shopping' } })
  })

  it('loads the shopping tab once when mounted with the shopping projectTab in the query', async () => {
    await mountProjectDetailWithQuery({ tab: 'projects', projectTab: 'shopping' })

    expect(tabLoadCounts()).toEqual({
      'ShoppingTab.loadPersistedStock': 1,
      'ShoppingTab.activate': 1,
      'ShoppingTab.loadSuggestions': 1,
    })
    expect(tabLoadCalls.filter(call => call !== 'ShoppingTab.loadPersistedStock')).toEqual([
      'ShoppingTab.activate',
      'ShoppingTab.loadSuggestions',
    ])
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('renders the invention tab without loading shopping or costs when mounted with the invention projectTab in the query', async () => {
    const wrapper = await mountProjectDetailWithQuery({ tab: 'projects', projectTab: 'invention' })

    expect(wrapper.findComponent({ name: 'BpcKitTab' }).exists()).toBe(true)
    expect(tabLoadCounts()).toEqual({ 'ShoppingTab.loadPersistedStock': 1 })
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('activates the cost estimation tab once when mounted with the costs projectTab in the query', async () => {
    await mountProjectDetailWithQuery({ tab: 'projects', projectTab: 'costs' })

    expect(tabLoadCounts()).toEqual({
      'ShoppingTab.loadPersistedStock': 1,
      'CostEstimationTab.activate': 1,
    })
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('loads only the persisted stock when mounted without projectTab in the query', async () => {
    const wrapper = await mountProjectDetailWithQuery({ tab: 'projects' })

    expect(buttonNamed(wrapper, en.industry.project.steps).classes()).toContain('bg-cyan-600')
    expect(tabLoadCounts()).toEqual({ 'ShoppingTab.loadPersistedStock': 1 })
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('loads the shopping tab once when the back button moves projectTab from invention to shopping', async () => {
    const wrapper = await mountProjectDetailWithQuery({ tab: 'projects', projectTab: 'invention' })
    tabLoadCalls.length = 0

    route.query = { tab: 'projects', projectTab: 'shopping' }
    await flushPromises()

    expect(buttonNamed(wrapper, en.industry.projectDetail.procurement).classes()).toContain('bg-cyan-600')
    expect(tabLoadCalls).toEqual(['ShoppingTab.activate', 'ShoppingTab.loadSuggestions'])
    expect(routerReplace).not.toHaveBeenCalled()
  })
})

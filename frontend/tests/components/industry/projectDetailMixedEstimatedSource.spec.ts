import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProjectDetail from '@/components/industry/ProjectDetail.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject } from '@/stores/industry/types'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {} })) as never,
  useRouter: vi.fn(() => ({ replace: vi.fn() })) as never,
}))

const PROJECT_ID = 'project-179'
const MIXED_SOURCES_LABEL = 'Mixed'

function projectTabStub(name: string) {
  return defineComponent({
    name,
    methods: {
      activate: () => undefined,
      loadSuggestions: () => undefined,
      loadPersistedStock: () => undefined,
    },
    render: () => h('div'),
  })
}

const stubbedChildren = {
  teleport: true,
  StepsTab: projectTabStub('StepsTab'),
  ShoppingTab: projectTabStub('ShoppingTab'),
  BpcKitTab: projectTabStub('BpcKitTab'),
  CostEstimationTab: projectTabStub('CostEstimationTab'),
}

function projectEstimatedAt(source: string): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Rifter',
    productTypeId: 587,
    displayName: null,
    runs: 10,
    meLevel: 10,
    teLevel: 0,
    maxJobDurationDays: 2,
    acceptsOutsideFavoriteSystem: false,
    minimumOutsideSaving: 500000,
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
    estimatedSellPrice: 1_100_000,
    estimatedSellPriceSource: source as IndustryProject['estimatedSellPriceSource'],
    estimatedTaxAmount: 33_000,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-11-04T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps: [],
    tree: null,
  }
}

async function mountProjectDetailEstimatedAt(source: string) {
  const projectsStore = useProjectsStore()
  projectsStore.currentProject = projectEstimatedAt(source)
  vi.spyOn(projectsStore, 'fetchProject').mockResolvedValue(undefined as never)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ProjectDetail, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [i18n], stubs: stubbedChildren },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountProjectDetailEstimatedAt>>

function textsOfParagraphs(wrapper: Wrapper) {
  return wrapper.findAll('p').map(paragraph => paragraph.text().trim())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProjectDetail estimated sell price source', () => {
  it('shows the mixed sources label when the products of the project are priced on different markets', async () => {
    const wrapper = await mountProjectDetailEstimatedAt('mixed')

    const texts = textsOfParagraphs(wrapper)
    expect(texts).toContain(MIXED_SOURCES_LABEL)
    expect(texts).not.toContain(en.industry.costs.structure)
    expect(texts).not.toContain('Jita')
  })

  it('shows Jita when every product of the project is priced at Jita', async () => {
    const wrapper = await mountProjectDetailEstimatedAt('jita')

    const texts = textsOfParagraphs(wrapper)
    expect(texts).toContain('Jita')
    expect(texts).not.toContain(MIXED_SOURCES_LABEL)
  })
})

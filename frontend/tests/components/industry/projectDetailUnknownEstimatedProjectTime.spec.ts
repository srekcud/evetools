import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProjectDetail from '@/components/industry/ProjectDetail.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {} })) as never,
  useRouter: vi.fn(() => ({ replace: vi.fn() })) as never,
}))

const PROJECT_ID = 'project-282'
const UNKNOWN_VALUE = `[title="${en.common.unknownValue}"]`

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

function stepOf(overrides: Partial<IndustryProjectStep>): IndustryProjectStep {
  return {
    id: 'step-282-root',
    blueprintTypeId: 28401,
    productTypeId: 28402,
    productTypeName: 'Root Cruiser',
    quantity: 2,
    runs: 2,
    depth: 0,
    activityType: 'manufacturing',
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 10,
    teLevel: 0,
    jobMatchMode: 'auto',
    structureConfigId: null,
    structureConfigName: null,
    structureMaterialBonus: null,
    structureTimeBonus: null,
    favoriteSystemWithoutSuitableStructure: false,
    deletable: true,
    timePerRun: 9000,
    recommendedCharacterName: null,
    splitGroupId: null,
    splitIndex: 0,
    totalGroupRuns: null,
    facilityInfoType: null,
    actualFacilityName: null,
    bestStructureName: null,
    bestMaterialBonus: null,
    jobMatches: [],
    jobsCost: null,
    purchases: [],
    purchasesCost: null,
    purchasedQuantity: 0,
    inStock: false,
    esiJobId: null,
    esiJobCost: null,
    esiJobStatus: null,
    esiJobEndDate: null,
    esiJobRuns: null,
    esiJobCharacterName: null,
    esiJobsCount: null,
    esiJobsTotalRuns: null,
    esiJobsActiveRuns: null,
    esiJobsDeliveredRuns: null,
    manualJobData: false,
    recommendedStructureName: null,
    structureBonus: null,
    similarJobs: [],
    ...overrides,
  }
}

function componentStep(overrides: Partial<IndustryProjectStep>): IndustryProjectStep {
  return stepOf({
    id: 'step-282-component',
    blueprintTypeId: 28411,
    productTypeId: 28412,
    productTypeName: 'Cruiser Component',
    quantity: 5,
    runs: 5,
    depth: 1,
    timePerRun: null,
    ...overrides,
  })
}

const SECOND_ROOT_STEP = stepOf({
  id: 'step-282-second-root',
  blueprintTypeId: 28421,
  productTypeId: 28422,
  productTypeName: 'Root Frigate',
  quantity: 3,
  runs: 3,
  timePerRun: 1200,
})

function projectOf(steps: IndustryProjectStep[]): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Root Cruiser',
    productTypeId: 28402,
    displayName: null,
    runs: 2,
    meLevel: 10,
    teLevel: 0,
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
    createdAt: '2026-10-30T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps,
    tree: null,
  }
}

async function mountProjectDetailOf(steps: IndustryProjectStep[]) {
  const projectsStore = useProjectsStore()
  projectsStore.currentProject = projectOf(steps)
  vi.spyOn(projectsStore, 'fetchProject').mockResolvedValue(undefined as never)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ProjectDetail, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [i18n], stubs: stubbedChildren },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountProjectDetailOf>>

function unknownValuesOf(wrapper: Wrapper) {
  return wrapper.findAll(UNKNOWN_VALUE).map(unknownValue => unknownValue.text())
}

function textsOfSpans(wrapper: Wrapper) {
  return wrapper.findAll('span').map(span => span.text().trim())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProjectDetail estimated project time', () => {
  it('shows an unknown estimated project time for a single product project with a step to build of unknown time per run', async () => {
    const wrapper = await mountProjectDetailOf([stepOf({}), componentStep({})])

    expect(unknownValuesOf(wrapper)).toEqual(['—'])
    expect(textsOfSpans(wrapper)).not.toContain('5h 0m')
  })

  it('shows an unknown estimated project time for a multi product project with a step to build of unknown time per run', async () => {
    const wrapper = await mountProjectDetailOf([stepOf({}), SECOND_ROOT_STEP, componentStep({})])

    expect(unknownValuesOf(wrapper)).toEqual(['—'])
    expect(textsOfSpans(wrapper)).not.toContain('5h 0m')
  })

  it('shows the known estimated project time when the step of unknown time per run is in stock', async () => {
    const wrapper = await mountProjectDetailOf([stepOf({}), componentStep({ inStock: true })])

    expect(unknownValuesOf(wrapper)).toEqual([])
    expect(textsOfSpans(wrapper)).toContain('5h 0m')
  })

  it('hides the estimated project time when every step is purchased', async () => {
    const wrapper = await mountProjectDetailOf([stepOf({ purchased: true }), componentStep({ purchased: true })])

    expect(unknownValuesOf(wrapper)).toEqual([])
    expect(textsOfSpans(wrapper)).not.toContain('5h 0m')
  })
})

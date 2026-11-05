import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import { useStepsStore } from '@/stores/industry/steps'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-182'

function stepOf(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'inStockQuantity' | 'purchasedQuantity' | 'inStock'>): IndustryProjectStep {
  return {
    activityType: 'manufacturing',
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    meLevel: 10,
    teLevel: 20,
    jobMatchMode: 'auto',
    structureConfigId: null,
    structureConfigName: null,
    structureMaterialBonus: null,
    structureTimeBonus: null,
    favoriteSystemWithoutSuitableStructure: false,
    deletable: true,
    timePerRun: null,
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

function kestrelStepCoveredByPurchasesButNotInStockForTheServer(): IndustryProjectStep {
  return stepOf({
    id: 'step-182-kestrel',
    blueprintTypeId: 947,
    productTypeId: 602,
    productTypeName: 'Kestrel',
    quantity: 6,
    runs: 6,
    depth: 1,
    inStockQuantity: 0,
    purchasedQuantity: 6,
    inStock: false,
  })
}

function nitrogenFuelBlockStepWithoutStockButInStockForTheServer(): IndustryProjectStep {
  return stepOf({
    id: 'step-182-nitrogen-fuel-block',
    blueprintTypeId: 4314,
    productTypeId: 4051,
    productTypeName: 'Nitrogen Fuel Block',
    quantity: 100,
    runs: 3,
    depth: 2,
    inStockQuantity: 0,
    purchasedQuantity: 0,
    inStock: true,
  })
}

function ferniteAlloyStep(inStockQuantity: number, inStock: boolean): IndustryProjectStep {
  return stepOf({
    id: 'step-182-fernite-alloy',
    blueprintTypeId: 46168,
    productTypeId: 16656,
    productTypeName: 'Fernite Alloy',
    activityType: 'reaction',
    quantity: 100,
    runs: 1,
    depth: 1,
    meLevel: 0,
    teLevel: 0,
    inStockQuantity,
    purchasedQuantity: 50,
    inStock,
  })
}

function projectWith(steps: IndustryProjectStep[]): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Fernite Carbide',
    productTypeId: 16673,
    displayName: null,
    runs: 4,
    meLevel: 0,
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
    jobsCost: 0,
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
    createdAt: '2026-10-31T09:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps,
    tree: null,
    failedProducts: [],
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('in-stock flag of a step read from the server', () => {
  it('keeps a step covered by its purchases out of stock when the server says so', async () => {
    vi.mocked(apiRequest).mockResolvedValue(projectWith([kestrelStepCoveredByPurchasesButNotInStockForTheServer()]))
    const store = useProjectsStore()

    await store.fetchProject(PROJECT_ID)

    expect(store.currentProject?.steps?.map(step => step.inStock)).toEqual([false])
  })

  it('keeps a step without stock in stock when the server says so', async () => {
    vi.mocked(apiRequest).mockResolvedValue(projectWith([nitrogenFuelBlockStepWithoutStockButInStockForTheServer()]))
    const store = useProjectsStore()

    await store.fetchProject(PROJECT_ID)

    expect(store.currentProject?.steps?.map(step => step.inStock)).toEqual([true])
  })

  it('takes the in-stock flag of the patched step from the server answer', async () => {
    const projectsStore = useProjectsStore()
    projectsStore.currentProject = projectWith([ferniteAlloyStep(0, false)])
    vi.mocked(apiRequest).mockResolvedValue([ferniteAlloyStep(100, true)])

    await useStepsStore().toggleStepInStock(PROJECT_ID, 'step-182-fernite-alloy', 100)

    expect(projectsStore.currentProject.steps?.map(step => [step.inStockQuantity, step.inStock])).toEqual([[100, true]])
    expect(vi.mocked(apiRequest).mock.calls).toEqual([
      [`/industry/projects/${PROJECT_ID}/steps/step-182-fernite-alloy`, { method: 'PATCH', body: '{"inStockQuantity":100}' }],
    ])
  })
})

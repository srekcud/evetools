import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import { useStepsStore } from '@/stores/industry/steps'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-202'
const OTHER_PROJECT_ID = 'project-131'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const FERNITE_CARBIDE_STEP_ID = 'step-202-fernite-carbide'
const KEPT_ALLOY_JOB_ID = 'job-202-fernite-alloy-0'
const DELETED_ALLOY_JOB_ID = 'job-202-fernite-alloy-1'
const HYDROGEN_FUEL_BLOCK_STEP_ID = 'step-202-hydrogen-fuel-block'
const FERNITE_ALLOY_SPLIT_GROUP_ID = 'fernite-alloy'
const LINKED_TO_AN_ESI_JOB = 'Cannot delete a step linked to an ESI job'

function step(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType'>): IndustryProjectStep {
  return {
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 0,
    teLevel: 0,
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

function ferniteCarbideStep(): IndustryProjectStep {
  return step({
    id: FERNITE_CARBIDE_STEP_ID,
    blueprintTypeId: 46166,
    productTypeId: 16673,
    productTypeName: 'Fernite Carbide',
    quantity: 100000,
    runs: 10,
    depth: 0,
    activityType: 'reaction',
    deletable: false,
  })
}

function ferniteAlloyJob(id: string, splitIndex: number, runs: number): IndustryProjectStep {
  return step({
    id,
    blueprintTypeId: 46168,
    productTypeId: 16656,
    productTypeName: 'Fernite Alloy',
    quantity: runs * 200,
    runs,
    depth: 1,
    activityType: 'reaction',
    splitGroupId: FERNITE_ALLOY_SPLIT_GROUP_ID,
    splitIndex,
    totalGroupRuns: 5,
  })
}

function dissolvedAlloyJob(): IndustryProjectStep {
  return step({
    id: KEPT_ALLOY_JOB_ID,
    blueprintTypeId: 46168,
    productTypeId: 16656,
    productTypeName: 'Fernite Alloy',
    quantity: 1000,
    runs: 5,
    depth: 1,
    activityType: 'reaction',
  })
}

function hydrogenFuelBlockStep(runs: number, quantity: number): IndustryProjectStep {
  return step({
    id: HYDROGEN_FUEL_BLOCK_STEP_ID,
    blueprintTypeId: 4316,
    productTypeId: 4246,
    productTypeName: 'Hydrogen Fuel Block',
    quantity,
    runs,
    depth: 2,
    activityType: 'manufacturing',
    meLevel: 10,
  })
}

function stepsBeforeTheDeletion(): IndustryProjectStep[] {
  return [
    ferniteCarbideStep(),
    ferniteAlloyJob(KEPT_ALLOY_JOB_ID, 0, 3),
    ferniteAlloyJob(DELETED_ALLOY_JOB_ID, 1, 2),
    hydrogenFuelBlockStep(2, 75),
  ]
}

function stepsAfterTheDeletion(): IndustryProjectStep[] {
  return [
    ferniteCarbideStep(),
    dissolvedAlloyJob(),
    hydrogenFuelBlockStep(2, 75),
  ]
}

function project(id: string, steps: IndustryProjectStep[]): IndustryProject {
  return {
    id,
    productTypeName: 'Fernite Carbide',
    productTypeId: 16673,
    displayName: null,
    runs: 10,
    meLevel: 0,
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
    createdAt: '2026-10-31T09:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps,
    tree: null,
  }
}

function serverAnswers(reloadedSteps: IndustryProjectStep[]) {
  vi.mocked(apiRequest).mockImplementation(async (_path: string, options?: RequestInit) =>
    options?.method === 'DELETE' ? undefined : project(PROJECT_ID, reloadedSteps))
}

function requestsSent(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([path, options]) => `${options?.method ?? 'GET'} ${path}`)
}

function stepsInTheStore(): Array<[string, number, number, string | null, number | null]> {
  return (useProjectsStore().currentProject?.steps ?? []).map(s => [s.id, s.runs, s.quantity, s.splitGroupId, s.totalGroupRuns])
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('steps store after a step is deleted', () => {
  it('reloads the current project so that the store holds the dissolved job and the recalculated steps', async () => {
    useProjectsStore().currentProject = project(PROJECT_ID, stepsBeforeTheDeletion())
    serverAnswers(stepsAfterTheDeletion())

    await useStepsStore().deleteStep(PROJECT_ID, DELETED_ALLOY_JOB_ID)

    expect(requestsSent()).toEqual([`DELETE ${PROJECT_PATH}/steps/${DELETED_ALLOY_JOB_ID}`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore()).toEqual([
      [FERNITE_CARBIDE_STEP_ID, 10, 100000, null, null],
      [KEPT_ALLOY_JOB_ID, 5, 1000, null, null],
      [HYDROGEN_FUEL_BLOCK_STEP_ID, 2, 75, null, null],
    ])
  })

  it('does not reload a project that is not the current one', async () => {
    useProjectsStore().currentProject = project(OTHER_PROJECT_ID, [hydrogenFuelBlockStep(7, 270)])
    serverAnswers(stepsAfterTheDeletion())

    await useStepsStore().deleteStep(PROJECT_ID, DELETED_ALLOY_JOB_ID)

    expect(requestsSent()).toEqual([`DELETE ${PROJECT_PATH}/steps/${DELETED_ALLOY_JOB_ID}`])
    expect([useProjectsStore().currentProject?.id, ...stepsInTheStore()]).toEqual([
      OTHER_PROJECT_ID,
      [HYDROGEN_FUEL_BLOCK_STEP_ID, 7, 270, null, null],
    ])
  })

  it('keeps the steps and reports the refusal of the server without reloading the project', async () => {
    useProjectsStore().currentProject = project(PROJECT_ID, stepsBeforeTheDeletion())
    vi.mocked(apiRequest).mockRejectedValue(new ApiError(LINKED_TO_AN_ESI_JOB, 400))

    await expect(useStepsStore().deleteStep(PROJECT_ID, DELETED_ALLOY_JOB_ID)).rejects.toThrow(LINKED_TO_AN_ESI_JOB)

    expect(requestsSent()).toEqual([`DELETE ${PROJECT_PATH}/steps/${DELETED_ALLOY_JOB_ID}`])
    expect(useProjectsStore().error).toBe(LINKED_TO_AN_ESI_JOB)
    expect(stepsInTheStore()).toEqual([
      [FERNITE_CARBIDE_STEP_ID, 10, 100000, null, null],
      [KEPT_ALLOY_JOB_ID, 3, 600, FERNITE_ALLOY_SPLIT_GROUP_ID, 5],
      [DELETED_ALLOY_JOB_ID, 2, 400, FERNITE_ALLOY_SPLIT_GROUP_ID, 5],
      [HYDROGEN_FUEL_BLOCK_STEP_ID, 2, 75, null, null],
    ])
  })
})

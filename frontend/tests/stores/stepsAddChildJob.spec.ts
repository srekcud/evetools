import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import { useStepsStore } from '@/stores/industry/steps'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-191'
const OTHER_PROJECT_ID = 'project-190'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const FERNITE_CARBIDE_STEP_ID = 'step-fernite-carbide'
const FERNITE_ALLOY_JOB_IDS = ['job-fernite-alloy-0', 'job-fernite-alloy-1']
const ADDED_JOB_ID = 'job-fernite-alloy-added'
const HYDROGEN_FUEL_BLOCK_STEP_ID = 'step-hydrogen-fuel-block'
const FERNITE_ALLOY_SPLIT_GROUP_ID = 'fernite-alloy'
const ADDED_RUNS = 4

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
  })
}

function ferniteAlloyJob(id: string, splitIndex: number, runs: number, totalGroupRuns: number, runsFixed: boolean): IndustryProjectStep {
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
    totalGroupRuns,
    runsFixed,
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

function stepsBeforeTheAddedJob(): IndustryProjectStep[] {
  return [
    ferniteCarbideStep(),
    ferniteAlloyJob(FERNITE_ALLOY_JOB_IDS[0], 0, 3, 5, false),
    ferniteAlloyJob(FERNITE_ALLOY_JOB_IDS[1], 1, 2, 5, false),
    hydrogenFuelBlockStep(2, 75),
  ]
}

function addedJob(): IndustryProjectStep {
  return ferniteAlloyJob(ADDED_JOB_ID, 2, ADDED_RUNS, 9, true)
}

function stepsAfterTheAddedJob(): IndustryProjectStep[] {
  return [
    ferniteCarbideStep(),
    ferniteAlloyJob(FERNITE_ALLOY_JOB_IDS[0], 0, 3, 9, true),
    ferniteAlloyJob(FERNITE_ALLOY_JOB_IDS[1], 1, 2, 9, true),
    hydrogenFuelBlockStep(3, 95),
    addedJob(),
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
    createdAt: '2026-10-31T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps,
    tree: null,
  }
}

function serverAnswers(postResponse: unknown, reloadedSteps: IndustryProjectStep[]) {
  vi.mocked(apiRequest).mockImplementation(async (_path: string, options?: RequestInit) =>
    options?.method === 'POST' ? postResponse : project(PROJECT_ID, reloadedSteps))
}

function requestsSent(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([path, options]) => `${options?.method ?? 'GET'} ${path}`)
}

function stepsInTheStore(): Array<[string, number, number, boolean, number | null]> {
  return (useProjectsStore().currentProject?.steps ?? []).map(s => [s.id, s.runs, s.quantity, s.runsFixed, s.totalGroupRuns])
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('steps store after a job is added', () => {
  it('reloads the project after a job added through a step and returns the new step', async () => {
    useProjectsStore().currentProject = project(PROJECT_ID, stepsBeforeTheAddedJob())
    serverAnswers({ newStep: addedJob(), updatedStep: ferniteAlloyJob(FERNITE_ALLOY_JOB_IDS[0], 0, 3, 9, true) }, stepsAfterTheAddedJob())

    const returnedStep = await useStepsStore().addChildJob(PROJECT_ID, null, FERNITE_ALLOY_JOB_IDS[0], ADDED_RUNS)

    expect(requestsSent()).toEqual([`POST ${PROJECT_PATH}/steps`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore()).toEqual([
      [FERNITE_CARBIDE_STEP_ID, 10, 100000, false, null],
      [FERNITE_ALLOY_JOB_IDS[0], 3, 600, true, 9],
      [FERNITE_ALLOY_JOB_IDS[1], 2, 400, true, 9],
      [HYDROGEN_FUEL_BLOCK_STEP_ID, 3, 95, false, null],
      [ADDED_JOB_ID, 4, 800, true, 9],
    ])
    expect([returnedStep?.id, returnedStep?.runs, returnedStep?.splitIndex]).toEqual([ADDED_JOB_ID, 4, 2])
  })

  it('reloads the project after a job added to a split group and returns the added job', async () => {
    useProjectsStore().currentProject = project(PROJECT_ID, stepsBeforeTheAddedJob())
    serverAnswers(addedJob(), stepsAfterTheAddedJob())

    const returnedStep = await useStepsStore().addChildJob(PROJECT_ID, FERNITE_ALLOY_SPLIT_GROUP_ID, null, ADDED_RUNS)

    expect(requestsSent()).toEqual([`POST ${PROJECT_PATH}/steps`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore().map(([id, runs, quantity]) => [id, runs, quantity])).toEqual([
      [FERNITE_CARBIDE_STEP_ID, 10, 100000],
      [FERNITE_ALLOY_JOB_IDS[0], 3, 600],
      [FERNITE_ALLOY_JOB_IDS[1], 2, 400],
      [HYDROGEN_FUEL_BLOCK_STEP_ID, 3, 95],
      [ADDED_JOB_ID, 4, 800],
    ])
    expect([returnedStep?.id, returnedStep?.runs, returnedStep?.splitIndex]).toEqual([ADDED_JOB_ID, 4, 2])
  })

  it('does not reload a project that is not the current one', async () => {
    useProjectsStore().currentProject = project(OTHER_PROJECT_ID, [hydrogenFuelBlockStep(7, 270)])
    serverAnswers(addedJob(), stepsAfterTheAddedJob())

    await useStepsStore().addChildJob(PROJECT_ID, FERNITE_ALLOY_SPLIT_GROUP_ID, null, ADDED_RUNS)

    expect(requestsSent()).toEqual([`POST ${PROJECT_PATH}/steps`])
    expect([useProjectsStore().currentProject?.id, ...stepsInTheStore()]).toEqual([
      OTHER_PROJECT_ID,
      [HYDROGEN_FUEL_BLOCK_STEP_ID, 7, 270, false, null],
    ])
  })

  it('keeps the steps and reports the error of a refused added job without reloading the project', async () => {
    useProjectsStore().currentProject = project(PROJECT_ID, stepsBeforeTheAddedJob())
    vi.mocked(apiRequest).mockRejectedValue(new Error('Product of blueprint 999999999 missing from the SDE'))

    await expect(useStepsStore().addChildJob(PROJECT_ID, null, FERNITE_ALLOY_JOB_IDS[0], ADDED_RUNS)).rejects.toThrow('Product of blueprint 999999999 missing from the SDE')

    expect(requestsSent()).toEqual([`POST ${PROJECT_PATH}/steps`])
    expect(useProjectsStore().error).toBe('Product of blueprint 999999999 missing from the SDE')
    expect(stepsInTheStore().map(([id, runs]) => [id, runs])).toEqual([
      [FERNITE_CARBIDE_STEP_ID, 10],
      [FERNITE_ALLOY_JOB_IDS[0], 3],
      [FERNITE_ALLOY_JOB_IDS[1], 2],
      [HYDROGEN_FUEL_BLOCK_STEP_ID, 2],
    ])
  })
})

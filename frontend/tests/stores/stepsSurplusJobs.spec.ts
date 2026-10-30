import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import { useStepsStore } from '@/stores/industry/steps'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-131'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const CARRIER_STEP_ID = 'step-thanatos'
const FUEL_BLOCK_JOB_IDS = ['job-oxygen-0', 'job-oxygen-1', 'job-oxygen-2', 'job-oxygen-3']
const FUEL_BLOCK_STEP_ID = 'step-oxygen'
const FUEL_BLOCK_SPLIT_GROUP_ID = 'oxygen-fuel-block'
const FUEL_BLOCKS_PER_RUN = 40
const CARRIER_RUNS_BEFORE = 8
const CARRIER_RUNS_AFTER = 1

function step(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType'>): IndustryProjectStep {
  return {
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 10,
    teLevel: 20,
    jobMatchMode: 'auto',
    structureConfigId: null,
    structureConfigName: null,
    structureMaterialBonus: null,
    structureTimeBonus: null,
    favoriteSystemWithoutSuitableStructure: false,
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

function carrierStep(runs: number): IndustryProjectStep {
  return step({
    id: CARRIER_STEP_ID,
    blueprintTypeId: 23912,
    productTypeId: 23911,
    productTypeName: 'Thanatos',
    quantity: runs,
    runs,
    depth: 0,
    activityType: 'manufacturing',
  })
}

function fuelBlockJob(splitIndex: number, runs: number, quantity: number, totalGroupRuns: number): IndustryProjectStep {
  return step({
    id: FUEL_BLOCK_JOB_IDS[splitIndex],
    blueprintTypeId: 4313,
    productTypeId: 4312,
    productTypeName: 'Oxygen Fuel Block',
    quantity,
    runs,
    depth: 1,
    activityType: 'manufacturing',
    splitGroupId: FUEL_BLOCK_SPLIT_GROUP_ID,
    splitIndex,
    totalGroupRuns,
  })
}

function unsplitFuelBlockStep(runs: number, quantity: number): IndustryProjectStep {
  return step({
    id: FUEL_BLOCK_STEP_ID,
    blueprintTypeId: 4313,
    productTypeId: 4312,
    productTypeName: 'Oxygen Fuel Block',
    quantity,
    runs,
    depth: 1,
    activityType: 'manufacturing',
  })
}

function fuelBlockGroupOfFourJobs(): IndustryProjectStep[] {
  return [
    fuelBlockJob(0, 6, 6 * FUEL_BLOCKS_PER_RUN, 22),
    fuelBlockJob(1, 6, 6 * FUEL_BLOCKS_PER_RUN, 22),
    fuelBlockJob(2, 5, 5 * FUEL_BLOCKS_PER_RUN, 22),
    fuelBlockJob(3, 5, 184, 22),
  ]
}

function fuelBlockGroupOfThreeJobs(): IndustryProjectStep[] {
  return [
    fuelBlockJob(0, 1, FUEL_BLOCKS_PER_RUN, 3),
    fuelBlockJob(1, 1, FUEL_BLOCKS_PER_RUN, 3),
    fuelBlockJob(2, 1, 28, 3),
  ]
}

function project(steps: IndustryProjectStep[]): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Thanatos',
    productTypeId: 23911,
    displayName: null,
    runs: CARRIER_RUNS_BEFORE,
    meLevel: 10,
    teLevel: 20,
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

function serverAnswers(patchResponse: IndustryProjectStep | IndustryProjectStep[], reloadedSteps: IndustryProjectStep[]) {
  vi.mocked(apiRequest).mockImplementation(async (_path: string, options?: RequestInit) =>
    options?.method === 'PATCH' ? patchResponse : project(reloadedSteps))
}

function requestsSent(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([path, options]) => `${options?.method ?? 'GET'} ${path}`)
}

function stepsInTheStore(): Array<[string, number, number, string | null]> {
  return (useProjectsStore().currentProject?.steps ?? []).map(s => [s.id, s.runs, s.quantity, s.splitGroupId])
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('steps store after the removal of surplus jobs', () => {
  it('reloads the project when a returned step belongs to a split group', async () => {
    useProjectsStore().currentProject = project([carrierStep(CARRIER_RUNS_BEFORE), ...fuelBlockGroupOfFourJobs()])
    serverAnswers([carrierStep(CARRIER_RUNS_AFTER), ...fuelBlockGroupOfThreeJobs()], [carrierStep(CARRIER_RUNS_AFTER), ...fuelBlockGroupOfThreeJobs()])

    await useStepsStore().updateStepRuns(PROJECT_ID, CARRIER_STEP_ID, CARRIER_RUNS_AFTER)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${CARRIER_STEP_ID}`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore()).toEqual([
      [CARRIER_STEP_ID, 1, 1, null],
      [FUEL_BLOCK_JOB_IDS[0], 1, 40, FUEL_BLOCK_SPLIT_GROUP_ID],
      [FUEL_BLOCK_JOB_IDS[1], 1, 40, FUEL_BLOCK_SPLIT_GROUP_ID],
      [FUEL_BLOCK_JOB_IDS[2], 1, 28, FUEL_BLOCK_SPLIT_GROUP_ID],
    ])
  })

  it('reloads the project when the released job is no longer in the response', async () => {
    useProjectsStore().currentProject = project([carrierStep(CARRIER_RUNS_AFTER), ...fuelBlockGroupOfFourJobs()])
    serverAnswers(fuelBlockGroupOfThreeJobs(), [carrierStep(CARRIER_RUNS_AFTER), ...fuelBlockGroupOfThreeJobs()])

    await useStepsStore().releaseStepRuns(PROJECT_ID, FUEL_BLOCK_JOB_IDS[3])

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${FUEL_BLOCK_JOB_IDS[3]}`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore().map(([id]) => id)).toEqual([CARRIER_STEP_ID, ...FUEL_BLOCK_JOB_IDS.slice(0, 3)])
  })

  it('reloads the project when the edited step is missing from a response without split group', async () => {
    useProjectsStore().currentProject = project([carrierStep(CARRIER_RUNS_BEFORE), unsplitFuelBlockStep(22, 864)])
    serverAnswers([carrierStep(CARRIER_RUNS_BEFORE)], [carrierStep(CARRIER_RUNS_BEFORE)])

    await useStepsStore().updateStepMeLevel(PROJECT_ID, FUEL_BLOCK_STEP_ID, 8)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${FUEL_BLOCK_STEP_ID}`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore().map(([id]) => id)).toEqual([CARRIER_STEP_ID])
  })

  it('reloads the project when a returned step was in a split group before the response', async () => {
    useProjectsStore().currentProject = project([carrierStep(CARRIER_RUNS_BEFORE), fuelBlockJob(0, 15, 600, 20), fuelBlockJob(1, 5, 200, 20)])
    const dissolvedJob = { ...fuelBlockJob(0, 15, 600, 20), splitGroupId: null, totalGroupRuns: null, runsFixed: true }
    serverAnswers([carrierStep(4), dissolvedJob], [carrierStep(4), dissolvedJob])

    await useStepsStore().updateStepRuns(PROJECT_ID, CARRIER_STEP_ID, 4)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${CARRIER_STEP_ID}`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore()).toEqual([
      [CARRIER_STEP_ID, 4, 4, null],
      [FUEL_BLOCK_JOB_IDS[0], 15, 600, null],
    ])
  })

  it('replaces the returned steps one by one when no split group is involved', async () => {
    useProjectsStore().currentProject = project([carrierStep(CARRIER_RUNS_BEFORE), unsplitFuelBlockStep(22, 864)])
    serverAnswers([carrierStep(CARRIER_RUNS_AFTER), unsplitFuelBlockStep(3, 108)], [])

    await useStepsStore().updateStepRuns(PROJECT_ID, CARRIER_STEP_ID, CARRIER_RUNS_AFTER)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${CARRIER_STEP_ID}`])
    expect(stepsInTheStore()).toEqual([
      [CARRIER_STEP_ID, 1, 1, null],
      [FUEL_BLOCK_STEP_ID, 3, 108, null],
    ])
  })

  it('replaces a split job answered alone without reloading the project', async () => {
    useProjectsStore().currentProject = project([carrierStep(CARRIER_RUNS_BEFORE), ...fuelBlockGroupOfFourJobs()])
    serverAnswers({ ...fuelBlockGroupOfFourJobs()[1], teLevel: 14 }, [])

    await useStepsStore().updateStepTeLevel(PROJECT_ID, FUEL_BLOCK_JOB_IDS[1], 14)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${FUEL_BLOCK_JOB_IDS[1]}`])
    expect(useProjectsStore().currentProject?.steps?.map(s => s.teLevel)).toEqual([20, 20, 14, 20, 20])
  })
})

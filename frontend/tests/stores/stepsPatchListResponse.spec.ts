import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import { useStepsStore } from '@/stores/industry/steps'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-233'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const THANATOS_STEP_ID = 'step-233-thanatos'
const FUSION_REACTOR_UNIT_STEP_ID = 'step-233-fusion-reactor-unit'
const FERNITE_ALLOY_STEP_ID = 'step-233-fernite-alloy'
const OXYGEN_FUEL_BLOCK_JOB_IDS = ['job-233-oxygen-0', 'job-233-oxygen-1']
const OXYGEN_FUEL_BLOCK_SPLIT_GROUP_ID = 'split-233-oxygen-fuel-block'

function stepOf(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType'>): IndustryProjectStep {
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

function thanatosStep(runs: number): IndustryProjectStep {
  return stepOf({
    id: THANATOS_STEP_ID,
    blueprintTypeId: 23912,
    productTypeId: 23911,
    productTypeName: 'Thanatos',
    quantity: runs,
    runs,
    depth: 0,
    activityType: 'manufacturing',
  })
}

function fusionReactorUnitStep(runs: number, overrides: Partial<IndustryProjectStep> = {}): IndustryProjectStep {
  return stepOf({
    id: FUSION_REACTOR_UNIT_STEP_ID,
    blueprintTypeId: 17330,
    productTypeId: 11694,
    productTypeName: 'Fusion Reactor Unit',
    quantity: runs * 2,
    runs,
    depth: 1,
    activityType: 'manufacturing',
    meLevel: 9,
    teLevel: 18,
    ...overrides,
  })
}

function ferniteAlloyStep(runs: number): IndustryProjectStep {
  return stepOf({
    id: FERNITE_ALLOY_STEP_ID,
    blueprintTypeId: 46168,
    productTypeId: 16656,
    productTypeName: 'Fernite Alloy',
    quantity: runs * 200,
    runs,
    depth: 2,
    activityType: 'reaction',
    meLevel: 0,
    teLevel: 0,
  })
}

function oxygenFuelBlockJob(splitIndex: number, runs: number, teLevel: number): IndustryProjectStep {
  return stepOf({
    id: OXYGEN_FUEL_BLOCK_JOB_IDS[splitIndex],
    blueprintTypeId: 4313,
    productTypeId: 4312,
    productTypeName: 'Oxygen Fuel Block',
    quantity: runs * 40,
    runs,
    depth: 1,
    activityType: 'manufacturing',
    meLevel: 8,
    teLevel,
    splitGroupId: OXYGEN_FUEL_BLOCK_SPLIT_GROUP_ID,
    splitIndex,
    totalGroupRuns: 13,
  })
}

function projectOf(steps: IndustryProjectStep[]): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Thanatos',
    productTypeId: 23911,
    displayName: null,
    runs: 3,
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
    createdAt: '2026-11-03T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps,
    tree: null,
    failedProducts: [],
  }
}

function projectInTheStore(): IndustryProject {
  return projectOf([thanatosStep(3), fusionReactorUnitStep(12), ferniteAlloyStep(7), oxygenFuelBlockJob(0, 7, 20), oxygenFuelBlockJob(1, 6, 20)])
}

function serverAnswers(patchResponse: IndustryProjectStep[], reloadedSteps: IndustryProjectStep[]) {
  vi.mocked(apiRequest).mockImplementation(async (_path: string, options?: RequestInit) =>
    options?.method === 'PATCH' ? patchResponse : projectOf(reloadedSteps))
}

function requestsSent(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([path, options]) => `${options?.method ?? 'GET'} ${path}`)
}

function stepsInTheStore(): Array<[string, number, number, boolean, number, number]> {
  return (useProjectsStore().currentProject?.steps ?? []).map(s => [s.id, s.runs, s.quantity, s.purchased, s.inStockQuantity, s.teLevel])
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('steps store reading the list answered to a step patch', () => {
  it('replaces the edited step answered alone without reloading the project', async () => {
    useProjectsStore().currentProject = projectInTheStore()
    serverAnswers([fusionReactorUnitStep(15, { runsFixed: true })], [])

    const editedStep = await useStepsStore().updateStepRuns(PROJECT_ID, FUSION_REACTOR_UNIT_STEP_ID, 15)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${FUSION_REACTOR_UNIT_STEP_ID}`])
    expect([editedStep?.id, editedStep?.runs, editedStep?.quantity, editedStep?.runsFixed]).toEqual([FUSION_REACTOR_UNIT_STEP_ID, 15, 30, true])
    expect(stepsInTheStore()).toEqual([
      [THANATOS_STEP_ID, 3, 3, false, 0, 20],
      [FUSION_REACTOR_UNIT_STEP_ID, 15, 30, false, 0, 18],
      [FERNITE_ALLOY_STEP_ID, 7, 1_400, false, 0, 0],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[0], 7, 280, false, 0, 20],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[1], 6, 240, false, 0, 20],
    ])
  })

  it('replaces the edited step and the recalculated steps listed after it without reloading the project', async () => {
    useProjectsStore().currentProject = projectInTheStore()
    serverAnswers([fusionReactorUnitStep(16, { meLevel: 6 }), ferniteAlloyStep(9)], [])

    const editedStep = await useStepsStore().updateStepMeLevel(PROJECT_ID, FUSION_REACTOR_UNIT_STEP_ID, 6)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${FUSION_REACTOR_UNIT_STEP_ID}`])
    expect([editedStep?.id, editedStep?.meLevel, editedStep?.runs]).toEqual([FUSION_REACTOR_UNIT_STEP_ID, 6, 16])
    expect(stepsInTheStore()).toEqual([
      [THANATOS_STEP_ID, 3, 3, false, 0, 20],
      [FUSION_REACTOR_UNIT_STEP_ID, 16, 32, false, 0, 18],
      [FERNITE_ALLOY_STEP_ID, 9, 1_800, false, 0, 0],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[0], 7, 280, false, 0, 20],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[1], 6, 240, false, 0, 20],
    ])
  })

  it('reloads the project and returns no step when the list answered is empty', async () => {
    useProjectsStore().currentProject = projectInTheStore()
    serverAnswers([], [thanatosStep(3), fusionReactorUnitStep(12), oxygenFuelBlockJob(0, 7, 20), oxygenFuelBlockJob(1, 6, 20)])

    const editedStep = await useStepsStore().releaseStepRuns(PROJECT_ID, FERNITE_ALLOY_STEP_ID)

    expect(editedStep).toBeUndefined()
    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${FERNITE_ALLOY_STEP_ID}`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore().map(([id]) => id)).toEqual([THANATOS_STEP_ID, FUSION_REACTOR_UNIT_STEP_ID, ...OXYGEN_FUEL_BLOCK_JOB_IDS])
  })

  it('marks the step purchased from a list of that step alone without reloading the project', async () => {
    useProjectsStore().currentProject = projectInTheStore()
    serverAnswers([fusionReactorUnitStep(12, { purchased: true })], [])

    await useStepsStore().toggleStepPurchased(PROJECT_ID, FUSION_REACTOR_UNIT_STEP_ID, true)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${FUSION_REACTOR_UNIT_STEP_ID}`])
    expect(stepsInTheStore()).toEqual([
      [THANATOS_STEP_ID, 3, 3, false, 0, 20],
      [FUSION_REACTOR_UNIT_STEP_ID, 12, 24, true, 0, 18],
      [FERNITE_ALLOY_STEP_ID, 7, 1_400, false, 0, 0],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[0], 7, 280, false, 0, 20],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[1], 6, 240, false, 0, 20],
    ])
  })

  it('sets the stock of the step from a list of that step alone without reloading the project', async () => {
    useProjectsStore().currentProject = projectInTheStore()
    serverAnswers([{ ...ferniteAlloyStep(7), inStockQuantity: 600 }], [])

    await useStepsStore().toggleStepInStock(PROJECT_ID, FERNITE_ALLOY_STEP_ID, 600)

    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${FERNITE_ALLOY_STEP_ID}`])
    expect(stepsInTheStore()).toEqual([
      [THANATOS_STEP_ID, 3, 3, false, 0, 20],
      [FUSION_REACTOR_UNIT_STEP_ID, 12, 24, false, 0, 18],
      [FERNITE_ALLOY_STEP_ID, 7, 1_400, false, 600, 0],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[0], 7, 280, false, 0, 20],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[1], 6, 240, false, 0, 20],
    ])
  })

  it('reloads the project when the list answered holds several steps with a job of a split group', async () => {
    useProjectsStore().currentProject = projectInTheStore()
    const reloadedSteps = [thanatosStep(4), fusionReactorUnitStep(12), ferniteAlloyStep(7), oxygenFuelBlockJob(0, 9, 20), oxygenFuelBlockJob(1, 8, 20)]
    serverAnswers([thanatosStep(4), oxygenFuelBlockJob(0, 9, 20), oxygenFuelBlockJob(1, 8, 20)], reloadedSteps)

    const editedStep = await useStepsStore().updateStepRuns(PROJECT_ID, THANATOS_STEP_ID, 4)

    expect([editedStep?.id, editedStep?.runs]).toEqual([THANATOS_STEP_ID, 4])
    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${THANATOS_STEP_ID}`, `GET ${PROJECT_PATH}`])
    expect(stepsInTheStore().map(([id, runs, quantity]) => [id, runs, quantity])).toEqual([
      [THANATOS_STEP_ID, 4, 4],
      [FUSION_REACTOR_UNIT_STEP_ID, 12, 24],
      [FERNITE_ALLOY_STEP_ID, 7, 1_400],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[0], 9, 360],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[1], 8, 320],
    ])
  })

  it('replaces a job of a split group answered alone without reloading the project', async () => {
    useProjectsStore().currentProject = projectInTheStore()
    serverAnswers([oxygenFuelBlockJob(1, 6, 14)], [])

    const editedStep = await useStepsStore().updateStepTeLevel(PROJECT_ID, OXYGEN_FUEL_BLOCK_JOB_IDS[1], 14)

    expect([editedStep?.id, editedStep?.teLevel]).toEqual([OXYGEN_FUEL_BLOCK_JOB_IDS[1], 14])
    expect(requestsSent()).toEqual([`PATCH ${PROJECT_PATH}/steps/${OXYGEN_FUEL_BLOCK_JOB_IDS[1]}`])
    expect(stepsInTheStore().map(([id, , , , , teLevel]) => [id, teLevel])).toEqual([
      [THANATOS_STEP_ID, 20],
      [FUSION_REACTOR_UNIT_STEP_ID, 18],
      [FERNITE_ALLOY_STEP_ID, 0],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[0], 20],
      [OXYGEN_FUEL_BLOCK_JOB_IDS[1], 14],
    ])
  })
})

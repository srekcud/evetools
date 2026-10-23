import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useStepsStore } from '@/stores/industry/steps'
import type { IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-185'
const STEP_ID = 'step-fusion-reactor-unit'
const ENTERED_RUNS = 20
const CAPPED_RUNS = 18

function fusionReactorUnitStep(runs: number, runsFixed: boolean): IndustryProjectStep {
  return {
    id: STEP_ID,
    blueprintTypeId: 17330,
    productTypeId: 11694,
    productTypeName: 'Fusion Reactor Unit',
    quantity: runs * 2,
    runs,
    runsFixed,
    depth: 1,
    activityType: 'manufacturing',
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
  }
}

function bodyOfTheStepPatch(): unknown {
  expect(apiRequest).toHaveBeenCalledTimes(1)
  const [path, options] = vi.mocked(apiRequest).mock.calls[0]
  expect(path).toBe(`/industry/projects/${PROJECT_ID}/steps/${STEP_ID}`)
  expect(options?.method).toBe('PATCH')
  return JSON.parse(String(options?.body))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(fusionReactorUnitStep(CAPPED_RUNS, true))
})

describe('steps store fixed step runs', () => {
  it('patches only the entered runs by default', async () => {
    await useStepsStore().updateStepRuns(PROJECT_ID, STEP_ID, ENTERED_RUNS)

    expect(bodyOfTheStepPatch()).toEqual({ runs: ENTERED_RUNS })
  })

  it('patches the entered runs with the overproduction confirmation', async () => {
    await useStepsStore().updateStepRuns(PROJECT_ID, STEP_ID, ENTERED_RUNS, true)

    expect(bodyOfTheStepPatch()).toEqual({ runs: ENTERED_RUNS, overproduce: true })
  })

  it('releases the fixed runs back to the automatic calculation', async () => {
    await useStepsStore().releaseStepRuns(PROJECT_ID, STEP_ID)

    expect(bodyOfTheStepPatch()).toEqual({ runsFixed: false })
  })
})

import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { CharacterSyncFailure, IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-197'

function matchedKestrelStep(): IndustryProjectStep {
  return {
    id: 'step-kestrel',
    blueprintTypeId: 947,
    productTypeId: 602,
    productTypeName: 'Kestrel',
    quantity: 6,
    runs: 6,
    depth: 0,
    activityType: 'manufacturing',
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 9,
    teLevel: 18,
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
    jobsCost: 734500.5,
    purchases: [],
    purchasesCost: null,
    purchasedQuantity: 6,
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

function projectWithoutMatchedJobs(id: string): IndustryProject {
  return {
    id,
    productTypeName: 'Kestrel',
    productTypeId: 602,
    displayName: null,
    runs: 6,
    meLevel: 9,
    teLevel: 18,
    maxJobDurationDays: 3,
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
    createdAt: '2026-10-28T14:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps: [],
    tree: null,
    failedProducts: [],
  }
}

const FAILED_CHARACTERS: CharacterSyncFailure[] = [
  { characterName: 'Pilot One', reason: 'esi_unavailable', statusCode: 503 },
  { characterName: 'Pilot Two', reason: 'esi_error', statusCode: 401 },
  { characterName: 'Pilot Three', reason: 'missing_token', statusCode: null },
]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('projects store job matching', () => {
  it('returns the failed characters of the job sync', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ steps: [], jobsCost: 0, syncedCharacters: 0, failedCharacters: FAILED_CHARACTERS })

    const failedCharacters = await useProjectsStore().matchJobs(PROJECT_ID)

    expect(failedCharacters).toEqual(FAILED_CHARACTERS)
    expect(vi.mocked(apiRequest).mock.calls).toEqual([[`/industry/projects/${PROJECT_ID}/match-jobs`, { method: 'POST', body: '{}' }]])
  })

  it('returns no failed character when every character was synced', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ steps: [], jobsCost: 1250000, syncedCharacters: 3, failedCharacters: [] })

    expect(await useProjectsStore().matchJobs(PROJECT_ID)).toEqual([])
  })

  it('updates the current project steps and jobsCost', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ steps: [matchedKestrelStep()], jobsCost: 734500.5, syncedCharacters: 2, failedCharacters: [] })
    const store = useProjectsStore()
    store.currentProject = projectWithoutMatchedJobs(PROJECT_ID)

    await store.matchJobs(PROJECT_ID)

    expect(store.currentProject?.steps).toEqual([{ ...matchedKestrelStep(), inStock: true }])
    expect(store.currentProject?.jobsCost).toBe(734500.5)
  })

  it('leaves a current project of another id unchanged', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ steps: [matchedKestrelStep()], jobsCost: 734500.5, syncedCharacters: 2, failedCharacters: [] })
    const store = useProjectsStore()
    store.currentProject = projectWithoutMatchedJobs('project-other')

    await store.matchJobs(PROJECT_ID)

    expect(store.currentProject?.steps).toEqual([])
    expect(store.currentProject?.jobsCost).toBe(0)
  })

  it('returns no failed character and keeps the error when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Bad gateway'))

    const store = useProjectsStore()
    const failedCharacters = await store.matchJobs(PROJECT_ID)

    expect(failedCharacters).toEqual([])
    expect(store.error).toBe('Bad gateway')
  })
})

import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, ShoppingListFailedProduct } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-fernite-carbide'
const PATCHED_NOTES = 'Haul Fernite Alloy from Amarr'
const FERNITE_ALLOY_FAILED: ShoppingListFailedProduct[] = [{ typeId: 16656, reason: 'Types missing from the SDE: 16639' }]

function ferniteCarbideProject(overrides: Partial<IndustryProject>): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Fernite Carbide',
    productTypeId: 16673,
    displayName: null,
    runs: 1,
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
    createdAt: '2026-10-29T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    ...overrides,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('projects store failed products after a project patch', () => {
  it('keeps the failed products of the last read when the patch response does not compute them', async () => {
    const store = useProjectsStore()
    vi.mocked(apiRequest)
      .mockResolvedValueOnce(ferniteCarbideProject({ steps: [], tree: null, failedProducts: FERNITE_ALLOY_FAILED }))
      .mockResolvedValueOnce(ferniteCarbideProject({ notes: PATCHED_NOTES, failedProducts: null }))
    await store.fetchProject(PROJECT_ID)

    await store.updateProject(PROJECT_ID, { notes: PATCHED_NOTES })

    expect(store.currentProject?.notes).toBe(PATCHED_NOTES)
    expect(store.currentProject?.failedProducts).toEqual(FERNITE_ALLOY_FAILED)
  })
})

import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const JOIN_CODE = 'K7M2QX'

const PROJECTS_URL = '/industry/projects'
const AVAILABLE_PROJECTS_URL = '/industry/projects/available'
const BOM_URL = `/industry/projects/${PROJECT_ID}/bom`
const JOIN_URL = `/industry/projects/join/${JOIN_CODE}`

const ISHTAR_BATCH = { id: PROJECT_ID, name: 'Ishtar batch', status: 'draft', private: true } as unknown as GroupProject
const TRITANIUM_LINE = { id: 'line-tritanium', typeId: 34, typeName: 'Tritanium', isJob: false } as unknown as BomItem
const ISHTAR_LINE_ID = 'job-ishtar'
const PLATES_LINE_ID = 'job-capital-armor-plates'
const TRANSACTION_UUID = '0192f0c4-7b1e-7a3c-9d2e-205000000201'
const AVAILABLE_JOBS_URL = `/industry/projects/${PROJECT_ID}/available-jobs`
const LINK_JOB_URL = `/industry/projects/${PROJECT_ID}/bom/${ISHTAR_LINE_ID}/link-job`
const UNLINK_JOB_URL = `/industry/projects/${PROJECT_ID}/job-matches/match-aria`
const PURCHASE_SUGGESTIONS_URL = `/industry/projects/${PROJECT_ID}/purchase-suggestions`
const PLATES_PURCHASES_URL = `/industry/projects/${PROJECT_ID}/bom/${PLATES_LINE_ID}/purchases`
const DELETE_PURCHASE_URL = `/industry/projects/${PROJECT_ID}/purchases/purchase-manual`
const AVAILABLE_JOB = { esiJobId: 9001, characterName: 'Cyra Lune', linkedToBomLineId: null, matchId: null }
const PURCHASE_SUGGESTIONS = { suggestions: [{ transactionId: 6_400_000_201, transactionUuid: TRANSACTION_UUID, typeId: 21010, alreadyLinked: false }], totalCount: 1, walletReadings: [{ characterName: 'Cyra Lune', status: 'read', readAt: '2026-11-13T20:04:10+00:00', nextReadAt: '2026-11-13T21:04:00+00:00' }] }

type PurchaseInput = { transactionId: string } | { typeId: number; quantity: number; unitPrice: number }

type JobLinksAndPurchases = {
  fetchAvailableJobs(projectId: string): Promise<unknown>
  linkJob(projectId: string, lineId: string, esiJobId: number): Promise<void>
  unlinkJob(projectId: string, matchId: string): Promise<void>
  fetchPurchaseSuggestions(projectId: string): Promise<unknown>
  createPurchase(projectId: string, lineId: string, input: PurchaseInput): Promise<void>
  deletePurchase(projectId: string, purchaseId: string): Promise<void>
}

function jobLinksAndPurchases(): JobLinksAndPurchases {
  return useGroupProjectStore() as unknown as JobLinksAndPurchases
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group project store API paths', () => {
  it('loads my projects and the available projects from the unified project routes', async () => {
    vi.mocked(apiRequest).mockImplementation((async (url: string) => (url === PROJECTS_URL ? [ISHTAR_BATCH] : [])) as never)
    const store = useGroupProjectStore()

    await store.fetchMyProjects()
    await store.fetchAvailableProjects()

    expect(vi.mocked(apiRequest).mock.calls.map(([url]) => url)).toEqual([PROJECTS_URL, AVAILABLE_PROJECTS_URL])
    expect(store.myProjects).toEqual([ISHTAR_BATCH])
    expect(store.error).toBeNull()
  })

  it('loads the BOM of a project from its unified project route', async () => {
    vi.mocked(apiRequest).mockResolvedValue([TRITANIUM_LINE])
    const store = useGroupProjectStore()

    await store.fetchBom(PROJECT_ID)

    expect(apiRequest).toHaveBeenCalledWith(BOM_URL)
    expect(store.bomItems).toEqual([TRITANIUM_LINE])
  })

  it('joins a project by its short link code on the unified project route', async () => {
    vi.mocked(apiRequest).mockResolvedValue(ISHTAR_BATCH)
    const store = useGroupProjectStore()

    const joined = await store.joinProject(JOIN_CODE)

    expect(apiRequest).toHaveBeenCalledWith(JOIN_URL, { method: 'POST', body: '{}' })
    expect(joined).toEqual(ISHTAR_BATCH)
  })

  it('reads the available jobs, links a job to a line and unlinks a match on the unified project routes, reloading the BOM after each change', async () => {
    vi.mocked(apiRequest).mockImplementation((async (url: string) => {
      if (url === AVAILABLE_JOBS_URL) return [AVAILABLE_JOB]
      if (url === BOM_URL) return [TRITANIUM_LINE]
      return {}
    }) as never)
    const store = jobLinksAndPurchases()

    const availableJobs = await store.fetchAvailableJobs(PROJECT_ID)
    await store.linkJob(PROJECT_ID, ISHTAR_LINE_ID, 9001)
    await store.unlinkJob(PROJECT_ID, 'match-aria')

    expect(availableJobs).toEqual([AVAILABLE_JOB])
    expect(vi.mocked(apiRequest).mock.calls).toEqual([
      [AVAILABLE_JOBS_URL],
      [LINK_JOB_URL, { method: 'POST', body: JSON.stringify({ esiJobId: 9001 }) }],
      [BOM_URL],
      [UNLINK_JOB_URL, { method: 'DELETE' }],
      [BOM_URL],
    ])
  })

  it('reads the purchase suggestions and creates a purchase from a transaction or by hand then deletes one, reloading the BOM after each change', async () => {
    vi.mocked(apiRequest).mockImplementation((async (url: string) => {
      if (url === PURCHASE_SUGGESTIONS_URL) return PURCHASE_SUGGESTIONS
      if (url === BOM_URL) return [TRITANIUM_LINE]
      return {}
    }) as never)
    const store = jobLinksAndPurchases()

    const suggestions = await store.fetchPurchaseSuggestions(PROJECT_ID)
    await store.createPurchase(PROJECT_ID, PLATES_LINE_ID, { transactionId: TRANSACTION_UUID })
    await store.createPurchase(PROJECT_ID, PLATES_LINE_ID, { typeId: 21010, quantity: 15, unitPrice: 1_080_000 })
    await store.deletePurchase(PROJECT_ID, 'purchase-manual')

    expect(suggestions).toEqual(PURCHASE_SUGGESTIONS)
    expect(vi.mocked(apiRequest).mock.calls).toEqual([
      [PURCHASE_SUGGESTIONS_URL],
      [PLATES_PURCHASES_URL, { method: 'POST', body: JSON.stringify({ transactionId: TRANSACTION_UUID }) }],
      [BOM_URL],
      [PLATES_PURCHASES_URL, { method: 'POST', body: JSON.stringify({ typeId: 21010, quantity: 15, unitPrice: 1_080_000 }) }],
      [BOM_URL],
      [DELETE_PURCHASE_URL, { method: 'DELETE' }],
      [BOM_URL],
    ])
  })
})

import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useIndustryWorkshopProjectsStore } from '@/stores/industry/workshopProjects'
import { useSyncStore } from '@/stores/sync'
import { phase, workshopMember, workshopProject } from '../industryWorkshopProjectFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const WORKSHOP_PROJECTS_ENDPOINT = '/industry/workshop-projects'
const NYX_PROJECT_ID = '0b6f3c2e-8d41-4a7b-9e15-5c2d7f80a3a1'
const WIDOW_PROJECT_ID = '7e2a9d14-3c5b-4f86-a0d2-91b4c6e8f2b7'

const ROWS = [
  workshopProject({
    projectId: NYX_PROJECT_ID,
    members: [workshopMember('Ilyra Castell', 91000001, 'owner'), workshopMember('Brann Holt', null, 'member')],
    phases: [phase('prepare', 'done', 1, 1), phase('supply', 'blocked', 0, 3, { code: 'materials_missing', count: 3 })],
    profit: { amount: 1_200_000, status: 'provisional' },
  }),
  workshopProject({
    projectId: WIDOW_PROJECT_ID,
    members: [workshopMember('Ilyra Castell', 91000001, 'owner')],
    phases: [phase('prepare', 'current', 0, 1)],
    profit: { amount: null, status: 'awaiting_sales' },
  }),
]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('industry workshop projects store', () => {
  it('exposes the rows read from the workshop projects endpoint, then no row at all and the failure when a refresh fails', async () => {
    const store = useIndustryWorkshopProjectsStore()
    const stateBeforeLoad = { rows: [...store.rows], isLoaded: store.isLoaded, error: store.error }
    vi.mocked(apiRequest).mockResolvedValueOnce(ROWS)
    await store.fetchWorkshopProjects()
    const loaded = { rows: [...store.rows], isLoaded: store.isLoaded, error: store.error }
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('Workshop projects service unavailable'))

    await store.fetchWorkshopProjects()

    expect({
      stateBeforeLoad,
      loaded,
      afterFailure: { rows: store.rows, isLoaded: store.isLoaded, error: store.error },
      endpoints: vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint),
    }).toEqual({
      stateBeforeLoad: { rows: [], isLoaded: false, error: null },
      loaded: { rows: ROWS, isLoaded: true, error: null },
      afterFailure: { rows: [], isLoaded: false, error: 'Workshop projects service unavailable' },
      endpoints: [WORKSHOP_PROJECTS_ENDPOINT, WORKSHOP_PROJECTS_ENDPOINT],
    })
  })

  it('reloads the rows when the industry project event is received', async () => {
    const store = useIndustryWorkshopProjectsStore()
    vi.mocked(apiRequest).mockResolvedValue(ROWS)
    await store.fetchWorkshopProjects()

    useSyncStore().syncStatus['industry-project'] = {
      syncType: 'industry-project',
      status: 'notification' as never,
      progress: null,
      message: 'Nyx job delivered',
      data: { projectId: NYX_PROJECT_ID },
      timestamp: '2026-11-07T10:00:00+00:00',
    }
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toEqual([WORKSHOP_PROJECTS_ENDPOINT, WORKSHOP_PROJECTS_ENDPOINT])
  })
})

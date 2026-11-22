import { createPinia, setActivePinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import { useUnassignedJobsStore } from '@/stores/group-industry/unassignedJobs'
import type { UnassignedJob } from '@/stores/group-industry/types'
import { unassignedCandidate, unassignedJob } from '../components/group-industry/unassignedJobFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LIST_PATH = '/industry/unassigned-jobs'
const ASSIGN_PATH = '/industry/unassigned-jobs/9102/assign'
const DISMISS_PATH = '/industry/unassigned-jobs/9101/dismiss'

const ISHTAR_JOB = unassignedJob({ esiJobId: 9_101 })
const VEXOR_JOB = unassignedJob({
  esiJobId: 9_102,
  characterName: 'Beta Pilot',
  blueprintTypeId: 17_841,
  productTypeId: 17_840,
  productTypeName: 'Vexor Navy Issue',
  runs: 2,
  stationId: 60_008_494,
  candidates: [unassignedCandidate({ projectId: 'project-vexor-7', projectName: 'Vexor run', lineId: 'line-vexor-hull', lineName: 'Vexor Navy Issue', remainingRuns: 3, score: 5, signals: ['place'] })],
})
const CARACAL_JOB = unassignedJob({ esiJobId: 9_103, characterName: 'Gamma Pilot', blueprintTypeId: 622, productTypeId: 621, productTypeName: 'Caracal', runs: 7, stationId: 60_011_866, candidates: [unassignedCandidate({ lineId: 'line-caracal-hull', lineName: 'Caracal', remainingRuns: 7, score: 3, signals: ['structure'] })] })

let servedJobs: UnassignedJob[]
let failingPath: string | null

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}

function syncEntry(syncType: string, status: 'in_progress' | 'completed') {
  return { syncType, status, progress: status === 'completed' ? 100 : 40, message: null, data: null, timestamp: '2026-11-22T12:00:00+00:00' }
}

beforeEach(() => {
  setActivePinia(createPinia())
  servedJobs = [ISHTAR_JOB, VEXOR_JOB]
  failingPath = null
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === failingPath) throw new Error('Unassigned job not found')
    if (endpoint === LIST_PATH) return servedJobs
    if (endpoint === ASSIGN_PATH) servedJobs = servedJobs.filter(job => job.esiJobId !== 9_102)
    if (endpoint === DISMISS_PATH) servedJobs = servedJobs.filter(job => job.esiJobId !== 9_101)
    return undefined
  })
})

describe('useUnassignedJobsStore', () => {
  it('loads the unassigned jobs in the order served and counts them', async () => {
    const store = useUnassignedJobsStore()

    await store.load()

    expect({ jobs: store.jobs, count: store.count }).toEqual({ jobs: [ISHTAR_JOB, VEXOR_JOB], count: 2 })
  })

  it('reloads when the industry jobs sync completes and when the connection comes back, and ignores a running industry jobs sync and the other syncs', async () => {
    const store = useUnassignedJobsStore()
    const syncStore = useSyncStore()
    await store.load()
    const loadsAtStart = callsTo(LIST_PATH)

    servedJobs = [ISHTAR_JOB, VEXOR_JOB, CARACAL_JOB]
    syncStore.syncStatus['industry-jobs'] = syncEntry('industry-jobs', 'in_progress')
    syncStore.syncStatus['blueprints'] = syncEntry('blueprints', 'completed')
    await flushPromises()
    const loadsAfterIgnoredSyncs = callsTo(LIST_PATH)
    syncStore.syncStatus['industry-jobs'] = syncEntry('industry-jobs', 'completed')
    await vi.waitFor(() => expect(store.count).toBe(3))

    servedJobs = [CARACAL_JOB]
    syncStore.reconnectedAt = 1_000
    await vi.waitFor(() => expect(store.count).toBe(1))

    expect({ loadsAtStart, loadsAfterIgnoredSyncs, loadsAtEnd: callsTo(LIST_PATH), jobs: store.jobs }).toEqual({
      loadsAtStart: 1,
      loadsAfterIgnoredSyncs: 1,
      loadsAtEnd: 3,
      jobs: [CARACAL_JOB],
    })
  })

  it('posts the line of the assignment and reloads the list, which loses the job', async () => {
    const store = useUnassignedJobsStore()
    await store.load()

    await store.assign(9_102, 'line-vexor-hull')

    expect({
      assignments: vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === ASSIGN_PATH),
      jobs: store.jobs.map(job => job.esiJobId),
      count: store.count,
      error: store.error,
    }).toEqual({
      assignments: [[ASSIGN_PATH, { method: 'POST', body: JSON.stringify({ lineId: 'line-vexor-hull' }) }]],
      jobs: [9_101],
      count: 1,
      error: null,
    })
  })

  it('posts the dismissal without body and reloads the list, which loses the job', async () => {
    const store = useUnassignedJobsStore()
    await store.load()

    await store.dismiss(9_101)

    expect({
      dismissals: vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === DISMISS_PATH),
      jobs: store.jobs.map(job => job.esiJobId),
      error: store.error,
    }).toEqual({
      dismissals: [[DISMISS_PATH, { method: 'POST' }]],
      jobs: [9_102],
      error: null,
    })
  })

  it('exposes the error of a failed load and of a failed assignment instead of swallowing it', async () => {
    const store = useUnassignedJobsStore()
    failingPath = LIST_PATH
    await store.load()
    const afterFailedLoad = store.error

    failingPath = null
    await store.load()
    failingPath = ASSIGN_PATH
    await store.assign(9_102, 'line-vexor-hull')

    expect({ afterFailedLoad, afterFailedAssignment: store.error, jobs: store.jobs.map(job => job.esiJobId) }).toEqual({
      afterFailedLoad: 'Unassigned job not found',
      afterFailedAssignment: 'Unassigned job not found',
      jobs: [9_101, 9_102],
    })
  })
})

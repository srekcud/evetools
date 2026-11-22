import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { PROJECT_DETAIL_READ_WAIT_LIMIT_MS, useProjectDetailRequestQueueStore } from '@/stores/group-industry/projectDetailRequestQueue'
import { deferred } from '../deferred'

const PROJECT_ID = 'project-ishtar-batch'
const OTHER_PROJECT_ID = 'project-hecate-batch'

let runAfterPreviousReadsOf: ReturnType<typeof useProjectDetailRequestQueueStore>['runAfterPreviousReadsOf']

beforeEach(() => {
  setActivePinia(createPinia())
  runAfterPreviousReadsOf = useProjectDetailRequestQueueStore().runAfterPreviousReadsOf
})

afterEach(() => {
  vi.useRealTimers()
})

describe('project detail request queue', () => {
  it('is a fresh queue for each pinia, so a read left pending in one does not hold the reads of another', async () => {
    void runAfterPreviousReadsOf(PROJECT_ID, () => new Promise<never>(() => undefined))
    setActivePinia(createPinia())

    const readOfAnotherPinia = useProjectDetailRequestQueueStore().runAfterPreviousReadsOf(PROJECT_ID, () => Promise.resolve('rows'))

    expect(await readOfAnotherPinia).toBe('rows')
  })

  it('starts a read only once the read queued before it has finished', async () => {
    const first = deferred<string>()
    const started: string[] = []

    const firstRead = runAfterPreviousReadsOf(PROJECT_ID, () => {
      started.push('chain')
      return first.promise
    })
    const secondRead = runAfterPreviousReadsOf(PROJECT_ID, () => {
      started.push('sourcing-decisions')
      return Promise.resolve('rows')
    })
    await flushPromises()
    const startedWhileFirstIsPending = [...started]
    first.resolve('nodes')
    await flushPromises()

    expect(startedWhileFirstIsPending).toEqual(['chain'])
    expect(started).toEqual(['chain', 'sourcing-decisions'])
    expect(await firstRead).toBe('nodes')
    expect(await secondRead).toBe('rows')
  })

  it('does not make the reads of a project wait for a read of another project', async () => {
    const started: string[] = []

    void runAfterPreviousReadsOf(PROJECT_ID, () => {
      started.push('chain of the first project')
      return new Promise<never>(() => undefined)
    })
    const readOfOtherProject = runAfterPreviousReadsOf(OTHER_PROJECT_ID, () => {
      started.push('chain of the other project')
      return Promise.resolve('nodes')
    })

    expect(await readOfOtherProject).toBe('nodes')
    expect(started).toEqual(['chain of the first project', 'chain of the other project'])
  })

  it('runs the reads in the order they were queued', async () => {
    const started: number[] = []

    const reads = [1, 2, 3, 4, 5].map(rank => runAfterPreviousReadsOf(PROJECT_ID, async () => {
      started.push(rank)
      return rank
    }))

    expect(await Promise.all(reads)).toEqual([1, 2, 3, 4, 5])
    expect(started).toEqual([1, 2, 3, 4, 5])
  })

  it('still runs the next read when the previous one failed, and gives each caller its own outcome', async () => {
    const failing = runAfterPreviousReadsOf(PROJECT_ID, () => Promise.reject(new Error('Maximum execution time of 30 seconds exceeded')))
    const following = runAfterPreviousReadsOf(PROJECT_ID, () => Promise.resolve('research rows'))

    await expect(failing).rejects.toThrow('Maximum execution time of 30 seconds exceeded')
    expect(await following).toBe('research rows')
  })

  it('does not hold the next read behind a read that never answers', async () => {
    vi.useFakeTimers()
    const started: string[] = []

    void runAfterPreviousReadsOf(PROJECT_ID, () => {
      started.push('hanging')
      return new Promise<never>(() => undefined)
    })
    void runAfterPreviousReadsOf(PROJECT_ID, () => {
      started.push('next')
      return Promise.resolve('rows')
    })
    await vi.advanceTimersByTimeAsync(PROJECT_DETAIL_READ_WAIT_LIMIT_MS - 1)
    const startedJustBeforeTheLimit = [...started]
    await vi.advanceTimersByTimeAsync(1)

    expect(startedJustBeforeTheLimit).toEqual(['hanging'])
    expect(started).toEqual(['hanging', 'next'])
  })
})

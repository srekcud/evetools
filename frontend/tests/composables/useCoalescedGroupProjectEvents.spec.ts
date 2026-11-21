import { createPinia, setActivePinia } from 'pinia'
import { effectScope, ref, type EffectScope } from 'vue'
import { GROUP_PROJECT_EVENT_COALESCE_MS, useCoalescedGroupProjectEvents } from '@/composables/useCoalescedGroupProjectEvents'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'

const PROJECT_ID = 'project-nyx-batch'
const OTHER_PROJECT_ID = 'project-drake-batch'

const projectEvents = ref<Record<string, GroupProjectEvent>>({})
let scope: EffectScope
let refetch: ReturnType<typeof vi.fn>
let visibility: 'visible' | 'hidden'

function publish(action: string, projectId = PROJECT_ID): void {
  projectEvents.value = { ...projectEvents.value, [projectId]: { action, data: null, timestamp: '2026-11-21T10:00:00+00:00' } }
}

function startCoalescing(): void {
  scope = effectScope()
  scope.run(() => useCoalescedGroupProjectEvents(() => PROJECT_ID, refetch as never))
}

function becomeVisible(): void {
  visibility = 'visible'
  document.dispatchEvent(new Event('visibilitychange'))
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  setActivePinia(createPinia())
  projectEvents.value = {}
  visibility = 'visible'
  vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => visibility)
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  refetch = vi.fn().mockResolvedValue(undefined)
})

afterEach(() => {
  scope.stop()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('useCoalescedGroupProjectEvents', () => {
  it('refetches at once on the first event of a quiet period, with the action received', async () => {
    startCoalescing()

    publish('sale_recorded')
    await vi.advanceTimersByTimeAsync(0)

    expect(refetch.mock.calls.map(([actions]) => actions)).toEqual([['sale_recorded']])
  })

  it('collapses a burst into the immediate refetch plus one refetch at the end of the window carrying every later action', async () => {
    startCoalescing()

    for (const action of ['sale_recorded', 'sale_recorded', 'sale_updated', 'sale_deleted', 'sale_recorded']) publish(action)
    await vi.advanceTimersByTimeAsync(0)
    const callsBeforeTheWindowEnds = refetch.mock.calls.length
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)

    expect({ callsBeforeTheWindowEnds, calls: refetch.mock.calls.map(([actions]) => actions) }).toEqual({
      callsBeforeTheWindowEnds: 1,
      calls: [['sale_recorded'], ['sale_recorded', 'sale_updated', 'sale_deleted', 'sale_recorded']],
    })
  })

  it('refetches at once again for an event that follows a quiet window, and never refetches without an event', async () => {
    startCoalescing()
    publish('line_reserved')
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS * 2)
    const callsAfterTheQuietWindow = refetch.mock.calls.length

    publish('line_reserved')
    await vi.advanceTimersByTimeAsync(0)

    expect([callsAfterTheQuietWindow, refetch.mock.calls.length]).toEqual([1, 2])
  })

  it('starts no second refetch while one is in flight, then runs one with the events received meanwhile once it settles', async () => {
    let finishFirstRefetch: () => void = () => undefined
    refetch.mockImplementationOnce(() => new Promise<void>((resolve) => { finishFirstRefetch = resolve }))
    startCoalescing()

    publish('sale_recorded')
    await vi.advanceTimersByTimeAsync(0)
    publish('sale_updated')
    publish('sale_deleted')
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS * 3)
    const callsWhileInFlight = refetch.mock.calls.length
    finishFirstRefetch()
    await vi.advanceTimersByTimeAsync(0)

    expect({ callsWhileInFlight, calls: refetch.mock.calls.map(([actions]) => actions) }).toEqual({
      callsWhileInFlight: 1,
      calls: [['sale_recorded'], ['sale_updated', 'sale_deleted']],
    })
  })

  it('does not refetch while the browser tab is hidden and refetches once, with every action, when it becomes visible', async () => {
    visibility = 'hidden'
    startCoalescing()

    publish('sale_recorded')
    publish('sale_updated')
    publish('sale_deleted')
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS * 2)
    const callsWhileHidden = refetch.mock.calls.length
    becomeVisible()
    await vi.advanceTimersByTimeAsync(0)

    expect({ callsWhileHidden, calls: refetch.mock.calls.map(([actions]) => actions) }).toEqual({
      callsWhileHidden: 0,
      calls: [['sale_recorded', 'sale_updated', 'sale_deleted']],
    })
  })

  it('ignores the events of another project', async () => {
    startCoalescing()

    publish('sale_recorded', OTHER_PROJECT_ID)
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)

    expect(refetch).not.toHaveBeenCalled()
  })

  it('refetches nothing once its scope is stopped, even for events received during the window', async () => {
    startCoalescing()
    publish('sale_recorded')
    await vi.advanceTimersByTimeAsync(0)
    publish('sale_updated')

    scope.stop()
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS * 2)
    visibility = 'visible'
    document.dispatchEvent(new Event('visibilitychange'))
    publish('sale_deleted')
    await vi.advanceTimersByTimeAsync(0)

    expect(refetch).toHaveBeenCalledTimes(1)
  })
})

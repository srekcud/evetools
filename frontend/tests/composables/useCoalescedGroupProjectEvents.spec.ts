import { createPinia, setActivePinia } from 'pinia'
import { effectScope, ref, shallowRef, type EffectScope } from 'vue'
import { GROUP_PROJECT_EVENT_COALESCE_MS, useCoalescedGroupProjectEvents } from '@/composables/useCoalescedGroupProjectEvents'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'

const PROJECT_ID = 'project-nyx-batch'
const OTHER_PROJECT_ID = 'project-drake-batch'

const projectEvents = ref<Record<string, GroupProjectEvent>>({})
let scope: EffectScope
let refetch: ReturnType<typeof vi.fn>
let visibility: 'visible' | 'hidden'
let companionVisibility: 'visible' | 'hidden'

const useCoalescingWithDocument = useCoalescedGroupProjectEvents as unknown as (...args: unknown[]) => void

function publish(action: string, projectId = PROJECT_ID): void {
  projectEvents.value = { ...projectEvents.value, [projectId]: { action, data: null, timestamp: '2026-11-21T10:00:00+00:00' } }
}

function startCoalescing(): void {
  scope = effectScope()
  scope.run(() => useCoalescedGroupProjectEvents(() => PROJECT_ID, refetch as never))
}

function startCoalescingWatching(visibilityDocument: () => Document): void {
  scope = effectScope()
  scope.run(() => useCoalescingWithDocument(() => PROJECT_ID, refetch as never, GROUP_PROJECT_EVENT_COALESCE_MS, visibilityDocument))
}

function createCompanionDocument(): Document {
  const companionDocument = document.implementation.createHTMLDocument('companion')
  Object.defineProperty(companionDocument, 'visibilityState', { get: () => companionVisibility, configurable: true })
  return companionDocument
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
  companionVisibility = 'visible'
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

  it('refetches at once, with the action received, when the main tab is hidden but the companion window is visible', async () => {
    visibility = 'hidden'
    const companionDocument = createCompanionDocument()
    startCoalescingWatching(() => companionDocument)

    publish('line_reserved')
    await vi.advanceTimersByTimeAsync(0)

    expect(refetch.mock.calls.map(([actions]) => actions)).toEqual([['line_reserved']])
  })

  it('does not refetch while the companion window is hidden even if the main tab is visible, and refetches once with every action when the window resumes', async () => {
    companionVisibility = 'hidden'
    const companionDocument = createCompanionDocument()
    startCoalescingWatching(() => companionDocument)

    publish('line_reserved')
    publish('line_released')
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS * 2)
    const callsWhileHidden = refetch.mock.calls.length
    companionVisibility = 'visible'
    companionDocument.dispatchEvent(new Event('visibilitychange'))
    await vi.advanceTimersByTimeAsync(0)

    expect({ callsWhileHidden, calls: refetch.mock.calls.map(([actions]) => actions) }).toEqual({
      callsWhileHidden: 0,
      calls: [['line_reserved', 'line_released']],
    })
  })

  it('waits for the main tab without a companion window, then sends the waiting action as soon as a visible companion window opens', async () => {
    visibility = 'hidden'
    const companionWindowDocument = shallowRef<Document | null>(null)
    startCoalescingWatching(() => companionWindowDocument.value ?? document)

    publish('step_reset')
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)
    const callsWithoutWindow = refetch.mock.calls.length
    companionWindowDocument.value = createCompanionDocument()
    await vi.advanceTimersByTimeAsync(0)

    expect({ callsWithoutWindow, calls: refetch.mock.calls.map(([actions]) => actions) }).toEqual({
      callsWithoutWindow: 0,
      calls: [['step_reset']],
    })
  })

  it('ignores the visibilitychange of a companion window that was closed and replaced by the main tab', async () => {
    visibility = 'hidden'
    companionVisibility = 'hidden'
    const closedWindowDocument = createCompanionDocument()
    const companionWindowDocument = shallowRef<Document | null>(closedWindowDocument)
    startCoalescingWatching(() => companionWindowDocument.value ?? document)
    publish('line_reserved')
    await vi.advanceTimersByTimeAsync(0)

    companionWindowDocument.value = null
    await vi.advanceTimersByTimeAsync(0)
    visibility = 'visible'
    companionVisibility = 'visible'
    closedWindowDocument.dispatchEvent(new Event('visibilitychange'))
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)

    expect(refetch).not.toHaveBeenCalled()
  })

  it('refetches nothing after its scope is stopped when the companion window becomes visible', async () => {
    visibility = 'hidden'
    companionVisibility = 'hidden'
    const companionDocument = createCompanionDocument()
    startCoalescingWatching(() => companionDocument)
    publish('line_reserved')
    await vi.advanceTimersByTimeAsync(0)

    scope.stop()
    companionVisibility = 'visible'
    companionDocument.dispatchEvent(new Event('visibilitychange'))
    publish('line_released')
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)

    expect(refetch).not.toHaveBeenCalled()
  })
})

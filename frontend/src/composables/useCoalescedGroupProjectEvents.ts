import { onScopeDispose, watch } from 'vue'
import { useSyncStore } from '@/stores/sync'

export const GROUP_PROJECT_EVENT_COALESCE_MS = 3000

export function useCoalescedGroupProjectEvents(
  projectId: () => string | null,
  refetch: (actions: string[]) => unknown,
  coalesceMs: number = GROUP_PROJECT_EVENT_COALESCE_MS,
  visibilityDocument: () => Document = () => document,
): void {
  const syncStore = useSyncStore()
  let waitingActions: string[] = []
  let windowOpen = false
  let refetchInFlight = false
  let windowTimer: ReturnType<typeof setTimeout> | null = null
  let stopped = false

  function runWaitingActions(): void {
    if (stopped || waitingActions.length === 0 || windowOpen || refetchInFlight) return
    if (visibilityDocument().visibilityState === 'hidden') return
    const actions = waitingActions
    waitingActions = []
    windowOpen = true
    refetchInFlight = true
    windowTimer = setTimeout(() => {
      windowOpen = false
      windowTimer = null
      runWaitingActions()
    }, coalesceMs)
    void (async () => {
      try {
        await refetch(actions)
      } finally {
        refetchInFlight = false
        runWaitingActions()
      }
    })()
  }

  watch(
    () => {
      const id = projectId()
      return id === null ? null : syncStore.getGroupProjectEvent(id)
    },
    (event) => {
      if (event === null) return
      waitingActions.push(event.action)
      runWaitingActions()
    },
    { flush: 'sync' },
  )

  const resumeWhenVisible = (): void => runWaitingActions()
  let listenedDocument: Document | null = null

  function stopListening(): void {
    listenedDocument?.removeEventListener('visibilitychange', resumeWhenVisible)
    listenedDocument = null
  }

  watch(
    visibilityDocument,
    (currentDocument) => {
      stopListening()
      listenedDocument = currentDocument
      currentDocument.addEventListener('visibilitychange', resumeWhenVisible)
      runWaitingActions()
    },
    { flush: 'sync', immediate: true },
  )

  onScopeDispose(() => {
    stopped = true
    waitingActions = []
    if (windowTimer !== null) clearTimeout(windowTimer)
    stopListening()
  })
}

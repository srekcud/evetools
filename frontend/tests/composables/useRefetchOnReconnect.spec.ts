import { createPinia, setActivePinia } from 'pinia'
import { effectScope, nextTick } from 'vue'
import { useRefetchOnReconnect } from '@/composables/useRefetchOnReconnect'
import { useSyncStore } from '@/stores/sync'

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('useRefetchOnReconnect', () => {
  it('does not refetch on mount, then refetches once per reconnection', async () => {
    const syncStore = useSyncStore()
    const refetch = vi.fn()
    const scope = effectScope()
    scope.run(() => useRefetchOnReconnect(refetch))
    await nextTick()
    const callsOnMount = refetch.mock.calls.length

    syncStore.reconnectedAt = 1000
    await nextTick()
    const callsAfterFirst = refetch.mock.calls.length
    syncStore.reconnectedAt = 2000
    await nextTick()

    expect({ callsOnMount, callsAfterFirst, callsAfterSecond: refetch.mock.calls.length }).toEqual({
      callsOnMount: 0,
      callsAfterFirst: 1,
      callsAfterSecond: 2,
    })
    scope.stop()
  })

  it('stops refetching once its scope is stopped', async () => {
    const syncStore = useSyncStore()
    const refetch = vi.fn()
    const scope = effectScope()
    scope.run(() => useRefetchOnReconnect(refetch))
    scope.stop()

    syncStore.reconnectedAt = 1000
    await nextTick()

    expect(refetch).not.toHaveBeenCalled()
  })

  it('does not refetch when the signal is reset to null', async () => {
    const syncStore = useSyncStore()
    syncStore.reconnectedAt = 1000
    const refetch = vi.fn()
    const scope = effectScope()
    scope.run(() => useRefetchOnReconnect(refetch))

    syncStore.reconnectedAt = null
    await nextTick()

    expect(refetch).not.toHaveBeenCalled()
    scope.stop()
  })
})

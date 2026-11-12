import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'

class FakeEventSource {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static readonly CLOSED = 2
  static instances: FakeEventSource[] = []

  readyState = 1
  onopen: (() => void) | null = null
  onmessage: ((event: MessageEvent) => void) | null = null
  onerror: ((event: Event) => void) | null = null

  constructor(readonly url: string) {
    FakeEventSource.instances.push(this)
  }

  close(): void {
    this.readyState = FakeEventSource.CLOSED
  }
}

function lastEventSource(): FakeEventSource {
  return FakeEventSource.instances[FakeEventSource.instances.length - 1]
}

function receive(message: Record<string, unknown>): void {
  lastEventSource().onmessage?.(new MessageEvent('message', { data: JSON.stringify(message) }))
}

function syncMessage(syncType: string, status: string): Record<string, unknown> {
  return { syncType, status, progress: 40, message: `${syncType} ${status}`, data: null, timestamp: '2026-11-12T10:00:00+00:00' }
}

function dropAndBrowserRetry(): void {
  const source = lastEventSource()
  source.readyState = FakeEventSource.CONNECTING
  source.onerror?.(new Event('error'))
  source.readyState = FakeEventSource.OPEN
  source.onopen?.()
}

async function connectedSyncStore() {
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-000000000787',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98000787,
    corporationName: 'Reconnect Corp',
  } as User

  const syncStore = useSyncStore()
  await syncStore.connect()
  await vi.waitFor(() => expect(FakeEventSource.instances.length).toBeGreaterThan(0))
  lastEventSource().onopen?.()

  return syncStore
}

beforeEach(() => {
  setActivePinia(createPinia())
  FakeEventSource.instances = []
  vi.stubGlobal('EventSource', FakeEventSource)
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({
    token: 'mercure-jwt',
    topics: ['/user/00000000-0000-4000-8000-000000000787/sync/corporation-assets'],
    hubUrl: '/.well-known/mercure',
  }), { status: 200 })))
  vi.spyOn(console, 'error').mockImplementation(() => undefined)
  vi.spyOn(console, 'debug').mockImplementation(() => undefined)
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('sync store, reconnection catch-up', () => {
  it('does not flag the first open after login as a reconnection', async () => {
    const syncStore = await connectedSyncStore()

    expect(syncStore.reconnectedAt).toBeNull()
  })

  it('flags an open that follows a drop and turns the running syncs into unknown', async () => {
    const syncStore = await connectedSyncStore()
    receive(syncMessage('corporation-assets', 'in_progress'))
    receive(syncMessage('industry-jobs', 'started'))

    dropAndBrowserRetry()

    expect({
      reconnectedAtSet: syncStore.reconnectedAt !== null,
      corporationAssets: syncStore.getSyncProgress('corporation-assets')?.status,
      industryJobs: syncStore.getSyncProgress('industry-jobs')?.status,
      isLoadingCorporationAssets: syncStore.isLoading('corporation-assets'),
      isAnyLoading: syncStore.isAnyLoading,
    }).toEqual({
      reconnectedAtSet: true,
      corporationAssets: 'unknown',
      industryJobs: 'unknown',
      isLoadingCorporationAssets: false,
      isAnyLoading: false,
    })
  })

  it('keeps the message of a stale sync and leaves finished syncs untouched', async () => {
    const syncStore = await connectedSyncStore()
    receive(syncMessage('corporation-assets', 'in_progress'))
    receive(syncMessage('character-assets', 'completed'))
    receive(syncMessage('pve', 'error'))

    dropAndBrowserRetry()

    expect({
      staleMessage: syncStore.getSyncProgress('corporation-assets')?.message,
      completed: syncStore.getSyncProgress('character-assets')?.status,
      failed: syncStore.getSyncProgress('pve')?.status,
      untrackedType: syncStore.getSyncProgress('mining'),
    }).toEqual({
      staleMessage: 'corporation-assets in_progress',
      completed: 'completed',
      failed: 'error',
      untrackedType: null,
    })
  })

  it('flags a store-driven retry after the connection was closed', async () => {
    vi.useFakeTimers()
    const syncStore = await connectedSyncStore()
    receive(syncMessage('corporation-assets', 'started'))
    const sourcesBeforeRetry = FakeEventSource.instances.length
    const closedSource = lastEventSource()
    closedSource.readyState = FakeEventSource.CLOSED
    closedSource.onerror?.(new Event('error'))

    await vi.advanceTimersByTimeAsync(3000)
    await vi.waitFor(() => expect(FakeEventSource.instances.length).toBe(sourcesBeforeRetry + 1))
    lastEventSource().onopen?.()

    expect({
      reconnectedAtSet: syncStore.reconnectedAt !== null,
      status: syncStore.getSyncProgress('corporation-assets')?.status,
    }).toEqual({ reconnectedAtSet: true, status: 'unknown' })
  })

  it('moves reconnectedAt forward on every reconnection', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-12T10:00:00Z'))
    const syncStore = await connectedSyncStore()

    dropAndBrowserRetry()
    const firstReconnection = syncStore.reconnectedAt
    vi.setSystemTime(new Date('2026-11-12T10:01:00Z'))
    dropAndBrowserRetry()

    expect(syncStore.reconnectedAt).toBeGreaterThan(firstReconnection as number)
  })

  it('does not flag a reconnection when no drop happened between two opens', async () => {
    const syncStore = await connectedSyncStore()

    lastEventSource().onopen?.()

    expect(syncStore.reconnectedAt).toBeNull()
  })

  it('does not count the first open after a logout and a new login as a reconnection', async () => {
    const syncStore = await connectedSyncStore()
    dropAndBrowserRetry()
    const authStore = useAuthStore()

    authStore.token = null
    authStore.user = null
    await nextTick()
    const reconnectedAfterLogout = syncStore.reconnectedAt
    const sourcesBeforeLogin = FakeEventSource.instances.length
    authStore.token = 'test-token-2'
    authStore.user = { id: '00000000-0000-4000-8000-000000000788', authStatus: 'valid', mainCharacter: null, characters: [], corporationId: 98000788, corporationName: 'Other Corp' } as User
    await vi.waitFor(() => expect(FakeEventSource.instances.length).toBeGreaterThan(sourcesBeforeLogin))
    lastEventSource().onopen?.()

    expect({ reconnectedAfterLogout, afterNewLogin: syncStore.reconnectedAt }).toEqual({ reconnectedAfterLogout: null, afterNewLogin: null })
  })
})

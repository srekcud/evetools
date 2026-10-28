import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'

class FakeEventSource {
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

function receive(message: Record<string, unknown>): void {
  FakeEventSource.instances[FakeEventSource.instances.length - 1].onmessage?.(new MessageEvent('message', { data: JSON.stringify(message) }))
}

async function connectedSyncStore() {
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-000000230000',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98000230,
    corporationName: 'Castell Extraction',
  } as User

  const syncStore = useSyncStore()
  await vi.waitFor(() => expect(FakeEventSource.instances.length).toBeGreaterThan(0))
  FakeEventSource.instances[FakeEventSource.instances.length - 1].onopen?.()

  return syncStore
}

beforeEach(() => {
  setActivePinia(createPinia())
  FakeEventSource.instances = []
  vi.stubGlobal('EventSource', FakeEventSource)
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({
    token: 'mercure-jwt',
    topics: [
      '/user/00000000-0000-4000-8000-000000230000/alerts/market-price',
      '/user/00000000-0000-4000-8000-000000230000/alerts/planetary-expiry',
    ],
    hubUrl: '/.well-known/mercure',
  }), { status: 200 })))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('sync store, alert messages', () => {
  it('does not store a market price alert message as a sync status', async () => {
    const syncStore = await connectedSyncStore()

    receive({ alertType: 'market-price', data: { typeId: 35, typeName: 'Pyerite', currentPrice: 13.75 }, timestamp: '2026-10-27T08:20:00+00:00' })

    expect(syncStore.syncStatus).toEqual({})
  })

  it('does not store a planetary expiry alert message as a sync status', async () => {
    const syncStore = await connectedSyncStore()

    receive({ alertType: 'planetary-expiry', data: { planetName: 'Jita IV', hoursLeft: 1 }, timestamp: '2026-10-27T08:40:00+00:00' })

    expect(syncStore.syncStatus).toEqual({})
  })

  it('keeps only the planetary sync status when a planetary sync completes after a market price alert', async () => {
    const syncStore = await connectedSyncStore()

    receive({ alertType: 'market-price', data: { typeId: 36, typeName: 'Mexallon', currentPrice: 50.4 }, timestamp: '2026-10-27T09:05:00+00:00' })
    receive({ syncType: 'planetary', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-10-27T09:10:00+00:00' })

    expect(Object.keys(syncStore.syncStatus)).toEqual(['planetary'])
    expect(syncStore.syncStatus.planetary.status).toBe('completed')
  })
})

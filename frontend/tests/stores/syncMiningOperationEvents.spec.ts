import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'

const OPERATION_O = '4d2f8a61-3b7c-4e95-a0d8-6c1e9f2b7a53'
const OPERATION_P2 = 'b7e4c2a9-1d63-4f58-8a0e-5c9d3f7b2e14'
const PROJECT_P1 = '0b6f3c2e-8d41-4a7b-9e15-5c2d7f80a3a1'
const USER_ID = '00000000-0000-4000-8000-000000000262'

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

function lastEventSource(): FakeEventSource {
  return FakeEventSource.instances[FakeEventSource.instances.length - 1]
}

function receive(message: Record<string, unknown>): void {
  lastEventSource().onmessage?.(new MessageEvent('message', { data: JSON.stringify(message) }))
}

async function connectedSyncStore() {
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: USER_ID,
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98_262_101,
    corporationName: 'Spodumain Corp',
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
    topics: [`/user/${USER_ID}/mining-operations`, `/group-project/${PROJECT_P1}/events`],
    hubUrl: '/.well-known/mercure',
  }), { status: 200 })))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('sync store, mining operation events', () => {
  it('keeps the last event of a mining operation by operation id without touching the sync status nor the project events', async () => {
    const syncStore = await connectedSyncStore()

    receive({
      action: 'member_joined',
      operationId: OPERATION_O,
      data: { memberId: 'member-cyra', status: 'pending' },
      timestamp: '2026-11-04T09:30:00+00:00',
    })

    expect(syncStore.getMiningOperationEvent(OPERATION_O)).toEqual({
      action: 'member_joined',
      data: { memberId: 'member-cyra', status: 'pending' },
      timestamp: '2026-11-04T09:30:00+00:00',
    })
    expect(syncStore.getMiningOperationEvent(OPERATION_P2)).toBeNull()
    expect(syncStore.syncStatus).toEqual({})
    expect(syncStore.getGroupProjectEvent(OPERATION_O)).toBeNull()
  })

  it('exposes two identical successive mining operation events as two distinct events', async () => {
    const syncStore = await connectedSyncStore()
    const joined = {
      action: 'member_joined',
      operationId: OPERATION_O,
      data: { memberId: 'member-cyra', status: 'pending' },
      timestamp: '2026-11-04T09:30:00+00:00',
    }

    receive(joined)
    const firstEvent = syncStore.getMiningOperationEvent(OPERATION_O)
    receive(joined)
    const secondEvent = syncStore.getMiningOperationEvent(OPERATION_O)

    expect(firstEvent).not.toBeNull()
    expect(secondEvent).toEqual(firstEvent)
    expect(secondEvent).not.toBe(firstEvent)
  })

  it('still stores a group project event under its project and not as a mining operation event', async () => {
    const syncStore = await connectedSyncStore()

    receive({
      action: 'member_joined',
      projectId: PROJECT_P1,
      data: { memberId: 'm-4' },
      timestamp: '2026-11-04T09:31:00+00:00',
    })

    expect(syncStore.getGroupProjectEvent(PROJECT_P1)).toEqual({
      action: 'member_joined',
      data: { memberId: 'm-4' },
      timestamp: '2026-11-04T09:31:00+00:00',
    })
    expect(syncStore.getMiningOperationEvent(PROJECT_P1)).toBeNull()
  })
})

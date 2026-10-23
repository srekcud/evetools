import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'

const PROJECT_P1 = '0b6f3c2e-8d41-4a7b-9e15-5c2d7f80a3a1'
const PROJECT_P2 = '7e2a9d14-3c5b-4f86-a0d2-91b4c6e8f2b7'

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
    id: '00000000-0000-4000-8000-000000000148',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98000148,
    corporationName: 'Realtime Corp',
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
    topics: [`/group-project/${PROJECT_P1}/events`, `/group-project/${PROJECT_P2}/events`],
    hubUrl: '/.well-known/mercure',
  }), { status: 200 })))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('sync store, group project events', () => {
  it('keeps the last event of a group project by project id without touching the sync status', async () => {
    const syncStore = await connectedSyncStore()

    receive({
      action: 'contribution_approved',
      projectId: PROJECT_P1,
      data: { contributionId: 'c-3' },
      timestamp: '2026-10-23T08:15:00+00:00',
    })

    expect(syncStore.getGroupProjectEvent(PROJECT_P1)).toEqual({
      action: 'contribution_approved',
      data: { contributionId: 'c-3' },
      timestamp: '2026-10-23T08:15:00+00:00',
    })
    expect(syncStore.syncStatus).toEqual({})
  })

  it('does not change the last event of a project when another project receives one', async () => {
    const syncStore = await connectedSyncStore()

    receive({
      action: 'member_joined',
      projectId: PROJECT_P1,
      data: { memberId: 'm-4' },
      timestamp: '2026-10-23T08:20:00+00:00',
    })
    receive({
      action: 'sale_recorded',
      projectId: PROJECT_P2,
      data: { saleId: 's-9' },
      timestamp: '2026-10-23T08:21:00+00:00',
    })

    expect(syncStore.getGroupProjectEvent(PROJECT_P1)).toEqual({
      action: 'member_joined',
      data: { memberId: 'm-4' },
      timestamp: '2026-10-23T08:20:00+00:00',
    })
  })

  it('exposes two identical successive events as two distinct events', async () => {
    const syncStore = await connectedSyncStore()
    const approval = {
      action: 'contribution_approved',
      projectId: PROJECT_P1,
      data: { contributionId: 'c-5' },
      timestamp: '2026-10-23T08:30:00+00:00',
    }

    receive(approval)
    const firstEvent = syncStore.getGroupProjectEvent(PROJECT_P1)
    receive(approval)
    const secondEvent = syncStore.getGroupProjectEvent(PROJECT_P1)

    expect(firstEvent).not.toBeNull()
    expect(secondEvent).toEqual(firstEvent)
    expect(secondEvent).not.toBe(firstEvent)
  })

  it('still stores a sync message under its sync type', async () => {
    const syncStore = await connectedSyncStore()
    const industryJobsSync = {
      syncType: 'industry-jobs',
      status: 'completed',
      progress: 100,
      message: null,
      data: { jobsCount: 12 },
      timestamp: '2026-10-23T08:40:00+00:00',
    }

    receive(industryJobsSync)

    expect(syncStore.syncStatus).toEqual({ 'industry-jobs': industryJobsSync })
    expect(syncStore.getSyncProgress('industry-jobs')).toEqual(industryJobsSync)
  })

  it('still hands a notification message to the notifications store', async () => {
    const syncStore = await connectedSyncStore()
    const notification = {
      id: 'n-17',
      category: 'industry',
      level: 'success',
      title: 'Job completed',
      message: 'Orca x2 delivered',
      data: null,
      route: null,
      isRead: false,
      createdAt: '2026-10-23T08:45:00+00:00',
    }

    receive(notification)

    await vi.waitFor(() => expect(useNotificationsStore().notifications).toEqual([notification]))
    expect(syncStore.syncStatus).toEqual({})
  })
})

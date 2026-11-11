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

  constructor(readonly url: string, readonly init?: EventSourceInit) {
    FakeEventSource.instances.push(this)
  }

  close(): void {
    this.readyState = FakeEventSource.CLOSED
  }
}

const TOPICS = [
  '/user/00000000-0000-4000-8000-000000613000/sync/character-assets',
  '/user/00000000-0000-4000-8000-000000613000/notifications',
  '/group-project/00000000-0000-4000-8000-000000613001/events',
]

async function openedSubscriptionUrl(): Promise<URL> {
  const authStore = useAuthStore()
  authStore.token = 'api-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-000000613000',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98000613,
    corporationName: 'Voss Logistics',
  } as User

  useSyncStore()
  await vi.waitFor(() => expect(FakeEventSource.instances.length).toBeGreaterThan(0))

  return new URL(FakeEventSource.instances[FakeEventSource.instances.length - 1].url, 'https://evetools.local')
}

beforeEach(() => {
  setActivePinia(createPinia())
  FakeEventSource.instances = []
  vi.stubGlobal('EventSource', FakeEventSource)
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({
    token: 'mercure-jwt',
    topics: TOPICS,
    hubUrl: '/.well-known/mercure',
  }), { status: 200 })))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('sync store, Mercure 1.0 subscription', () => {
  it('subscribes to every topic with the match query parameter', async () => {
    const url = await openedSubscriptionUrl()

    expect(url.pathname).toBe('/.well-known/mercure')
    expect(url.searchParams.getAll('match')).toEqual(TOPICS)
  })

  it('does not use the legacy topic query parameter', async () => {
    const url = await openedSubscriptionUrl()

    expect(url.searchParams.has('topic')).toBe(false)
  })

  it('keeps the subscriber token out of the subscription url', async () => {
    const url = await openedSubscriptionUrl()

    expect(url.searchParams.has('authorization')).toBe(false)
    expect(url.toString()).not.toContain('mercure-jwt')
  })

  it('sends the subscriber authorization cookie with the subscription', async () => {
    await openedSubscriptionUrl()

    expect(FakeEventSource.instances[FakeEventSource.instances.length - 1].init).toEqual({ withCredentials: true })
  })
})

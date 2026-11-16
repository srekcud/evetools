import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'
import {
  callsTo,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_ID,
  PROJECT_PATH,
  route,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OTHER_PROJECT_ID = 'project-already-joined'
const PROJECT_TOPIC = `/group-project/${PROJECT_ID}/events`
const OTHER_PROJECT_TOPIC = `/group-project/${OTHER_PROJECT_ID}/events`
const BOM_PATH = `${PROJECT_PATH}/bom`

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

  subscribedTopics(): string[] {
    return new URL(this.url, 'http://localhost').searchParams.getAll('match')
  }

  deliverAsTheHub(message: Record<string, unknown> & { projectId: string }): void {
    if (!this.subscribedTopics().includes(`/group-project/${message.projectId}/events`)) return
    this.onmessage?.(new MessageEvent('message', { data: JSON.stringify(message) }))
  }
}

type TokenAnswer = { topics: string[] } | { failure: number }

let tokenAnswers: TokenAnswer[]
let tokenRequests: number

function answerTokenRequests(...answers: TokenAnswer[]): void {
  tokenAnswers = answers
}

function nextTokenAnswer(): TokenAnswer {
  const answer = tokenAnswers[Math.min(tokenRequests, tokenAnswers.length - 1)]
  tokenRequests += 1
  return answer
}

function currentEventSource(): FakeEventSource {
  return FakeEventSource.instances[FakeEventSource.instances.length - 1]
}

async function connectBeforeTheProjectExists(): Promise<void> {
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-000000001004',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98001004,
    corporationName: 'Companion Corp',
  } as User

  useSyncStore()
  await vi.waitFor(() => expect(FakeEventSource.instances.length).toBeGreaterThan(0))
  currentEventSource().onopen?.()
}

async function openProjectPage(): Promise<void> {
  await mountCockpitDetail({
    project: nyxProject('in_progress'),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
  })
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  FakeEventSource.instances = []
  tokenRequests = 0
  vi.stubGlobal('EventSource', FakeEventSource)
  vi.stubGlobal('fetch', vi.fn(async () => {
    const answer = nextTokenAnswer()
    if ('failure' in answer) return new Response('{}', { status: answer.failure })
    return new Response(JSON.stringify({ token: 'mercure-jwt', topics: answer.topics, hubUrl: '/.well-known/mercure' }), { status: 200 })
  }))
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('project page realtime subscription', () => {
  it('subscribes to the project topic when the project was created after the realtime connection opened', async () => {
    answerTokenRequests({ topics: [OTHER_PROJECT_TOPIC] }, { topics: [OTHER_PROJECT_TOPIC, PROJECT_TOPIC] })
    await connectBeforeTheProjectExists()
    const connectionOpenedBeforeTheProject = currentEventSource()

    await openProjectPage()

    expect(currentEventSource().subscribedTopics()).toContain(PROJECT_TOPIC)
    expect(connectionOpenedBeforeTheProject.readyState).toBe(FakeEventSource.CLOSED)
  })

  it('reloads the BOM when a reservation event arrives on the connection opened for the project', async () => {
    answerTokenRequests({ topics: [OTHER_PROJECT_TOPIC] }, { topics: [OTHER_PROJECT_TOPIC, PROJECT_TOPIC] })
    await connectBeforeTheProjectExists()
    await openProjectPage()
    currentEventSource().onopen?.()
    const bomLoadsAfterMount = callsTo(BOM_PATH)

    currentEventSource().deliverAsTheHub({
      action: 'line_reserved',
      projectId: PROJECT_ID,
      data: { bomItemId: 'bom-nocxium', released: false },
      timestamp: '2026-11-16T12:00:00+00:00',
    })
    await flushPromises()

    expect(callsTo(BOM_PATH)).toBeGreaterThan(bomLoadsAfterMount)
  })

  it('keeps the connection and asks for no new token when the project topic is already subscribed', async () => {
    answerTokenRequests({ topics: [OTHER_PROJECT_TOPIC, PROJECT_TOPIC] })
    await connectBeforeTheProjectExists()
    const openedConnection = currentEventSource()

    await openProjectPage()

    expect(FakeEventSource.instances).toEqual([openedConnection])
    expect(openedConnection.readyState).not.toBe(FakeEventSource.CLOSED)
    expect(tokenRequests).toBe(1)
  })

  it('keeps the connection when the refreshed token still does not grant the project topic', async () => {
    answerTokenRequests({ topics: [OTHER_PROJECT_TOPIC] })
    await connectBeforeTheProjectExists()
    const openedConnection = currentEventSource()

    await openProjectPage()

    expect(FakeEventSource.instances).toEqual([openedConnection])
    expect(openedConnection.readyState).not.toBe(FakeEventSource.CLOSED)
  })

  it('keeps the connection and still loads the page when the token request fails', async () => {
    answerTokenRequests({ topics: [OTHER_PROJECT_TOPIC] }, { failure: 500 })
    await connectBeforeTheProjectExists()
    const openedConnection = currentEventSource()
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    await openProjectPage()

    expect(FakeEventSource.instances).toEqual([openedConnection])
    expect(openedConnection.readyState).not.toBe(FakeEventSource.CLOSED)
    expect(callsTo(BOM_PATH)).toBeGreaterThan(0)
  })
})

import { defineComponent } from 'vue'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useTodoItems, type TodoItem, type TodoItems } from '@/composables/useTodoItems'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'
import {
  ACTIVE_TRITANIUM_ALERT,
  acknowledgement,
  acknowledgementWrites,
  EXPIRED_ISOGEN_ALERT,
  INVALID_TOKEN,
  MISSING_SCOPES_TOKEN,
  NO_COLONY_STATS,
  PENDING,
  TRIGGERED_MEXALLON_ALERT,
  TRIGGERED_NOCXIUM_ALERT,
  TRIGGERED_PYERITE_ALERT,
  planetaryStats,
  serverError,
  sourcesAnswering,
  todoUser,
  type SourceAnswer,
} from './todoSources'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

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

async function receiveMercure(message: Record<string, unknown>): Promise<void> {
  await vi.waitFor(() => expect(FakeEventSource.instances.length).toBeGreaterThan(0))
  const eventSource = FakeEventSource.instances[FakeEventSource.instances.length - 1]
  eventSource.onmessage?.(new MessageEvent('message', { data: JSON.stringify(message) }))
  await flushPromises()
}

interface TodoSources {
  user?: User | null
  planetaryStats?: SourceAnswer[]
  marketAlerts?: SourceAnswer[]
  acknowledgements?: SourceAnswer[]
  acknowledgementWriteError?: Error
}

async function useTodoItemsWith(sources: TodoSources): Promise<TodoItems> {
  const user = sources.user === undefined ? todoUser() : sources.user
  vi.mocked(apiRequest).mockImplementation(sourcesAnswering({
    user,
    planetaryStats: sources.planetaryStats ?? [planetaryStats()],
    marketAlerts: sources.marketAlerts ?? [[ACTIVE_TRITANIUM_ALERT]],
    acknowledgements: sources.acknowledgements,
    acknowledgementWriteError: sources.acknowledgementWriteError,
  }))

  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = user

  let todo: TodoItems | null = null
  mount(defineComponent({
    setup() {
      todo = useTodoItems()
      return () => null
    },
  }), { global: { plugins: [pinia] } })
  await flushPromises()

  return todo as unknown as TodoItems
}

function writes() {
  return acknowledgementWrites(vi.mocked(apiRequest).mock.calls)
}

function labelsAndCounts(todo: TodoItems): Array<[string, number | null]> {
  return todo.items.value.map(todoItem => [todoItem.labelKey, todoItem.count])
}

function apiCallsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  FakeEventSource.instances = []
  vi.mocked(apiRequest).mockReset()
  vi.stubGlobal('EventSource', FakeEventSource)
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({
    token: 'mercure-jwt',
    topics: ['/user/00000000-0000-4000-8000-000000230000/sync/planetary'],
    hubUrl: '/.well-known/mercure',
  }), { status: 200 })))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useTodoItems, one item per non-empty category', () => {
  it('reports all caught up with no item when every source answered and nothing needs action', async () => {
    const todo = await useTodoItemsWith({})

    expect(todo.items.value).toEqual([])
    expect(todo.isLoading.value).toBe(false)
    expect(todo.isAllCaughtUp.value).toBe(true)
  })

  it('lists two expiring extractors as one warning item linking to planetary', async () => {
    const todo = await useTodoItemsWith({ planetaryStats: [planetaryStats({ expiringExtractors: 2 })] })

    expect(todo.items.value).toMatchObject([
      { labelKey: 'expiringExtractors', count: 2, characterNames: [], level: 'warning', link: '/planetary' },
    ])
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it('lists one character to reconnect as a critical item citing Doran Vex', async () => {
    const todo = await useTodoItemsWith({ user: todoUser({ doran: INVALID_TOKEN }) })

    expect(todo.items.value).toMatchObject([
      { labelKey: 'charactersToReconnect', count: 1, characterNames: ['Doran Vex'], level: 'critical', link: '/characters' },
    ])
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it('counts two characters with missing scopes and cites the main character first', async () => {
    const todo = await useTodoItemsWith({ user: todoUser({ ilyra: MISSING_SCOPES_TOKEN, doran: MISSING_SCOPES_TOKEN }) })

    expect(todo.items.value).toMatchObject([
      { labelKey: 'charactersToReconnect', count: 2, characterNames: ['Ilyra Castell', 'Doran Vex'], level: 'critical', link: '/characters' },
    ])
  })

  it('reports at least one character to reconnect without a name when the authorization is revoked', async () => {
    const todo = await useTodoItemsWith({ user: todoUser({ authStatus: 'invalid' }) })

    expect(todo.items.value).toMatchObject([
      { labelKey: 'atLeastCharactersToReconnect', count: 1, characterNames: [], level: 'critical', link: '/characters' },
    ])
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it('reports at least one character to reconnect citing Doran Vex when the authorization is revoked and his token is invalid', async () => {
    const todo = await useTodoItemsWith({ user: todoUser({ authStatus: 'invalid', doran: INVALID_TOKEN }) })

    expect(todo.items.value).toMatchObject([
      { labelKey: 'atLeastCharactersToReconnect', count: 1, characterNames: ['Doran Vex'], level: 'critical', link: '/characters' },
    ])
  })

  it('reports at least two characters to reconnect when the authorization is revoked and both tokens are invalid', async () => {
    const todo = await useTodoItemsWith({ user: todoUser({ authStatus: 'invalid', ilyra: INVALID_TOKEN, doran: INVALID_TOKEN }) })

    expect(todo.items.value).toMatchObject([
      { labelKey: 'atLeastCharactersToReconnect', count: 2, characterNames: ['Ilyra Castell', 'Doran Vex'], level: 'critical', link: '/characters' },
    ])
  })

  it('orders the four categories characters, stopped extractors, expiring extractors, triggered price alerts', async () => {
    const todo = await useTodoItemsWith({
      user: todoUser({ doran: INVALID_TOKEN }),
      planetaryStats: [planetaryStats({ expiredExtractors: 1, expiringExtractors: 3 })],
      marketAlerts: [[TRIGGERED_PYERITE_ALERT, ACTIVE_TRITANIUM_ALERT, TRIGGERED_MEXALLON_ALERT]],
    })

    expect(todo.items.value).toMatchObject([
      { labelKey: 'charactersToReconnect', count: 1, characterNames: ['Doran Vex'], level: 'critical', link: '/characters' },
      { labelKey: 'expiredExtractors', count: 1, characterNames: [], level: 'critical', link: '/planetary' },
      { labelKey: 'expiringExtractors', count: 3, characterNames: [], level: 'warning', link: '/planetary' },
      { labelKey: 'triggeredPriceAlerts', count: 2, characterNames: [], level: 'info', link: '/market?tab=alerts' },
    ])
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it('does not count an expired price alert as to do', async () => {
    const todo = await useTodoItemsWith({ marketAlerts: [[EXPIRED_ISOGEN_ALERT, ACTIVE_TRITANIUM_ALERT]] })

    expect(todo.items.value).toEqual([])
    expect(todo.isAllCaughtUp.value).toBe(true)
  })

  it('shows no planetary item when the stats count no colony', async () => {
    const todo = await useTodoItemsWith({ planetaryStats: [NO_COLONY_STATS] })

    expect(todo.items.value).toEqual([])
    expect(todo.isAllCaughtUp.value).toBe(true)
  })
})

describe('useTodoItems, unknown sources', () => {
  it('shows the extractors as one unknown item when the planetary stats fail, never as zero', async () => {
    const todo = await useTodoItemsWith({ planetaryStats: [serverError()] })

    expect(todo.items.value).toHaveLength(1)
    expect(todo.items.value[0]).toMatchObject({ labelKey: 'unknownExtractors', count: null, characterNames: [], level: null, link: '/planetary' })
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it('shows the price alerts as unknown when the alerts fail', async () => {
    const todo = await useTodoItemsWith({ marketAlerts: [serverError()] })

    expect(todo.items.value).toHaveLength(1)
    expect(todo.items.value[0]).toMatchObject({ labelKey: 'unknownPriceAlerts', count: null, characterNames: [], level: null, link: '/market?tab=alerts' })
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it('keeps the unknown price alerts after one stopped extractor', async () => {
    const todo = await useTodoItemsWith({
      planetaryStats: [planetaryStats({ expiredExtractors: 1 })],
      marketAlerts: [serverError()],
    })

    expect(todo.items.value.map(item => [item.labelKey, item.count, item.characterNames, item.level, item.link])).toEqual([
      ['expiredExtractors', 1, [], 'critical', '/planetary'],
      ['unknownPriceAlerts', null, [], null, '/market?tab=alerts'],
    ])
  })

  it('shows the characters to reconnect as unknown when the user is absent after loading', async () => {
    const todo = await useTodoItemsWith({ user: null })

    expect(todo.items.value).toHaveLength(1)
    expect(todo.items.value[0]).toMatchObject({ labelKey: 'unknownCharacters', count: null, characterNames: [], level: null, link: '/characters' })
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it('reports loading and not all caught up while the planetary stats have not answered', async () => {
    const todo = await useTodoItemsWith({ planetaryStats: [PENDING] })

    expect(todo.isLoading.value).toBe(true)
    expect(todo.isAllCaughtUp.value).toBe(false)
    expect(todo.items.value).toEqual([])
  })
})

describe('useTodoItems, live updates', () => {
  it('reloads the planetary stats when the planetary sync completes and drops the expiring extractors', async () => {
    const todo = await useTodoItemsWith({ planetaryStats: [planetaryStats({ expiringExtractors: 2 }), planetaryStats()] })
    expect(todo.items.value.map(item => [item.labelKey, item.count])).toEqual([['expiringExtractors', 2]])

    useSyncStore()
    await receiveMercure({ syncType: 'planetary', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-10-27T09:30:00+00:00' })

    expect(apiCallsTo('/planetary/stats')).toBe(2)
    expect(todo.items.value).toEqual([])
    expect(todo.isAllCaughtUp.value).toBe(true)
  })

  it('does not show again the four expiring extractors when the reload after the planetary sync fails', async () => {
    const todo = await useTodoItemsWith({ planetaryStats: [planetaryStats({ expiringExtractors: 4 }), serverError()] })
    expect(todo.items.value.map(item => [item.labelKey, item.count])).toEqual([['expiringExtractors', 4]])

    useSyncStore()
    await receiveMercure({ syncType: 'planetary', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-10-27T10:00:00+00:00' })

    expect(apiCallsTo('/planetary/stats')).toBe(2)
    expect(todo.items.value.map(item => [item.labelKey, item.count])).toEqual([['unknownExtractors', null]])
  })

  it('does not show again the three triggered price alerts when the reload after a market price alert message fails', async () => {
    const todo = await useTodoItemsWith({
      marketAlerts: [[TRIGGERED_PYERITE_ALERT, TRIGGERED_MEXALLON_ALERT, TRIGGERED_NOCXIUM_ALERT, ACTIVE_TRITANIUM_ALERT], serverError()],
    })
    expect(todo.items.value.map(item => [item.labelKey, item.count])).toEqual([['triggeredPriceAlerts', 3]])

    useSyncStore()
    await receiveMercure({ alertType: 'market-price', data: { typeId: 36, typeName: 'Mexallon', currentPrice: 87.25 }, timestamp: '2026-10-27T13:20:00+00:00' })

    expect(apiCallsTo('/market/alerts')).toBe(2)
    expect(todo.items.value).toMatchObject([
      { labelKey: 'unknownPriceAlerts', count: null, characterNames: [], level: null, link: '/market?tab=alerts' },
    ])
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it.each([
    ['started', 0],
    ['in_progress', 45],
  ] as const)('does not reload the planetary stats while the planetary sync is %s', async (status, progress) => {
    const todo = await useTodoItemsWith({ planetaryStats: [planetaryStats({ expiringExtractors: 5 }), planetaryStats()] })

    const syncStore = useSyncStore()
    await receiveMercure({ syncType: 'planetary', status, progress, message: null, data: null, timestamp: '2026-10-27T14:05:00+00:00' })

    expect(syncStore.planetaryProgress?.status).toBe(status)
    expect(apiCallsTo('/planetary/stats')).toBe(1)
    expect(todo.items.value).toMatchObject([
      { labelKey: 'expiringExtractors', count: 5, characterNames: [], level: 'warning', link: '/planetary' },
    ])
  })

  it('reloads the price alerts on a market price alert message and lists the triggered alert', async () => {
    const todo = await useTodoItemsWith({ marketAlerts: [[ACTIVE_TRITANIUM_ALERT], [ACTIVE_TRITANIUM_ALERT, TRIGGERED_NOCXIUM_ALERT]] })
    expect(todo.isAllCaughtUp.value).toBe(true)

    const syncStore = useSyncStore()
    await receiveMercure({ alertType: 'market-price', data: { typeId: 38, typeName: 'Nocxium', currentPrice: 641.5 }, timestamp: '2026-10-27T11:15:00+00:00' })

    expect(apiCallsTo('/market/alerts')).toBe(2)
    expect(todo.items.value).toMatchObject([
      { labelKey: 'triggeredPriceAlerts', count: 1, characterNames: [], level: 'info', link: '/market?tab=alerts' },
    ])
    expect(Object.keys(syncStore.syncStatus)).not.toContain('undefined')
  })

  it('reloads the planetary stats on a planetary expiry alert message and lists the expiring extractor', async () => {
    const todo = await useTodoItemsWith({ planetaryStats: [planetaryStats(), planetaryStats({ expiringExtractors: 1 })] })
    expect(todo.isAllCaughtUp.value).toBe(true)

    useSyncStore()
    await receiveMercure({ alertType: 'planetary-expiry', data: { planetName: 'Perimeter II', hoursLeft: 2 }, timestamp: '2026-10-27T12:45:00+00:00' })

    expect(apiCallsTo('/planetary/stats')).toBe(2)
    expect(todo.items.value).toMatchObject([
      { labelKey: 'expiringExtractors', count: 1, characterNames: [], level: 'warning', link: '/planetary' },
    ])
  })
})

describe('useTodoItems, acknowledgements', () => {
  const EXPIRED_ACKNOWLEDGEMENT = acknowledgement('expiredExtractors', 2)

  it('keeps an unacknowledged line visible and marks only the three aggregated lines as acknowledgeable', async () => {
    const todo = await useTodoItemsWith({
      user: todoUser({ doran: INVALID_TOKEN }),
      planetaryStats: [planetaryStats({ expiredExtractors: 2, expiringExtractors: 1 })],
      marketAlerts: [[TRIGGERED_PYERITE_ALERT]],
    })

    expect(todo.items.value.map(todoItem => [todoItem.labelKey, todoItem.count, todoItem.acknowledgeable])).toEqual([
      ['charactersToReconnect', 1, false],
      ['expiredExtractors', 2, true],
      ['expiringExtractors', 1, true],
      ['triggeredPriceAlerts', 1, true],
    ])
  })

  it.each([
    [2, [] as Array<[string, number | null]>],
    [3, [['expiredExtractors', 3]] as Array<[string, number | null]>],
    [1, [] as Array<[string, number | null]>],
  ])('shows the stopped extractors line as %i against an acknowledgement at 2 with the lines %j', async (stoppedExtractors, expected) => {
    const todo = await useTodoItemsWith({
      planetaryStats: [planetaryStats({ expiredExtractors: stoppedExtractors })],
      acknowledgements: [[EXPIRED_ACKNOWLEDGEMENT]],
    })

    expect(labelsAndCounts(todo)).toEqual(expected)
    expect(writes()).toEqual([])
    expect(todo.isAllCaughtUp.value).toBe(expected.length === 0)
  })

  it('deletes the acknowledgement when the count falls to 0 and shows the line again when the cause returns at 2', async () => {
    const todo = await useTodoItemsWith({
      planetaryStats: [planetaryStats({ expiredExtractors: 0 }), planetaryStats({ expiredExtractors: 2 })],
      acknowledgements: [[EXPIRED_ACKNOWLEDGEMENT]],
    })

    expect(writes()).toEqual([{ endpoint: '/me/bridge/acknowledgements/expiredExtractors', method: 'DELETE', body: null }])
    expect(todo.items.value).toEqual([])

    useSyncStore()
    await receiveMercure({ syncType: 'planetary', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-11-11T09:30:00+00:00' })

    expect(labelsAndCounts(todo)).toEqual([['expiredExtractors', 2]])
  })

  it('hides the triggered price alerts line acknowledged at 1 without touching the alert', async () => {
    const todo = await useTodoItemsWith({
      marketAlerts: [[TRIGGERED_PYERITE_ALERT]],
      acknowledgements: [[acknowledgement('triggeredPriceAlerts', 1)]],
    })

    expect(todo.items.value).toEqual([])
    expect(apiCallsTo('/market/alerts')).toBe(1)
    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint.startsWith('/market/alerts/'))).toEqual([])
  })

  it('acknowledges the expiring extractors line and the expired one independently', async () => {
    const todo = await useTodoItemsWith({
      planetaryStats: [planetaryStats({ expiredExtractors: 2, expiringExtractors: 1 })],
      acknowledgements: [[acknowledgement('expiringExtractors', 1)]],
    })

    expect(labelsAndCounts(todo)).toEqual([['expiredExtractors', 2]])
  })

  it('deletes no acknowledgement while a source failed or is still loading', async () => {
    const todo = await useTodoItemsWith({
      planetaryStats: [serverError()],
      marketAlerts: [PENDING],
      acknowledgements: [[EXPIRED_ACKNOWLEDGEMENT, acknowledgement('triggeredPriceAlerts', 1)]],
    })

    expect(writes()).toEqual([])
    expect(todo.items.value.map(todoItem => [todoItem.labelKey, todoItem.acknowledgeable])).toEqual([['unknownExtractors', false]])
  })

  it('marks the unknown lines as not acknowledgeable', async () => {
    const todo = await useTodoItemsWith({ user: null, marketAlerts: [serverError()] })

    expect(todo.items.value.map(todoItem => [todoItem.labelKey, todoItem.acknowledgeable])).toEqual([
      ['unknownCharacters', false],
      ['unknownPriceAlerts', false],
    ])
  })

  it('hides no line when the acknowledgements cannot be read', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})

    const todo = await useTodoItemsWith({
      planetaryStats: [planetaryStats({ expiredExtractors: 2 })],
      acknowledgements: [serverError()],
    })

    expect(labelsAndCounts(todo)).toEqual([['expiredExtractors', 2]])
    expect(todo.isLoading.value).toBe(false)
    expect(writes()).toEqual([])
  })

  it('keeps showing the loading state and not all caught up until the acknowledgements are loaded', async () => {
    const todo = await useTodoItemsWith({
      planetaryStats: [planetaryStats({ expiredExtractors: 2 })],
      acknowledgements: [PENDING],
    })

    expect(todo.isLoading.value).toBe(true)
    expect(todo.isAllCaughtUp.value).toBe(false)
  })

  it('posts the current count when acknowledging a line and hides it', async () => {
    const todo = await useTodoItemsWith({ planetaryStats: [planetaryStats({ expiredExtractors: 2, expiringExtractors: 3 })] })
    const expiringLine = todo.items.value.find(todoItem => todoItem.labelKey === 'expiringExtractors')

    await todo.acknowledge(expiringLine as TodoItem)
    await flushPromises()

    expect(writes()).toEqual([{ endpoint: '/me/bridge/acknowledgements', method: 'POST', body: { itemKey: 'expiringExtractors', count: 3 } }])
    expect(labelsAndCounts(todo)).toEqual([['expiredExtractors', 2]])
  })

  it('keeps the line visible and logs the failure when the acknowledgement cannot be written', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    const todo = await useTodoItemsWith({
      planetaryStats: [planetaryStats({ expiredExtractors: 2 })],
      acknowledgementWriteError: serverError(),
    })

    await todo.acknowledge(todo.items.value[0])
    await flushPromises()

    expect(labelsAndCounts(todo)).toEqual([['expiredExtractors', 2]])
    expect(consoleError).toHaveBeenCalled()
  })

  it('acknowledges all the acknowledgeable lines with one post each at their current count and keeps the character line', async () => {
    const todo = await useTodoItemsWith({
      user: todoUser({ doran: INVALID_TOKEN }),
      planetaryStats: [planetaryStats({ expiredExtractors: 2, expiringExtractors: 3 })],
      marketAlerts: [[TRIGGERED_PYERITE_ALERT, TRIGGERED_MEXALLON_ALERT, TRIGGERED_NOCXIUM_ALERT]],
    })

    await todo.acknowledgeAll()
    await flushPromises()

    expect(writes()).toEqual([
      { endpoint: '/me/bridge/acknowledgements', method: 'POST', body: { itemKey: 'expiredExtractors', count: 2 } },
      { endpoint: '/me/bridge/acknowledgements', method: 'POST', body: { itemKey: 'expiringExtractors', count: 3 } },
      { endpoint: '/me/bridge/acknowledgements', method: 'POST', body: { itemKey: 'triggeredPriceAlerts', count: 3 } },
    ])
    expect(labelsAndCounts(todo)).toEqual([['charactersToReconnect', 1]])
  })
})

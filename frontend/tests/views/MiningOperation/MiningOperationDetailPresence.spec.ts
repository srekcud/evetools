import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import type { MiningOperation, MiningOperationMember } from '@/stores/miningOperation'
import { DETAIL_PATH, LEDGER_PATH, MEMBERS, MEMBERS_PATH, OPERATION_O, ledger, spodumainOperation, startedSpodumainOperation } from './miningOperationLedgerFixtures'
import { VALUATION_PATH, unmeasuredValuation } from './miningOperationValuationFixtures'
import { answerWithoutFleetStorage } from './miningOperationFleetStorageFixtures'

const PRESENCE_PATH = `/mining-operations/${OPERATION_O}/presence`
const HEARTBEAT_MILLISECONDS = 30_000
const MEMBERS_RELOAD_MILLISECONDS = 60_000

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: OPERATION_O }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const events = reactive<Record<string, MiningOperationEvent | null>>({})

let operation: MiningOperation
let members: MiningOperationMember[]

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

async function mountDetail(locale = 'en') {
  vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockImplementation((operationId: string) => events[operationId] ?? null)
  const wrapper = mount(MiningOperationDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

async function advance(milliseconds: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(milliseconds)
  await flushPromises()
}

async function receivePresenceChanged(): Promise<void> {
  events[OPERATION_O] = { action: 'presence_changed', data: { memberId: 'member-brann' }, timestamp: '2026-11-05T20:05:00+00:00' }
  await nextTick()
  await flushPromises()
}

function rowOf(wrapper: ReturnType<typeof mount>, characterName: string): string {
  const row = wrapper.findAll('li').find(item => item.find('span').exists() && item.find('span').text() === characterName)
  return row?.text() ?? ''
}

beforeEach(() => {
  vi.useFakeTimers()
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  operation = startedSpodumainOperation()
  members = MEMBERS
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
    if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
    if (endpoint === MEMBERS_PATH) return members as never
    if (endpoint === LEDGER_PATH) return ledger() as never
    if (endpoint === VALUATION_PATH) return unmeasuredValuation('in_progress') as never
    if (endpoint === PRESENCE_PATH) return operation as never
    return operation as never
  })
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, presence', () => {
  it('sends a heartbeat when the page of an operation in progress opens, then every 30 seconds', async () => {
    await mountDetail()
    expect(callsTo(PRESENCE_PATH)).toBe(1)

    await advance(HEARTBEAT_MILLISECONDS)
    await advance(HEARTBEAT_MILLISECONDS)

    expect(callsTo(PRESENCE_PATH)).toBe(3)
  })

  it('posts the heartbeat without any member identifier', async () => {
    await mountDetail()

    const heartbeat = vi.mocked(apiRequest).mock.calls.find(call => call[0] === PRESENCE_PATH)

    expect(heartbeat?.[1]).toMatchObject({ method: 'POST' })
  })

  it('stops the heartbeat when the page is closed', async () => {
    const wrapper = await mountDetail()
    expect(callsTo(PRESENCE_PATH)).toBe(1)

    wrapper.unmount()
    await advance(HEARTBEAT_MILLISECONDS * 3)

    expect(callsTo(PRESENCE_PATH)).toBe(1)
  })

  it('sends no heartbeat while the operation is planned', async () => {
    operation = spodumainOperation()

    await mountDetail()
    await advance(HEARTBEAT_MILLISECONDS)

    expect(callsTo(PRESENCE_PATH)).toBe(0)
  })

  it('sends no heartbeat once the participant left early', async () => {
    operation = startedSpodumainOperation({ myRole: 'member', myDepartedAt: '2026-11-05T21:05:00+00:00' })

    await mountDetail()
    await advance(HEARTBEAT_MILLISECONDS)

    expect(callsTo(PRESENCE_PATH)).toBe(0)
  })

  it('reloads the members and not the operation on presence_changed', async () => {
    await mountDetail()
    vi.mocked(apiRequest).mockClear()

    await receivePresenceChanged()

    expect([callsTo(MEMBERS_PATH), callsTo(DETAIL_PATH)]).toEqual([1, 0])
  })

  it('reloads the members every 60 seconds to catch a participant going AFK', async () => {
    await mountDetail()
    vi.mocked(apiRequest).mockClear()

    await advance(MEMBERS_RELOAD_MILLISECONDS)

    expect(callsTo(MEMBERS_PATH)).toBe(1)
  })

  it('shows the presence of each accepted participant of an operation in progress', async () => {
    members = [
      { ...MEMBERS[0], presence: 'online', presenceIdleMinutes: 0 },
      { ...MEMBERS[1], presence: 'afk', presenceIdleMinutes: 12 },
      { ...MEMBERS[0], id: 'member-cyra', characterName: 'Cyra Lune', presence: 'left', presenceIdleMinutes: null },
      { ...MEMBERS[0], id: 'member-dorn', characterName: 'Dorn Achar', presence: 'unknown', presenceIdleMinutes: null },
    ]

    const wrapper = await mountDetail('fr')

    expect([
      rowOf(wrapper, 'Aria Solberg').includes('En ligne'),
      rowOf(wrapper, 'Brann Holt').includes('AFK · 12 min'),
      rowOf(wrapper, 'Cyra Lune').includes('Parti'),
      rowOf(wrapper, 'Dorn Achar').includes('Pas encore vu'),
    ]).toEqual([true, true, true, true])
  })
})

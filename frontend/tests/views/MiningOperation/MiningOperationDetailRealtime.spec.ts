import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import type { MiningOperation, MiningOperationLedger, MiningOperationMember } from '@/stores/miningOperation'
import { VALUATION_PATH, unmeasuredValuation } from './miningOperationValuationFixtures'
import { answerWithoutFleetStorage } from './miningOperationFleetStorageFixtures'

const OPERATION_O = '4d2f8a61-3b7c-4e95-a0d8-6c1e9f2b7a53'
const OPERATION_P2 = 'b7e4c2a9-1d63-4f58-8a0e-5c9d3f7b2e14'
const DETAIL_PATH = `/mining-operations/${OPERATION_O}`
const MEMBERS_PATH = `/mining-operations/${OPERATION_O}/members`
const LEDGER_PATH = `/mining-operations/${OPERATION_O}/ledger`

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: OPERATION_O }, query: {} })
const push = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push, replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SPODUMAIN: MiningOperation = {
  id: OPERATION_O,
  label: 'Anomalie Spodumain — 20:00',
  plannedStartAt: '2026-11-05T20:00:00+00:00',
  status: 'planned',
  visibility: 'corporation',
  splitMode: 'value_prorata',
  corporationId: 98_262_101,
  ownerCharacterName: 'Aria Solberg',
  participantsCount: 2,
  charactersCount: 4,
  joinCode: '7f3a9c2e1b',
  myRole: 'owner',
  myStatus: 'accepted',
  createdAt: '2026-11-03T18:00:00+00:00',
  startedAt: null,
  endedAt: null,
  solarSystemId: null,
  solarSystemName: null,
  myDepartedAt: null,
  valuationSettings: {
    valuationForm: 'compressed',
    valuationMarket: 'jita',
    valuationSide: 'buy',
    structureId: null,
    structureName: null,
    reprocessYield: null,
    boosterCutPercent: 0,
    corporationCutPercent: 0,
    boosterMemberId: null,
    boosterCharacterName: null,
  },
  sharesFrozenAt: null,
  archivedAt: null,
  archivedByName: null,
  archiveSummary: null,
  fleetStorageRecipientName: null,
  recurrenceId: null,
  chunkId: null,
}

const MEMBERS: MiningOperationMember[] = [
  {
    id: 'member-aria',
    characterName: 'Aria Solberg',
    corporationId: 98_262_101,
    role: 'owner',
    status: 'accepted',
    joinedAt: '2026-11-03T18:00:00+00:00',
    departedAt: null,
    presence: null,
    presenceIdleMinutes: null,
    characters: [{ id: 'character-aria', eveCharacterId: 2_112_262_102, name: 'Aria Solberg' }],
  },
]

const PLANNED_LEDGER: MiningOperationLedger = {
  operationId: OPERATION_O,
  status: 'planned',
  startedAt: null,
  endedAt: null,
  solarSystemId: null,
  solarSystemName: null,
  endSnapshotsReadyAt: null,
  endSnapshotsPending: false,
  endSnapshotsSettled: 0,
  endSnapshotsTotal: 0,
  participants: [],
}

const events = reactive<Record<string, MiningOperationEvent | null>>({})

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

async function mountDetail() {
  vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockImplementation((operationId: string) => events[operationId] ?? null)
  const wrapper = mount(MiningOperationDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, teleport: true },
    },
  })
  await flushPromises()
  vi.mocked(apiRequest).mockClear()
  return wrapper
}

async function receive(operationId: string, action: string): Promise<void> {
  events[operationId] = { action, data: { memberId: 'member-cyra', status: 'pending' }, timestamp: '2026-11-04T09:30:00+00:00' }
  await nextTick()
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  push.mockReset()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
    if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
    if (endpoint === MEMBERS_PATH) return MEMBERS as never
    if (endpoint === LEDGER_PATH) return PLANNED_LEDGER as never
    if (endpoint === VALUATION_PATH) return unmeasuredValuation('planned') as never
    return SPODUMAIN as never
  })
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, live updates', () => {
  it('reloads the operation and its members on each member_joined of the displayed operation', async () => {
    await mountDetail()

    await receive(OPERATION_O, 'member_joined')
    await receive(OPERATION_O, 'member_joined')

    expect([callsTo(DETAIL_PATH), callsTo(MEMBERS_PATH)]).toEqual([2, 2])
  })

  it('reloads nothing on an event of another operation', async () => {
    await mountDetail()

    await receive(OPERATION_P2, 'member_joined')

    expect(apiRequest).not.toHaveBeenCalled()
  })

  it('reloads the operation and its members when the join code is renewed', async () => {
    await mountDetail()

    await receive(OPERATION_O, 'join_code_renewed')

    expect([callsTo(DETAIL_PATH), callsTo(MEMBERS_PATH)]).toEqual([1, 1])
  })

  it('goes back to the list once the displayed operation is deleted', async () => {
    await mountDetail()

    await receive(OPERATION_O, 'operation_deleted')

    expect(push).toHaveBeenCalledWith(expect.objectContaining({ name: 'mining-operations' }))
  })
})

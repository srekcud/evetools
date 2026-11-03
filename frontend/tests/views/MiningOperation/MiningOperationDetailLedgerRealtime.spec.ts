import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type MiningOperationEvent, type SyncProgress } from '@/stores/sync'
import { DETAIL_PATH, LEDGER_PATH, MEMBERS, MEMBERS_PATH, OPERATION_O, OPERATION_P2, ledger, startedSpodumainOperation } from './miningOperationLedgerFixtures'
import { VALUATION_PATH, unmeasuredValuation } from './miningOperationValuationFixtures'

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

const SNAPSHOT_SYNC_TYPE = 'mining-operation-snapshot'

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

async function receiveOperationEvent(action: string): Promise<void> {
  events[OPERATION_O] = { action, data: {}, timestamp: '2026-11-05T21:05:00+00:00' }
  await nextTick()
  await flushPromises()
}

async function receiveSnapshotProgress(operationId: string, status: SyncProgress['status'], characterName: string): Promise<void> {
  const syncStore = useSyncStore()
  syncStore.syncStatus = {
    ...syncStore.syncStatus,
    [SNAPSHOT_SYNC_TYPE]: {
      syncType: SNAPSHOT_SYNC_TYPE,
      status,
      progress: 100,
      message: null,
      data: { operationId, operationCharacterId: `enrolment-${characterName}`, characterName, kind: 'start', status: 'taken', readyAt: '2026-11-05T20:00:00+00:00', settled: 1, total: 4 },
      timestamp: '2026-11-05T20:00:03+00:00',
    },
  }
  await nextTick()
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === MEMBERS_PATH) return MEMBERS as never
    if (endpoint === LEDGER_PATH) return ledger() as never
    if (endpoint === VALUATION_PATH) return unmeasuredValuation('in_progress') as never
    return startedSpodumainOperation() as never
  })
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, live ledger', () => {
  it('reloads the ledger once for a snapshot of the displayed operation and not for another operation', async () => {
    await mountDetail()

    await receiveSnapshotProgress(OPERATION_O, 'completed', 'Brann Holt')
    await receiveSnapshotProgress(OPERATION_P2, 'completed', 'Cyra Lune')

    expect([callsTo(LEDGER_PATH), callsTo(DETAIL_PATH)]).toEqual([1, 0])
  })

  it.each(['operation_started', 'operation_ended', 'member_departed', 'quantity_corrected'])('reloads the operation, its members and its ledger on %s', async action => {
    await mountDetail()

    await receiveOperationEvent(action)

    expect([callsTo(DETAIL_PATH), callsTo(MEMBERS_PATH), callsTo(LEDGER_PATH)]).toEqual([1, 1, 1])
  })
})

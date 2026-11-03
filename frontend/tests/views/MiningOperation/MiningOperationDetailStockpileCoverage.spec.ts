import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import type { MiningOperation, MiningOperationStatus } from '@/stores/miningOperation'
import { COVERAGE_PATH, ISOGEN_TYPE_ID, MEXALLON_TYPE_ID, PYERITE_TYPE_ID, TRITANIUM_TYPE_ID, stockpileCoverage } from '../../components/mining-operation/stockpileCoverageHarness'
import { DETAIL_PATH, ENDED_AT, LEDGER_PATH, MEMBERS, MEMBERS_PATH, OPERATION_O, ledger, spodumainOperation, startedSpodumainOperation } from './miningOperationLedgerFixtures'
import { answerWithoutFleetStorage } from './miningOperationFleetStorageFixtures'

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

function operationIn(status: MiningOperationStatus, overrides: Partial<MiningOperation> = {}): MiningOperation {
  if (status === 'planned') return spodumainOperation(overrides)
  if (status === 'in_progress') return startedSpodumainOperation(overrides)
  return startedSpodumainOperation({ status, endedAt: ENDED_AT, ...overrides })
}

function answer(operation: MiningOperation): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
    if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return MEMBERS as never
    if (endpoint === LEDGER_PATH) return ledger({ status: operation.status, endedAt: operation.endedAt }) as never
    if (endpoint === COVERAGE_PATH) return stockpileCoverage() as never
    throw new Error(`unexpected request to ${endpoint}`)
  })
}

function coverageRequests(): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === COVERAGE_PATH).length
}

async function mountDetail() {
  vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockImplementation((operationId: string) => events[operationId] ?? null)
  const wrapper = mount(MiningOperationDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, teleport: true, RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, Stockpile coverage', () => {
  it.each(['closing', 'completed'] as const)('shows the Stockpile coverage of the viewer once the operation is %s', async status => {
    answer(operationIn(status))

    const wrapper = await mountDetail()

    expect(coverageRequests()).toBe(1)
    expect(wrapper.find('[data-stockpile-coverage]').exists()).toBe(true)
    expect(wrapper.findAll('[data-coverage-mineral]').map(row => row.attributes('data-coverage-mineral'))).toEqual([TRITANIUM_TYPE_ID, PYERITE_TYPE_ID, MEXALLON_TYPE_ID, ISOGEN_TYPE_ID].map(String))
  })

  it.each(['planned', 'in_progress'] as const)('neither requests nor shows any Stockpile coverage while the operation is %s', async status => {
    answer(operationIn(status))

    const wrapper = await mountDetail()

    expect(coverageRequests()).toBe(0)
    expect(wrapper.find('[data-stockpile-coverage]').exists()).toBe(false)
  })

  it('neither requests nor shows any Stockpile coverage to a pilot who does not participate', async () => {
    answer(operationIn('closing', { myRole: 'member', myStatus: 'pending' }))

    const wrapper = await mountDetail()

    expect(coverageRequests()).toBe(0)
    expect(wrapper.find('[data-stockpile-coverage]').exists()).toBe(false)
  })

  it('reloads the Stockpile coverage once when a quantity is corrected', async () => {
    answer(operationIn('closing'))
    await mountDetail()
    vi.mocked(apiRequest).mockClear()

    events[OPERATION_O] = { action: 'quantity_corrected', data: {}, timestamp: '2026-11-05T21:52:00+00:00' }
    await nextTick()
    await flushPromises()

    expect(coverageRequests()).toBe(1)
  })
})

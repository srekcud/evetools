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
import { COVERAGE_PATH, stockpileCoverage } from '../../components/mining-operation/stockpileCoverageHarness'
import { PRICED_SALE } from '../../components/ledger/oreSaleHarness'
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

const ORE_SALE_PATH = `/mining-operations/${OPERATION_O}/ore-sale`

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
    if (endpoint === ORE_SALE_PATH) return PRICED_SALE as never
    throw new Error(`unexpected request to ${endpoint}`)
  })
}

function oreSaleRequests(): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === ORE_SALE_PATH).length
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

describe('MiningOperationDetail, Sell my ore', () => {
  it.each(['closing', 'completed'] as const)('shows the ore sale of the viewer once the operation is %s', async status => {
    answer(operationIn(status))

    const wrapper = await mountDetail()

    expect(oreSaleRequests()).toBe(1)
    expect(wrapper.find('[data-ore-sale]').exists()).toBe(true)
  })

  it.each(['planned', 'in_progress'] as const)('neither requests nor shows any ore sale while the operation is %s', async status => {
    answer(operationIn(status))

    const wrapper = await mountDetail()

    expect(oreSaleRequests()).toBe(0)
    expect(wrapper.find('[data-ore-sale]').exists()).toBe(false)
  })
})

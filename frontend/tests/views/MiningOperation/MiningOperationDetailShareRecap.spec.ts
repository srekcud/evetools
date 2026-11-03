import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import type { MiningOperation, MiningOperationLedger, MiningOperationValuation } from '@/stores/miningOperation'
import { DETAIL_PATH, LEDGER_PATH, MEMBERS_PATH, OPERATION_O } from './miningOperationLedgerFixtures'
import { MEMBERS_WITH_CYRA, VALUATION_PATH } from './miningOperationValuationFixtures'
import { PAYMENTS_PATH } from './miningOperationPaymentFixtures'
import { answerWithoutFleetStorage } from './miningOperationFleetStorageFixtures'
import {
  PROPORTIONAL_RECAP_LINES,
  minedLedger,
  recapOperation,
  recapOperationEachForThemselves,
  recapValuation,
  recapValuationEachForThemselves,
} from './miningOperationShareRecapFixtures'

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

const COPY_TEXT = '[data-test="copy-recap-text"]'
const RECAP_TEXT = '[data-test="recap-text"]'

function answer(operation: MiningOperation, valuation: MiningOperationValuation, operationLedger: MiningOperationLedger): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
    if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return MEMBERS_WITH_CYRA as never
    if (endpoint === LEDGER_PATH) return operationLedger as never
    if (endpoint === VALUATION_PATH) return valuation as never
    if (endpoint === PAYMENTS_PATH) return [] as never
    return [] as never
  })
}

async function mountDetail() {
  vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockReturnValue(null)
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
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, summary to share', () => {
  it('offers the summary text to an accepted member once the shares are frozen', async () => {
    answer(recapOperation(), recapValuation({ frozen: true }), minedLedger())

    const wrapper = await mountDetail()

    expect(wrapper.find(COPY_TEXT).exists()).toBe(true)
    expect(wrapper.find(RECAP_TEXT).element.textContent).toBe(PROPORTIONAL_RECAP_LINES.join('\n'))
  })

  it('shows no summary while the operation is in progress, shows it while closing and once an each for themselves operation is completed', async () => {
    answer(recapOperation({ status: 'in_progress', endedAt: null }), recapValuation({ status: 'in_progress' }), minedLedger([], { status: 'in_progress', endedAt: null }))
    const inProgress = await mountDetail()

    answer(recapOperation({ status: 'closing' }), recapValuation({ status: 'closing' }), minedLedger([], { status: 'closing' }))
    setActivePinia(createPinia())
    const closing = await mountDetail()

    answer(recapOperationEachForThemselves(), recapValuationEachForThemselves(), minedLedger())
    setActivePinia(createPinia())
    const completed = await mountDetail()

    expect([inProgress, closing, completed].map(wrapper => wrapper.find(COPY_TEXT).exists())).toEqual([false, true, true])
    expect(completed.find(RECAP_TEXT).element?.textContent).toContain('evetools · each for themselves')
  })
})

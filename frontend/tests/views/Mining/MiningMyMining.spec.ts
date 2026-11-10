import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { MiningSeason } from '@/stores/miningOperation'
import type { User } from '@/types'
import MiningMyMining from '@/views/Mining/MiningMyMining.vue'
import { respondWith } from '../../components/ledger/miningLedgerHarness'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const STORED_PERIOD_DAYS = 60
const CHOSEN_PERIOD_DAYS = 90
const STORED_STRUCTURE = { id: 1_035_000_000_007, name: 'Jita 4-4 - Caldari Navy Assembly Plant' }
const STORED_EXPORT_TAX = '800'
const SEASON_PATH_30_DAYS = '/mining-operations/season?period=30d'
const SEASON_OF_ONE_KNOWN_OPERATION: MiningSeason = {
  period: '30d',
  operationsCount: 1,
  unknownValueOperationsCount: 0,
  knownValue: 150_000_000,
  knownOperationMinutes: 180,
  playedMinutes: 180,
  iskPerHour: 50_000_000,
  players: [{ name: 'Aria Solberg', operationsCount: 1, minutes: 180, minedValue: 150_000_000, hasUnknownValue: false }],
  ships: [],
  unknownShipCharactersCount: 0,
  sitesBySystem: [],
  sitesWithoutSystemCount: 0,
}

const ARIA_OF_C1 = {
  id: '00000000-0000-4000-8000-000000000531',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [],
  corporationId: 98_262_101,
  corporationName: 'Corporation 98262101',
} as User

async function mountMyMining(): Promise<VueWrapper> {
  useAuthStore().user = ARIA_OF_C1
  const wrapper = mount(MiningMyMining, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

function miningLedgerRequests(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(call => String(call[0])).filter(endpoint => endpoint.startsWith('/ledger/mining/')).sort()
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningMyMining', () => {
  it('starts on the period already chosen in the ledger, requests the lines without structure or export tax, and stores the period it is given without rewriting the settings of the browser', async () => {
    localStorage.setItem('ledgerSelectedDays', String(STORED_PERIOD_DAYS))
    localStorage.setItem('miningSelectedStructure', JSON.stringify(STORED_STRUCTURE))
    localStorage.setItem('miningExportTax', STORED_EXPORT_TAX)
    respondWith(() => new Promise(() => {}), () => new Promise(() => {}))
    const wrapper = await mountMyMining()
    const requestsAtStart = miningLedgerRequests()
    vi.mocked(apiRequest).mockClear()

    const chosen = wrapper.findAll('button').find(button => button.text() === `${CHOSEN_PERIOD_DAYS} days`)
    expect(chosen, 'period selector').toBeDefined()
    await chosen?.trigger('click')
    await flushPromises()

    expect({
      requestsAtStart,
      requestsAfterChoice: miningLedgerRequests(),
      storedPeriod: localStorage.getItem('ledgerSelectedDays'),
      storedStructure: localStorage.getItem('miningSelectedStructure'),
      storedExportTax: localStorage.getItem('miningExportTax'),
    }).toEqual({
      requestsAtStart: [
        `/ledger/mining/entries?days=${STORED_PERIOD_DAYS}`,
        `/ledger/mining/stats?days=${STORED_PERIOD_DAYS}`,
      ],
      requestsAfterChoice: [
        `/ledger/mining/entries?days=${CHOSEN_PERIOD_DAYS}`,
        `/ledger/mining/stats?days=${CHOSEN_PERIOD_DAYS}`,
      ],
      storedPeriod: String(CHOSEN_PERIOD_DAYS),
      storedStructure: JSON.stringify(STORED_STRUCTURE),
      storedExportTax: STORED_EXPORT_TAX,
    })
  })

  it('opens the season on its tab, which loads the season of the last 30 days and shows its indicators', async () => {
    route.query = { tab: 'season' }
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === SEASON_PATH_30_DAYS) return SEASON_OF_ONE_KNOWN_OPERATION as never
      return [] as never
    })

    const wrapper = await mountMyMining()

    expect([vi.mocked(apiRequest).mock.calls.filter(call => call[0] === SEASON_PATH_30_DAYS).length > 0, wrapper.text().includes('150,000,000')]).toEqual([true, true])
  })
})

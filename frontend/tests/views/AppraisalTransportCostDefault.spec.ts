import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Appraisal from '@/views/Appraisal.vue'
import { apiRequest, authFetch } from '@/services/api'
import { useAppraisalHandoffStore } from '@/stores/appraisalHandoff'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

const SETTINGS_ENDPOINT = '/industry/settings'
const PARSE_ENDPOINT = '/api/shopping-list/parse'
const APPRAISE_ENDPOINT = '/api/shopping-list/appraise'
const SAVED_RATE_PER_M3 = 1250
const DEFAULT_RATE_PER_M3 = 800
const HANDED_OVER_TEXT = 'Tritanium\t1250000'

const APPRAISAL_RESPONSE = {
  items: [
    {
      typeId: 34, typeName: 'Tritanium', quantity: 1_250_000, volume: 0.01, totalVolume: 12_500,
      sellPrice: 4.12, sellTotal: 5_150_000, buyPrice: 3.95, buyTotal: 4_937_500,
      sellPriceWeighted: null, sellTotalWeighted: null, buyPriceWeighted: null, buyTotalWeighted: null,
      sellCoverage: null, buyCoverage: null, avgDailyVolume: 9_800_000,
    },
  ],
  notFound: [],
  totals: { sellTotal: 5_150_000, buyTotal: 4_937_500, volume: 12_500, sellTotalWeighted: null, buyTotalWeighted: null },
  priceError: null,
  missingVolumeTypeIds: [],
}

const SHOPPING_RESPONSE = {
  items: [
    {
      typeId: 34, typeName: 'Tritanium', quantity: 1_250_000, totalVolume: 12_500,
      jitaTotal: 5_150_000, jitaWithImport: 5_250_000, structureTotal: 5_300_000, structureUnitPrice: 4.24, bestLocation: 'jita',
    },
  ],
  notFound: [],
  totals: { jitaWithImport: 5_250_000, structure: 5_300_000, best: 5_250_000, volume: 12_500 },
  transportCostPerM3: 800,
  structureId: null,
  structureName: null,
  priceError: null,
  structureAccessible: true,
  structureFromCache: false,
  structureLastSync: null,
}

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

async function mountAppraisal(): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Appraisal, {
    global: {
      plugins: [i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        ErrorBanner: true,
        AppraisalInputPanel: true,
        AppraisalResultsTable: true,
        AppraisalActions: true,
        MissingVolumeWarning: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function transportCostInput(wrapper: VueWrapper): HTMLInputElement {
  return wrapper.get('input[type="number"]').element as HTMLInputElement
}

function parseRequestTransportCost(): number {
  const call = vi.mocked(authFetch).mock.calls.find(([input]) => String(input) === PARSE_ENDPOINT)
  return JSON.parse(String(call?.[1]?.body)).transportCost
}

function stubSettings(exportCostPerM3: number): void {
  vi.mocked(apiRequest).mockImplementation(async (path: string) => {
    if (path === SETTINGS_ENDPOINT) return { exportCostPerM3 }
    return []
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  useAppraisalHandoffStore().send(HANDED_OVER_TEXT)
  vi.mocked(apiRequest).mockReset()
  vi.mocked(authFetch).mockReset()
  vi.mocked(authFetch).mockImplementation(async (input: RequestInfo | URL) => {
    if (String(input) === APPRAISE_ENDPOINT) return jsonResponse(APPRAISAL_RESPONSE)
    return jsonResponse(SHOPPING_RESPONSE)
  })
})

describe('Appraisal transport cost default', () => {
  it('starts from the saved Jita to capital rate of the user', async () => {
    stubSettings(SAVED_RATE_PER_M3)

    const wrapper = await mountAppraisal()

    expect(transportCostInput(wrapper).value).toBe(String(SAVED_RATE_PER_M3))
  })

  it('starts from 800 ISK/m³ when the settings cannot be read', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('settings unavailable'))
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    const wrapper = await mountAppraisal()

    expect(transportCostInput(wrapper).value).toBe(String(DEFAULT_RATE_PER_M3))
  })

  it('lets the user change the rate for a request', async () => {
    stubSettings(SAVED_RATE_PER_M3)
    const wrapper = await mountAppraisal()

    await wrapper.get('input[type="number"]').setValue('1500')

    expect(transportCostInput(wrapper).value).toBe('1500')
  })

  it('appraises a handed over text with the saved rate', async () => {
    stubSettings(SAVED_RATE_PER_M3)

    await mountAppraisal()

    expect(parseRequestTransportCost()).toBe(SAVED_RATE_PER_M3)
  })
})

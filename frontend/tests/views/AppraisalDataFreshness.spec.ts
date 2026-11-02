import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Appraisal from '@/views/Appraisal.vue'
import AppraisalInputPanel from '@/components/appraisal/AppraisalInputPanel.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import { ApiError, apiRequest, authFetch } from '@/services/api'
import { rejectWith, respondWith, type ApiOutcome } from '../deferred'
import {
  DATA_FRESHNESS_ENDPOINT,
  JITA_PRICES_42_MIN_AGO_FRESH,
  JITA_PRICES_UNKNOWN,
  NOW,
  TYPICAL_DATA_FRESHNESS_RESPONSE,
  dataFreshnessRequestCount,
  shownFreshness,
} from '../dataFreshnessResponses'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

const PASTED_ITEMS = 'Tritanium 12000\nPyerite 4500'
const STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon'
const SHORT_STRUCTURE_NAME = 'C-J6MT'

let dataFreshnessOutcome: ApiOutcome

const APPRAISAL_RESPONSE = {
  items: [
    {
      typeId: 34, typeName: 'Tritanium', quantity: 12_000, volume: 0.01, totalVolume: 120,
      sellPrice: 4.12, sellTotal: 49_440, buyPrice: 3.95, buyTotal: 47_400,
      sellPriceWeighted: null, sellTotalWeighted: null, buyPriceWeighted: null, buyTotalWeighted: null,
      sellCoverage: null, buyCoverage: null, avgDailyVolume: 9_800_000,
    },
    {
      typeId: 35, typeName: 'Pyerite', quantity: 4_500, volume: 0.01, totalVolume: 45,
      sellPrice: 11.6, sellTotal: 52_200, buyPrice: 10.8, buyTotal: 48_600,
      sellPriceWeighted: null, sellTotalWeighted: null, buyPriceWeighted: null, buyTotalWeighted: null,
      sellCoverage: null, buyCoverage: null, avgDailyVolume: 3_100_000,
    },
  ],
  notFound: [],
  totals: { sellTotal: 101_640, buyTotal: 96_000, volume: 165, sellTotalWeighted: null, buyTotalWeighted: null },
  priceError: null,
  missingVolumeTypeIds: [],
}

const SHOPPING_RESPONSE = {
  items: [
    { typeId: 34, typeName: 'Tritanium', quantity: 12_000, totalVolume: 120, jitaTotal: 49_440, jitaWithImport: 193_440, structureTotal: 61_200, structureUnitPrice: 5.1, bestLocation: 'structure' },
    { typeId: 35, typeName: 'Pyerite', quantity: 4_500, totalVolume: 45, jitaTotal: 52_200, jitaWithImport: 106_200, structureTotal: null, structureUnitPrice: null, bestLocation: 'jita' },
  ],
  notFound: [],
  totals: { jitaWithImport: 299_640, structure: 61_200, best: 167_400, volume: 165 },
  transportCostPerM3: 1_200,
  structureId: 1_035_466_617_946,
  structureName: STRUCTURE_NAME,
  priceError: null,
  structureAccessible: true,
  structureFromCache: true,
  structureLastSync: '2026-10-31T11:40:00+00:00',
}

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

async function mountAppraisedWithStructure(): Promise<VueWrapper> {
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
  const inputPanel = wrapper.findComponent(AppraisalInputPanel)
  inputPanel.vm.$emit('update:input-text', PASTED_ITEMS)
  await flushPromises()
  inputPanel.vm.$emit('analyze')
  await flushPromises()
  return wrapper
}

function structurePricesLine(wrapper: VueWrapper) {
  const line = wrapper.findAll('span').find(candidate => candidate.text().startsWith(`${SHORT_STRUCTURE_NAME} ${en.appraisal.pricesUpdated}`))
  if (!line) throw new Error('No structure prices line')
  return line.element.parentElement!
}

function freshnessNextToStructurePricesLine(wrapper: VueWrapper) {
  return Array.from(structurePricesLine(wrapper).querySelectorAll('[data-testid="data-freshness"]')).map(badge => ({
    label: badge.textContent?.trim(),
    level: badge.getAttribute('data-level') ?? undefined,
  }))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  vi.setSystemTime(new Date(NOW))
  setActivePinia(createPinia())
  dataFreshnessOutcome = respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE)
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === DATA_FRESHNESS_ENDPOINT) return dataFreshnessOutcome()
    throw new Error(`Unexpected request ${endpoint}`)
  })
  vi.mocked(authFetch).mockReset()
  vi.mocked(authFetch).mockImplementation(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url === '/api/shopping-list/appraise') return jsonResponse(APPRAISAL_RESPONSE)
    if (url === '/api/shopping-list/parse') return jsonResponse(SHOPPING_RESPONSE)
    throw new Error(`Unexpected request ${url}`)
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('Appraisal data freshness (issue 292)', () => {
  it('W7: shows the Jita prices freshness next to the structure prices line, read once', async () => {
    const wrapper = await mountAppraisedWithStructure()

    expect({
      requests: dataFreshnessRequestCount(vi.mocked(apiRequest).mock.calls),
      nextToStructurePricesLine: freshnessNextToStructurePricesLine(wrapper),
      shown: shownFreshness(wrapper),
    }).toEqual({
      requests: 1,
      nextToStructurePricesLine: [JITA_PRICES_42_MIN_AGO_FRESH],
      shown: [JITA_PRICES_42_MIN_AGO_FRESH],
    })
  })

  it('W3: shows the Jita prices with an unknown date next to the structure prices line and no error banner when the endpoint fails', async () => {
    dataFreshnessOutcome = rejectWith(new ApiError('API error: 500', 500))

    const wrapper = await mountAppraisedWithStructure()

    expect({
      nextToStructurePricesLine: freshnessNextToStructurePricesLine(wrapper),
      shown: shownFreshness(wrapper),
      errorBannerShown: wrapper.findComponent(ErrorBanner).exists(),
    }).toEqual({
      nextToStructurePricesLine: [JITA_PRICES_UNKNOWN],
      shown: [JITA_PRICES_UNKNOWN],
      errorBannerShown: false,
    })
  })
})

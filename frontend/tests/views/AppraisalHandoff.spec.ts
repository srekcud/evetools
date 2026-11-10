import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Appraisal from '@/views/Appraisal.vue'
import AppraisalInputPanel from '@/components/appraisal/AppraisalInputPanel.vue'
import { apiRequest, authFetch } from '@/services/api'
import { useAppraisalHandoffStore } from '@/stores/appraisalHandoff'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

const SENT_TEXT = 'Tritanium\t1250000\nMorphite\t37'
const APPRAISE_ENDPOINT = '/api/shopping-list/appraise'

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

function appraiseCallCount(): number {
  return vi.mocked(authFetch).mock.calls.filter(([input]) => String(input) === APPRAISE_ENDPOINT).length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue([])
  vi.mocked(authFetch).mockReset()
  vi.mocked(authFetch).mockImplementation(async (input: RequestInfo | URL) => {
    if (String(input) === APPRAISE_ENDPOINT) return jsonResponse(APPRAISAL_RESPONSE)
    return jsonResponse({ items: [], notFound: [], totals: { jitaWithImport: 0, structure: 0, best: 0, volume: 0 }, transportCostPerM3: 1200, structureId: null, structureName: null, priceError: null, structureAccessible: true, structureFromCache: false, structureLastSync: null })
  })
})

describe('Appraisal opened with a text handed over', () => {
  it('fills the input with the handed over text and runs the appraisal once', async () => {
    useAppraisalHandoffStore().send(SENT_TEXT)

    const wrapper = await mountAppraisal()

    expect(wrapper.findComponent(AppraisalInputPanel).props('inputText')).toBe(SENT_TEXT)
    expect(appraiseCallCount()).toBe(1)
    expect(useAppraisalHandoffStore().pendingText).toBeNull()
  })

  it('opens empty and runs no appraisal when nothing was handed over', async () => {
    const wrapper = await mountAppraisal()

    expect(wrapper.findComponent(AppraisalInputPanel).props('inputText')).toBe('')
    expect(appraiseCallCount()).toBe(0)
  })
})

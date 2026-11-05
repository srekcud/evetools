import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import type { ProfitMarginResult, SearchResult, UserSettings } from '@/stores/industry/types'
import { apiOutcomesByPath, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HERON_TYPE_ID = 605
const HERON_ONLY_MATERIAL = 'Isogen'

const HERON: SearchResult = { typeId: HERON_TYPE_ID, typeName: 'Heron', isT2: false }

const USER_SETTINGS: UserSettings = {
  favoriteManufacturingSystemId: null,
  favoriteManufacturingSystemName: null,
  favoriteReactionSystemId: null,
  favoriteReactionSystemName: null,
  exportCostPerM3: 850,
}

const HERON_MARGIN: ProfitMarginResult = {
  typeId: HERON_TYPE_ID,
  typeName: 'Heron',
  isT2: false,
  runs: 1,
  outputQuantity: 1,
  outputPerRun: 1,
  materialCost: 412_600,
  materials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 26_400, unitPrice: 4.12, totalPrice: 108_768 },
    { typeId: 37, typeName: HERON_ONLY_MATERIAL, quantity: 3_150, unitPrice: 96.45, totalPrice: 303_817.5 },
  ],
  jobInstallCost: 18_300,
  jobInstallSteps: [],
  inventionCost: 0,
  totalCost: 430_900,
  costPerUnit: 430_900,
  invention: null,
  sellPrices: {
    jitaSell: 615_000,
    structureSell: 598_000,
    structureBuy: 571_500,
    contractSell: null,
    contractCount: null,
    jitaSellCoveragePercent: null,
    structureId: 1_046_664_001_931,
    structureName: 'Raitaru Heron Works',
  },
  brokerFeeRate: 0.015,
  salesTaxRate: 0.0337,
  margins: {
    jitaSell: { revenue: 615_000, fees: 29_950, profit: 154_150, margin: 25.07 },
  },
  dailyVolume: 54,
  missingData: [],
  warnings: [],
}

const outcomes = apiOutcomesByPath()

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/industry/settings') return USER_SETTINGS
    const answer = outcomes.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

describe('ProfitMarginTab without the browser request time (issue 292)', () => {
  it('W9: shows no last updated time after a successful Heron analysis', async () => {
    outcomes.respondTo(`/industry/types/${HERON_TYPE_ID}`, respondWith(HERON))
    outcomes.respondTo(`/industry/profit-margin/${HERON_TYPE_ID}`, respondWith(HERON_MARGIN))
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })

    const wrapper = mount(ProfitMarginTab, {
      global: { plugins: [i18n], stubs: { ProductSearch: true } },
      props: { initialTypeId: HERON_TYPE_ID },
    })
    await flushPromises()

    expect({
      heronMaterialShown: wrapper.text().includes(HERON_ONLY_MATERIAL),
      lastUpdatedShown: wrapper.text().includes(en.industry.costEstimation.lastUpdated),
    }).toEqual({ heronMaterialShown: true, lastUpdatedShown: false })
  })
})

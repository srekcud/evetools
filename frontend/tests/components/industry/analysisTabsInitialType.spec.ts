import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { apiRequest } from '@/services/api'
import type { ProfitMarginResult } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROFIT_MARGIN_ENDPOINT = '/industry/profit-margin/'
const BUY_VS_BUILD_ENDPOINT = '/industry/buy-vs-build/'
const PIVOT_ADVISOR_ENDPOINT = '/industry/pivot-advisor/'
const ANALYSIS_ENDPOINTS = [PROFIT_MARGIN_ENDPOINT, BUY_VS_BUILD_ENDPOINT, PIVOT_ADVISOR_ENDPOINT]

const RAVEN_TYPE_ID = 638
const RAVEN_NAVY_ISSUE_TYPE_ID = 17636
const SABRE_TYPE_ID = 22456
const HULK_TYPE_ID = 22544
const DRAKE_TYPE_ID = 24698
const ROKH_TYPE_ID = 24688

const RAVEN_MARGIN_ANALYSIS: ProfitMarginResult = {
  typeId: RAVEN_TYPE_ID,
  typeName: 'Raven',
  isT2: false,
  runs: 1,
  outputQuantity: 1,
  outputPerRun: 1,
  materialCost: 98_400_000,
  materials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 8_200_000, unitPrice: 4.1, totalPrice: 33_620_000 },
    { typeId: 35, typeName: 'Pyerite', quantity: 2_100_000, unitPrice: 30.85, totalPrice: 64_780_000 },
  ],
  jobInstallCost: 1_350_000,
  jobInstallSteps: [],
  inventionCost: null,
  totalCost: 99_750_000,
  costPerUnit: 99_750_000,
  invention: null,
  sellPrices: {
    jitaSell: 121_000_000,
    structureSell: 118_500_000,
    structureBuy: 112_300_000,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.03,
  salesTaxRate: 0.036,
  margins: {
    structureSell: { revenue: 118_500_000, fees: 7_821_000, profit: 10_929_000, margin: 9.22 },
  },
  dailyVolume: 14,
  missingData: [],
  warnings: [],
}

function isAnalysisRequest(endpoint: string): boolean {
  return ANALYSIS_ENDPOINTS.some(prefix => endpoint.startsWith(prefix))
}

function requestedAnalyses(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([endpoint]) => endpoint)
    .filter(isAnalysisRequest)
    .map(endpoint => endpoint.split('?')[0])
}

function mountingOptions() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return { global: { plugins: [i18n], stubs: { ProductSearch: true } } }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (isAnalysisRequest(endpoint)) {
      return new Promise(() => {})
    }
    return null
  })
})

describe('ProfitMarginTab initial type', () => {
  it('analyzes the margin of the initial type at mount', async () => {
    mount(ProfitMarginTab, { ...mountingOptions(), props: { initialTypeId: RAVEN_TYPE_ID } })
    await flushPromises()

    expect(requestedAnalyses()).toEqual([`${PROFIT_MARGIN_ENDPOINT}${RAVEN_TYPE_ID}`])
  })

  it('analyzes the margin of a new initial type on a mounted tab', async () => {
    const wrapper = mount(ProfitMarginTab, { ...mountingOptions(), props: { initialTypeId: RAVEN_TYPE_ID } })
    await flushPromises()

    await wrapper.setProps({ initialTypeId: DRAKE_TYPE_ID })
    await flushPromises()

    expect(requestedAnalyses()).toEqual([
      `${PROFIT_MARGIN_ENDPOINT}${RAVEN_TYPE_ID}`,
      `${PROFIT_MARGIN_ENDPOINT}${DRAKE_TYPE_ID}`,
    ])
  })

  it('analyzes nothing without an initial type', async () => {
    mount(ProfitMarginTab, mountingOptions())
    await flushPromises()

    expect(requestedAnalyses()).toEqual([])
  })

  it('shows the initial type as the selected product once its margin is analyzed', async () => {
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint.startsWith(`${PROFIT_MARGIN_ENDPOINT}${RAVEN_TYPE_ID}`)) return RAVEN_MARGIN_ANALYSIS
      return null
    })

    const wrapper = mount(ProfitMarginTab, { ...mountingOptions(), props: { initialTypeId: RAVEN_TYPE_ID } })
    await flushPromises()

    const selectedProductIcon = wrapper.find(`img[alt="${RAVEN_MARGIN_ANALYSIS.typeName}"]`)
    expect(selectedProductIcon.exists()).toBe(true)
    expect(selectedProductIcon.attributes('src')).toContain(`/types/${RAVEN_TYPE_ID}/`)
    expect(wrapper.find(`button[aria-label="${en.common.actions.clear}"]`).exists()).toBe(true)
  })
})

describe('BuyVsBuildTab initial type', () => {
  it('analyzes buy vs build for the initial type at mount', async () => {
    mount(BuyVsBuildTab, { ...mountingOptions(), props: { initialTypeId: RAVEN_NAVY_ISSUE_TYPE_ID } })
    await flushPromises()

    expect(requestedAnalyses()).toEqual([`${BUY_VS_BUILD_ENDPOINT}${RAVEN_NAVY_ISSUE_TYPE_ID}`])
  })

  it('analyzes buy vs build for a new initial type on a mounted tab', async () => {
    const wrapper = mount(BuyVsBuildTab, { ...mountingOptions(), props: { initialTypeId: RAVEN_NAVY_ISSUE_TYPE_ID } })
    await flushPromises()

    await wrapper.setProps({ initialTypeId: HULK_TYPE_ID })
    await flushPromises()

    expect(requestedAnalyses()).toEqual([
      `${BUY_VS_BUILD_ENDPOINT}${RAVEN_NAVY_ISSUE_TYPE_ID}`,
      `${BUY_VS_BUILD_ENDPOINT}${HULK_TYPE_ID}`,
    ])
  })

  it('analyzes nothing without an initial type', async () => {
    mount(BuyVsBuildTab, mountingOptions())
    await flushPromises()

    expect(requestedAnalyses()).toEqual([])
  })
})

describe('PivotAdvisorTab initial type', () => {
  it('analyzes the pivots of the initial type at mount', async () => {
    mount(PivotAdvisorTab, { ...mountingOptions(), props: { initialTypeId: SABRE_TYPE_ID } })
    await flushPromises()

    expect(requestedAnalyses()).toEqual([`${PIVOT_ADVISOR_ENDPOINT}${SABRE_TYPE_ID}`])
  })

  it('analyzes the pivots of a new initial type on a mounted tab', async () => {
    const wrapper = mount(PivotAdvisorTab, { ...mountingOptions(), props: { initialTypeId: SABRE_TYPE_ID } })
    await flushPromises()

    await wrapper.setProps({ initialTypeId: ROKH_TYPE_ID })
    await flushPromises()

    expect(requestedAnalyses()).toEqual([
      `${PIVOT_ADVISOR_ENDPOINT}${SABRE_TYPE_ID}`,
      `${PIVOT_ADVISOR_ENDPOINT}${ROKH_TYPE_ID}`,
    ])
  })

  it('analyzes nothing without an initial type', async () => {
    mount(PivotAdvisorTab, mountingOptions())
    await flushPromises()

    expect(requestedAnalyses()).toEqual([])
  })
})

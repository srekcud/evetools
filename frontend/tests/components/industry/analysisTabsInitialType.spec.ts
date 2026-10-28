import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { apiRequest } from '@/services/api'

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

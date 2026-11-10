import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import { ApiError, apiRequest } from '@/services/api'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'
import { apiOutcomesByPath, rejectWith, respondWith } from '../deferred'
import { industryPageOfTab } from './industryPages'
import {
  ADJUSTED_PRICES_UNKNOWN,
  COST_INDICES_UNKNOWN,
  DATA_FRESHNESS_ENDPOINT,
  JITA_PRICES_UNKNOWN,
  NOW,
  TYPICAL_DATA_FRESHNESS_RESPONSE,
  TYPICAL_RESPONSE_SHOWN,
  dataFreshnessRequestCount,
  shownFreshness,
} from '../dataFreshnessResponses'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const route = reactive<{ query: LocationQuery }>({ query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  IndustryModuleTabs: true,
  teleport: true,
  ErrorBanner: true,
  ProductSearch: true,
  BlacklistConfig: true,
  StructureConfig: true,
  SkillsConfig: true,
  FeesConfig: true,
  IndustryDashboard: true,
  IndustryWorkshop: true,
  IndustrySlotsSection: true,
  ProfitMarginTab: true,
  BatchScanTab: true,
  BuyVsBuildTab: true,
  PivotAdvisorTab: true,
  StockpileDashboard: true,
}

const outcomes = apiOutcomesByPath()

async function mountIndustryWithQuery(query: LocationQuery) {
  route.query = query
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { props: { page: industryPageOfTab(query.tab) }, global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountIndustryWithQuery>>

function buttonNamed(wrapper: Wrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text().trim() === label)
  if (!button) throw new Error(`No button named ${label}`)
  return button
}

function requestCount(): number {
  return dataFreshnessRequestCount(vi.mocked(apiRequest).mock.calls)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  vi.setSystemTime(new Date(NOW))
  setActivePinia(createPinia())
  outcomes.clear()
  useIndustryStore().navigationIntent = null
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const answer = outcomes.answer(endpoint)
    return answer === undefined ? null : answer
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('Industry data freshness (issue 292)', () => {
  it.each(['margins', 'batch', 'buy-vs-build', 'pivot'] as const)('W1: shows the Jita prices, cost indices and adjusted prices freshness above the %s tab', async (tab) => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))

    const wrapper = await mountIndustryWithQuery({ tab })

    expect(shownFreshness(wrapper)).toEqual(TYPICAL_RESPONSE_SHOWN)
  })

  it.each(['workshop', 'dashboard', 'slots', 'stockpile'] as const)('W2: shows no data freshness on the %s tab', async (tab) => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))

    const wrapper = await mountIndustryWithQuery({ tab })

    expect(shownFreshness(wrapper)).toEqual([])
  })

  it('W3: shows the three sources with an unknown date and no error banner when the endpoint fails on the buy-vs-build tab', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, rejectWith(new ApiError('API error: 500', 500)))

    const wrapper = await mountIndustryWithQuery({ tab: 'buy-vs-build' })

    expect({
      requests: requestCount(),
      shown: shownFreshness(wrapper),
      errorBannerShown: wrapper.findComponent(ErrorBanner).exists(),
    }).toEqual({
      requests: 1,
      shown: [JITA_PRICES_UNKNOWN, COST_INDICES_UNKNOWN, ADJUSTED_PRICES_UNKNOWN],
      errorBannerShown: false,
    })
  })

  it('reads the data freshness again when the screen is mounted again', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))
    const firstScreen = await mountIndustryWithQuery({ tab: 'margins' })
    firstScreen.unmount()

    const secondScreen = await mountIndustryWithQuery({ tab: 'margins' })

    expect({ requests: requestCount(), shown: shownFreshness(secondScreen) }).toEqual({ requests: 2, shown: TYPICAL_RESPONSE_SHOWN })
  })

  it('W8: reads the data freshness once when going from the margins tab to the pivot tab', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))
    const wrapper = await mountIndustryWithQuery({ tab: 'margins' })

    await buttonNamed(wrapper, en.industry.tabs.pivot).trigger('click')
    await flushPromises()

    expect({ requests: requestCount(), shown: shownFreshness(wrapper) }).toEqual({ requests: 1, shown: TYPICAL_RESPONSE_SHOWN })
  })
})

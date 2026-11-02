import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import MarketBrowser from '@/views/MarketBrowser.vue'
import { apiRequest } from '@/services/api'
import {
  DATA_FRESHNESS_ENDPOINT,
  JITA_PRICES_42_MIN_AGO_FRESH,
  NOW,
  TYPICAL_DATA_FRESHNESS_RESPONSE,
  dataFreshnessRequestCount,
  shownFreshness,
} from '../dataFreshnessResponses'

const route = reactive<{ query: LocationQuery }>({ query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FIXED_JITA_SYNC_TEXT = 'Jita synced'

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  ErrorBanner: true,
  MarketSearch: true,
  MarketResultsTable: true,
  MarketTypeDetail: true,
  AlertsPanel: true,
  LoadingSpinner: true,
}

async function mountMarketBrowser() {
  route.query = {}
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(MarketBrowser, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  vi.setSystemTime(new Date(NOW))
  localStorage.clear()
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === DATA_FRESHNESS_ENDPOINT) return TYPICAL_DATA_FRESHNESS_RESPONSE
    if (endpoint === '/me/settings') return { effectiveMarketStructureName: 'C-J6MT - 1st Taj Mahgoon' }
    if (['/market/groups', '/market/favorites', '/market/alerts'].includes(endpoint)) return []
    throw new Error(`Unexpected request ${endpoint}`)
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('Market Browser data freshness (issue 292)', () => {
  it('W6: shows the Jita prices freshness instead of the fixed Jita synced badge', async () => {
    const wrapper = await mountMarketBrowser()

    expect({
      requests: dataFreshnessRequestCount(vi.mocked(apiRequest).mock.calls),
      shown: shownFreshness(wrapper),
      fixedJitaSyncShown: wrapper.text().includes(FIXED_JITA_SYNC_TEXT),
      fixedPulsingGreenDots: wrapper.findAll('.bg-emerald-500.animate-pulse').length,
    }).toEqual({
      requests: 1,
      shown: [JITA_PRICES_42_MIN_AGO_FRESH],
      fixedJitaSyncShown: false,
      fixedPulsingGreenDots: 0,
    })
  })
})

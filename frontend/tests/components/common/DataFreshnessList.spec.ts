import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import DataFreshness from '@/components/common/DataFreshness.vue'
import DataFreshnessList from '@/components/common/DataFreshnessList.vue'
import { apiRequest } from '@/services/api'
import { DATA_FRESHNESS_SOURCES, useDataFreshnessStore } from '@/stores/dataFreshness'
import type { DataFreshnessSource } from '@/utils/dataFreshness'
import {
  ADJUSTED_PRICES_INTERVAL,
  ADJUSTED_PRICES_UNKNOWN,
  COST_INDICES_INTERVAL,
  JITA_PRICES_42_MIN_AGO_FRESH,
  JITA_PRICES_INTERVAL,
  NOW,
  TYPICAL_DATA_FRESHNESS_RESPONSE,
  TYPICAL_RESPONSE_SHOWN,
  dataFreshnessRequestCount,
  shownFreshness,
} from '../../dataFreshnessResponses'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function mountList(sources: readonly DataFreshnessSource[]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useDataFreshnessStore().states = {
    'market-jita': { lastSuccessAt: '2026-10-31T11:18:00+00:00', expectedInterval: JITA_PRICES_INTERVAL, lastRunFailed: false },
    'cost-indices': { lastSuccessAt: '2026-10-31T05:30:00+00:00', expectedInterval: COST_INDICES_INTERVAL, lastRunFailed: true },
    'adjusted-prices': { lastSuccessAt: null, expectedInterval: ADJUSTED_PRICES_INTERVAL, lastRunFailed: false },
  }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(DataFreshnessList, { props: { sources }, global: { plugins: [i18n, pinia] } })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(TYPICAL_DATA_FRESHNESS_RESPONSE)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('DataFreshnessList (issue 292)', () => {
  it('W1: shows the Jita prices fresh, the cost indices stale after a failed sync and the adjusted prices unknown', () => {
    const wrapper = mountList(DATA_FRESHNESS_SOURCES)

    expect(shownFreshness(wrapper)).toEqual(TYPICAL_RESPONSE_SHOWN)
  })

  it('mounts one DataFreshness per source with the state of that source', () => {
    const wrapper = mountList(DATA_FRESHNESS_SOURCES)

    expect(wrapper.findAllComponents(DataFreshness).map(badge => badge.props())).toEqual([
      { source: 'market-jita', freshness: { lastSuccessAt: '2026-10-31T11:18:00+00:00', expectedInterval: JITA_PRICES_INTERVAL, lastRunFailed: false } },
      { source: 'cost-indices', freshness: { lastSuccessAt: '2026-10-31T05:30:00+00:00', expectedInterval: COST_INDICES_INTERVAL, lastRunFailed: true } },
      { source: 'adjusted-prices', freshness: { lastSuccessAt: null, expectedInterval: ADJUSTED_PRICES_INTERVAL, lastRunFailed: false } },
    ])
  })

  it('shows the sources in the order of its sources prop', () => {
    const wrapper = mountList(['adjusted-prices', 'market-jita'])

    expect(shownFreshness(wrapper)).toEqual([ADJUSTED_PRICES_UNKNOWN, JITA_PRICES_42_MIN_AGO_FRESH])
  })

  it('shows no data freshness for an empty list of sources', () => {
    const wrapper = mountList([])

    expect(shownFreshness(wrapper)).toEqual([])
  })

  it('R6: reads no data freshness itself, the screen does', async () => {
    mountList(DATA_FRESHNESS_SOURCES)
    await flushPromises()

    expect(dataFreshnessRequestCount(vi.mocked(apiRequest).mock.calls)).toBe(0)
  })
})

import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useDataFreshnessStore } from '@/stores/dataFreshness'
import { apiOutcomesByPath, rejectWith, respondWith } from '../deferred'
import {
  ADJUSTED_PRICES_INTERVAL,
  COST_INDICES_INTERVAL,
  DATA_FRESHNESS_ENDPOINT,
  JITA_PRICES_INTERVAL,
  JITA_PRICES_SYNCED_AT_11_18,
  NOW,
  TYPICAL_DATA_FRESHNESS_RESPONSE,
  dataFreshnessRequestCount,
} from '../dataFreshnessResponses'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const WALLET_ENTRY = {
  source: 'wallet',
  lastSuccessAt: '2026-10-31T11:50:00+00:00',
  expectedInterval: 3_600,
  lastRunFailed: false,
}

const outcomes = apiOutcomesByPath()

const TYPICAL_STATES = {
  'market-jita': { lastSuccessAt: '2026-10-31T11:18:00+00:00', expectedInterval: JITA_PRICES_INTERVAL, lastRunFailed: false },
  'cost-indices': { lastSuccessAt: '2026-10-31T05:30:00+00:00', expectedInterval: COST_INDICES_INTERVAL, lastRunFailed: true },
  'adjusted-prices': { lastSuccessAt: null, expectedInterval: ADJUSTED_PRICES_INTERVAL, lastRunFailed: false },
}

const ALL_UNKNOWN = { 'market-jita': null, 'cost-indices': null, 'adjusted-prices': null }

function requestCount(): number {
  return dataFreshnessRequestCount(vi.mocked(apiRequest).mock.calls)
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date(NOW))
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const answer = outcomes.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('dataFreshness store (issue 292)', () => {
  it('R1: maps the typical response to the state of each of the three sources', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))
    const store = useDataFreshnessStore()

    await store.fetchDataFreshness()

    expect({ requests: requestCount(), states: store.states }).toEqual({ requests: 1, states: TYPICAL_STATES })
  })

  it('keeps a null last successful sync as null and its expected interval of 86400 seconds', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))
    const store = useDataFreshnessStore()

    await store.fetchDataFreshness()

    expect(store.states['adjusted-prices']).toEqual({ lastSuccessAt: null, expectedInterval: ADJUSTED_PRICES_INTERVAL, lastRunFailed: false })
  })

  it('W4: leaves the cost indices and adjusted prices unknown when the response only has the Jita prices', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith([JITA_PRICES_SYNCED_AT_11_18]))
    const store = useDataFreshnessStore()

    await store.fetchDataFreshness()

    expect(store.states).toEqual({
      'market-jita': TYPICAL_STATES['market-jita'],
      'cost-indices': null,
      'adjusted-prices': null,
    })
  })

  it('W5: ignores an entry of the unknown wallet source', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith([...TYPICAL_DATA_FRESHNESS_RESPONSE, WALLET_ENTRY]))
    const store = useDataFreshnessStore()

    await store.fetchDataFreshness()

    expect(store.states).toEqual(TYPICAL_STATES)
  })

  it('W3: makes the three sources unknown without rejecting when the endpoint fails after a successful read', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, rejectWith(new ApiError('API error: 500', 500)))
    const store = useDataFreshnessStore()
    await store.fetchDataFreshness()

    const secondRead = store.fetchDataFreshness()

    await expect(secondRead).resolves.toBeUndefined()
    expect({ requests: requestCount(), states: store.states }).toEqual({ requests: 2, states: ALL_UNKNOWN })
  })

  it('reads the endpoint again on the next run after a failure', async () => {
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, rejectWith(new ApiError('API error: 500', 500)))
    outcomes.respondTo(DATA_FRESHNESS_ENDPOINT, respondWith(TYPICAL_DATA_FRESHNESS_RESPONSE))
    const store = useDataFreshnessStore()
    await store.fetchDataFreshness()

    await store.fetchDataFreshness()

    expect({ requests: requestCount(), states: store.states }).toEqual({ requests: 2, states: TYPICAL_STATES })
  })
})

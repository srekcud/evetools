import { createPinia, setActivePinia } from 'pinia'
import { useMarketStore, type MarketAlert } from '@/stores/market'

const PYERITE_ALERT: MarketAlert = {
  id: 'alert-pyerite',
  typeId: 35,
  typeName: 'Pyerite',
  direction: 'below',
  threshold: 9.125,
  priceSource: 'jita_buy',
  status: 'triggered',
  currentPrice: 8.6875,
  triggeredAt: '2026-11-09T18:30:00+00:00',
  createdAt: '2026-11-01T09:00:00+00:00',
}

const MEXALLON_ALERT: MarketAlert = {
  id: 'alert-mexallon',
  typeId: 36,
  typeName: 'Mexallon',
  direction: 'above',
  threshold: 61.5,
  priceSource: 'jita_sell',
  status: 'active',
  currentPrice: 55.25,
  triggeredAt: null,
  createdAt: '2026-11-02T10:00:00+00:00',
}

const REARMED_PYERITE_WITHOUT_PRICE = {
  ...PYERITE_ALERT,
  direction: 'below',
  threshold: 8.5,
  status: 'active',
  currentPrice: null,
  triggeredAt: null,
}

function stubFetch(response: Response) {
  const fetchStub = vi.fn(async () => response)
  vi.stubGlobal('fetch', fetchStub)
  return fetchStub
}

function storeWithAlerts() {
  setActivePinia(createPinia())
  const store = useMarketStore()
  store.alerts = [PYERITE_ALERT, MEXALLON_ALERT]
  return store
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('market store updateAlert', () => {
  it('patches the alert with the merge-patch content type and the new direction and threshold', async () => {
    const fetchStub = stubFetch(new Response(JSON.stringify(REARMED_PYERITE_WITHOUT_PRICE), { status: 200 }))
    const store = storeWithAlerts()

    await store.updateAlert('alert-pyerite', { direction: 'below', threshold: 8.5 })

    const [url, options] = fetchStub.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/market/alerts/alert-pyerite')
    expect(options.method).toBe('PATCH')
    expect((options.headers as Record<string, string>)['Content-Type']).toBe('application/merge-patch+json')
    expect(JSON.parse(String(options.body))).toEqual({ direction: 'below', threshold: 8.5 })
  })

  it('keeps the current price of the alert and leaves the other alerts as they were', async () => {
    stubFetch(new Response(JSON.stringify(REARMED_PYERITE_WITHOUT_PRICE), { status: 200 }))
    const store = storeWithAlerts()

    await store.updateAlert('alert-pyerite', { direction: 'below', threshold: 8.5 })

    expect(store.alerts).toEqual([
      { ...REARMED_PYERITE_WITHOUT_PRICE, currentPrice: 8.6875 },
      MEXALLON_ALERT,
    ])
  })

  it('rethrows the failure, keeps the alerts unchanged and exposes the error message', async () => {
    stubFetch(new Response(JSON.stringify({ error: 'An expired alert cannot be updated' }), { status: 409 }))
    const store = storeWithAlerts()

    await expect(store.updateAlert('alert-pyerite', { direction: 'below', threshold: 8.5 }))
      .rejects.toThrow('An expired alert cannot be updated')
    expect(store.alerts).toEqual([PYERITE_ALERT, MEXALLON_ALERT])
    expect(store.error).toBe('An expired alert cannot be updated')
  })
})

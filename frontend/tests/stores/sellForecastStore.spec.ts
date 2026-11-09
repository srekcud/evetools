import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupSellForecastStore } from '@/stores/group-industry/sellForecast'
import type { SellForecast, SellForecastMarket } from '@/stores/group-industry/types'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const NYX_PROJECT_ID = 'project-nyx-batch'
const SABRE_PROJECT_ID = 'project-sabre-batch'
const API_FAILURE = 'Sell forecast unavailable (fictif)'

const JITA_MARKET: SellForecastMarket = {
  name: 'Jita IV - Moon 4',
  unitPrice: 760_000,
  coveragePercent: null,
  feeRate: 0.04755,
  netUnitPrice: 723_862,
  netRevenue: 4_343_172,
  daysToSell: 12.5,
}

function forecastOf(projectId: string, typeName: string, quantity: number): SellForecast {
  return {
    projectId,
    sellStructure: null,
    products: [{ typeId: 12_779, typeName, quantity, structure: null, jita: JITA_MARKET }],
    totals: { structureNetRevenue: null, jitaNetRevenue: 4_343_172, structureUnknownProductCount: 0, jitaUnknownProductCount: 0 },
  }
}

const NYX_FORECAST = forecastOf(NYX_PROJECT_ID, 'Nyx', 6)
const SABRE_FORECAST = forecastOf(SABRE_PROJECT_ID, 'Sabre', 25)

function sellForecastPath(projectId: string): string {
  return `/industry/projects/${projectId}/sell-forecast`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group sell forecast store', () => {
  it('calls the sell forecast resource of the project and keeps the answer', async () => {
    vi.mocked(apiRequest).mockResolvedValue(NYX_FORECAST)
    const store = useGroupSellForecastStore()

    await store.fetchForProject(NYX_PROJECT_ID)

    expect(apiRequest).toHaveBeenCalledTimes(1)
    expect(apiRequest).toHaveBeenCalledWith(sellForecastPath(NYX_PROJECT_ID))
    expect(store.forecast).toEqual(NYX_FORECAST)
    expect(store.error).toBeNull()
    expect(store.loading).toBe(false)
  })

  it('shows loading while the request is pending', async () => {
    const pending = deferred<SellForecast>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupSellForecastStore()

    const fetching = store.fetchForProject(NYX_PROJECT_ID)

    expect(store.loading).toBe(true)
    pending.resolve(NYX_FORECAST)
    await fetching
    expect(store.loading).toBe(false)
  })

  it('ignores the answer of another project that arrives after the answer of the opened project', async () => {
    const nyxAnswer = deferred<SellForecast>()
    const sabreAnswer = deferred<SellForecast>()
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === sellForecastPath(NYX_PROJECT_ID)) return nyxAnswer.promise
      if (endpoint === sellForecastPath(SABRE_PROJECT_ID)) return sabreAnswer.promise
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const store = useGroupSellForecastStore()

    const fetchingNyx = store.fetchForProject(NYX_PROJECT_ID)
    const fetchingSabre = store.fetchForProject(SABRE_PROJECT_ID)
    sabreAnswer.resolve(SABRE_FORECAST)
    await fetchingSabre
    nyxAnswer.resolve(NYX_FORECAST)
    await fetchingNyx

    expect(store.forecast).toEqual(SABRE_FORECAST)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('ignores the failure of another project that arrives after the answer of the opened project', async () => {
    const nyxFailure = deferred<SellForecast>()
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === sellForecastPath(NYX_PROJECT_ID)) return nyxFailure.promise
      if (endpoint === sellForecastPath(SABRE_PROJECT_ID)) return SABRE_FORECAST
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const store = useGroupSellForecastStore()

    const fetchingNyx = store.fetchForProject(NYX_PROJECT_ID)
    await store.fetchForProject(SABRE_PROJECT_ID)
    nyxFailure.reject(new Error(API_FAILURE))
    await fetchingNyx

    expect(store.forecast).toEqual(SABRE_FORECAST)
    expect(store.error).toBeNull()
  })

  it('does not show the forecast of the previous project while the next one loads', async () => {
    const sabreAnswer = deferred<SellForecast>()
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === sellForecastPath(NYX_PROJECT_ID)) return NYX_FORECAST
      return sabreAnswer.promise
    })
    const store = useGroupSellForecastStore()
    await store.fetchForProject(NYX_PROJECT_ID)

    const fetchingSabre = store.fetchForProject(SABRE_PROJECT_ID)

    expect(store.forecast).toBeNull()
    sabreAnswer.resolve(SABRE_FORECAST)
    await fetchingSabre
  })

  it('keeps no forecast and exposes the message when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error(API_FAILURE))
    const store = useGroupSellForecastStore()

    await store.fetchForProject(NYX_PROJECT_ID)

    expect(store.forecast).toBeNull()
    expect(store.error).toBe(API_FAILURE)
    expect(store.loading).toBe(false)
  })

  it('drops the previous forecast of the same project when a reload fails', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(NYX_FORECAST).mockRejectedValueOnce(new Error(API_FAILURE))
    const store = useGroupSellForecastStore()
    await store.fetchForProject(NYX_PROJECT_ID)

    await store.fetchForProject(NYX_PROJECT_ID)

    expect(store.forecast).toBeNull()
    expect(store.error).toBe(API_FAILURE)
  })

  it('clears the error when a later request succeeds', async () => {
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error(API_FAILURE)).mockResolvedValueOnce(NYX_FORECAST)
    const store = useGroupSellForecastStore()
    await store.fetchForProject(NYX_PROJECT_ID)

    await store.fetchForProject(NYX_PROJECT_ID)

    expect(store.error).toBeNull()
    expect(store.forecast).toEqual(NYX_FORECAST)
  })
})

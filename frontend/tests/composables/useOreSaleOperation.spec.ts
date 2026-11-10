import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useOreSale } from '@/composables/useOreSale'
import {
  PERIMETER_STRUCTURE_NAME,
  miningValuationProfile,
  valuationProfileServer,
} from '../components/ledger/miningValuationProfileHarness'
import { PRICED_SALE, SALE_STRUCTURE_ID } from '../components/ledger/oreSaleHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OPERATION_ID = '0b1c5d1e-5f3a-4c53-9a43-7c2f0f6d0314'

function requestedEndpoints(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).filter(endpoint => endpoint !== '/mining/valuation-profile')
}

function answerSale(profile = miningValuationProfile()) {
  vi.mocked(apiRequest).mockImplementation(valuationProfileServer(profile).respondingWith(() => Promise.resolve(PRICED_SALE)))
}

function answerSaleWith(failures: Error[]) {
  const remaining = [...failures]
  vi.mocked(apiRequest).mockImplementation(valuationProfileServer().respondingWith(() => Promise.reject(remaining.shift())))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  localStorage.clear()
})

describe('useOreSale of an operation', () => {
  it('requests the ore sale of the operation instead of the held ore', async () => {
    answerSale()
    const { sale, load } = useOreSale(OPERATION_ID)

    await load()

    expect(requestedEndpoints()).toEqual([`/mining-operations/${OPERATION_ID}/ore-sale`])
    expect(sale.value).toEqual(PRICED_SALE)
  })

  it('passes the structure of the mining valuation profile to the operation route', async () => {
    answerSale(miningValuationProfile({ structureId: SALE_STRUCTURE_ID, structureName: PERIMETER_STRUCTURE_NAME }))
    const { load } = useOreSale(OPERATION_ID)

    await load()

    expect(requestedEndpoints()).toEqual([`/mining-operations/${OPERATION_ID}/ore-sale?structureId=${SALE_STRUCTURE_ID}`])
  })

  it('still requests the held ore when no operation is given', async () => {
    answerSale()
    const { load } = useOreSale()

    await load()

    expect(requestedEndpoints()).toEqual(['/mining/ore-sale'])
  })

  it('reports quantities not known yet on a conflict, without raising the load error', async () => {
    vi.mocked(apiRequest).mockImplementation(valuationProfileServer().respondingWith(() => Promise.reject(new ApiError('The mined quantities of this operation are not known yet', 409))))
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { sale, hasError, notKnownYet, load } = useOreSale(OPERATION_ID)

    await load()

    expect([sale.value, hasError.value, notKnownYet.value]).toEqual([null, false, true])
  })

  it('raises the load error on any other failure and clears a previous conflict', async () => {
    answerSaleWith([new ApiError('conflict', 409), new ApiError('API error: 503', 503)])
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { hasError, notKnownYet, load } = useOreSale(OPERATION_ID)

    await load()
    await load()

    expect([hasError.value, notKnownYet.value]).toEqual([true, false])
  })
})

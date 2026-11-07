import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useLedgerStore, type LedgerIndustry } from '@/stores/ledger'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function industryOfProfit(days: number, profit: number): LedgerIndustry {
  return {
    period: { from: '2026-10-08', to: '2026-11-07', days },
    totals: { profit, projectCount: 1, unknownProjectCount: 0, capital: 400_000_000, slotHours: 100, iskPerHour: profit / 100 },
    months: [
      { month: '2026-11', projectCount: 1, unknownProjectCount: 0, profit, capital: 400_000_000, marginPercent: 30, slotHours: 100, iskPerHour: profit / 100 },
    ],
    products: [{ typeId: 22456, typeName: 'Sabre', projectCount: 1, unknownProjectCount: 0, profit }],
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ledger store industry', () => {
  it('reads the industry revenue of the requested period', async () => {
    vi.mocked(apiRequest).mockResolvedValue(industryOfProfit(7, 120_000_000))
    const store = useLedgerStore()

    await store.fetchIndustry(7)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith('/ledger/industry?days=7')
    expect(store.industry).toEqual(industryOfProfit(7, 120_000_000))
  })

  it('ignores the late answer of a period that is no longer the selected one', async () => {
    const weekAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(weekAnswer.promise)
    vi.mocked(apiRequest).mockResolvedValueOnce(industryOfProfit(30, 150_000_000))
    const store = useLedgerStore()

    void store.fetchIndustry(7)
    await store.fetchIndustry(30)
    weekAnswer.resolve(industryOfProfit(7, 120_000_000))
    await flushPromises()

    expect(store.industry).toEqual(industryOfProfit(30, 150_000_000))
  })
})

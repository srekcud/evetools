import { apiRequest } from '@/services/api'
import { useStockpileCoverage, type StockpileCoverage } from '@/composables/useStockpileCoverage'
import { COVERAGE_PATH, COVERED_OPERATION_ID, stockpileCoverage } from '../components/mining-operation/stockpileCoverageHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('useStockpileCoverage', () => {
  it('loads the Stockpile coverage of the operation', async () => {
    vi.mocked(apiRequest).mockResolvedValue(stockpileCoverage())
    const { coverage, hasError, isLoading, load } = useStockpileCoverage()

    await load(COVERED_OPERATION_ID)

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toEqual([COVERAGE_PATH])
    expect(coverage.value).toEqual(stockpileCoverage())
    expect([hasError.value, isLoading.value]).toEqual([false, false])
  })

  it('is loading until the response arrives', async () => {
    let respond: (coverage: StockpileCoverage) => void = () => {}
    vi.mocked(apiRequest).mockReturnValue(new Promise(resolve => { respond = resolve }))
    const { isLoading, load } = useStockpileCoverage()

    const loading = load(COVERED_OPERATION_ID)
    expect(isLoading.value).toBe(true)
    respond(stockpileCoverage())
    await loading

    expect(isLoading.value).toBe(false)
  })

  it('reports a failed request as an error and drops the previous coverage', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(stockpileCoverage()).mockRejectedValueOnce(new Error('HTTP 500'))
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    const { coverage, hasError, isLoading, load } = useStockpileCoverage()

    await load(COVERED_OPERATION_ID)
    await load(COVERED_OPERATION_ID)
    consoleError.mockRestore()

    expect([coverage.value, hasError.value, isLoading.value]).toEqual([null, true, false])
  })
})

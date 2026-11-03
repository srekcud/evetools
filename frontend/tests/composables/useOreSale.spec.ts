import { apiRequest } from '@/services/api'
import { buildSaleSummary, useOreSale } from '@/composables/useOreSale'
import type { OreSaleResponse } from '@/composables/useOreSale'
import {
  COMPRESSED_FULLERITE_C50_TYPE_ID,
  EMPTY_SALE,
  PRICED_SALE,
  PRICED_SALE_SUMMARY,
  SALE_STRUCTURE_ID,
  SALE_WITH_UNPRICED_BITUMENS,
  oreSaleRow,
} from '../components/ledger/oreSaleHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function requestedEndpoints(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  localStorage.clear()
})

describe('buildSaleSummary', () => {
  it('writes one line per type with its quantity, then the total volume and the reference value', () => {
    expect(buildSaleSummary(PRICED_SALE)).toBe(PRICED_SALE_SUMMARY)
  })

  it('writes an unknown reference value followed by the types without price, never zero', () => {
    expect(buildSaleSummary(SALE_WITH_UNPRICED_BITUMENS)).toBe([
      'Compressed Veldspar\t20000',
      'Compressed Bitumens\t350',
      'Total volume: 55.00',
      'Reference value: unknown',
      'Missing prices: Compressed Bitumens',
    ].join('\n'))
  })

  it('writes the type id when the type name is missing and an unknown total volume', () => {
    const sale: OreSaleResponse = {
      structureId: null,
      rows: [oreSaleRow({ typeId: COMPRESSED_FULLERITE_C50_TYPE_ID, typeName: null, quantity: 12, unitVolume: null, totalVolume: null, jitaSellPrice: 2813, bestPrice: 2813, bestSource: 'jita_sell', totalValue: 33756 })],
      totals: {
        totalQuantity: 12,
        totalVolume: null,
        totalValue: 33756,
        missingPriceTypeIds: [],
        missingVolumeTypeIds: [COMPRESSED_FULLERITE_C50_TYPE_ID],
      },
    }

    expect(buildSaleSummary(sale)).toBe([
      `${COMPRESSED_FULLERITE_C50_TYPE_ID}\t12`,
      'Total volume: unknown',
      'Reference value: 33756.00',
    ].join('\n'))
  })

  it('lists a type without name by its id among the types without price', () => {
    const sale: OreSaleResponse = {
      structureId: null,
      rows: [oreSaleRow({ typeId: COMPRESSED_FULLERITE_C50_TYPE_ID, typeName: null, quantity: 3, unitVolume: 0.1, totalVolume: 0.3 })],
      totals: {
        totalQuantity: 3,
        totalVolume: 0.3,
        totalValue: null,
        missingPriceTypeIds: [COMPRESSED_FULLERITE_C50_TYPE_ID],
        missingVolumeTypeIds: [],
      },
    }

    expect(buildSaleSummary(sale)).toBe([
      `${COMPRESSED_FULLERITE_C50_TYPE_ID}\t3`,
      'Total volume: 0.30',
      'Reference value: unknown',
      `Missing prices: ${COMPRESSED_FULLERITE_C50_TYPE_ID}`,
    ].join('\n'))
  })
})

describe('useOreSale loading', () => {
  it('loads the ore sale without structure when none is chosen in the mining tab', async () => {
    vi.mocked(apiRequest).mockResolvedValue(PRICED_SALE)
    const { sale, isEmpty, hasError, isLoading, summary, load } = useOreSale()

    await load()

    expect(requestedEndpoints()).toEqual(['/mining/ore-sale'])
    expect(sale.value).toEqual(PRICED_SALE)
    expect(summary.value).toBe(PRICED_SALE_SUMMARY)
    expect([isEmpty.value, hasError.value, isLoading.value]).toEqual([false, false, false])
  })

  it('sends the structure chosen in the mining tab', async () => {
    localStorage.setItem('miningSelectedStructure', JSON.stringify({ id: SALE_STRUCTURE_ID, name: 'Perimeter - Tranquility Trading Tower' }))
    vi.mocked(apiRequest).mockResolvedValue(PRICED_SALE)
    const { load } = useOreSale()

    await load()

    expect(requestedEndpoints()).toEqual([`/mining/ore-sale?structureId=${SALE_STRUCTURE_ID}`])
  })

  it('sends no structure when the stored structure was cleared', async () => {
    localStorage.setItem('miningSelectedStructure', JSON.stringify({ id: null, name: '' }))
    vi.mocked(apiRequest).mockResolvedValue(PRICED_SALE)
    const { load } = useOreSale()

    await load()

    expect(requestedEndpoints()).toEqual(['/mining/ore-sale'])
  })

  it('is loading until the response arrives', async () => {
    let respond: (sale: OreSaleResponse) => void = () => {}
    vi.mocked(apiRequest).mockReturnValue(new Promise(resolve => { respond = resolve }))
    const { isLoading, load } = useOreSale()

    const loading = load()
    expect(isLoading.value).toBe(true)
    respond(PRICED_SALE)
    await loading

    expect(isLoading.value).toBe(false)
  })

  it('is empty when no compressed ore is held', async () => {
    vi.mocked(apiRequest).mockResolvedValue(EMPTY_SALE)
    const { sale, isEmpty, hasError, load } = useOreSale()

    await load()

    expect(sale.value).toEqual(EMPTY_SALE)
    expect([isEmpty.value, hasError.value]).toEqual([true, false])
  })

  it('reports a failed request as an error, not as an empty sale', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('HTTP 500'))
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    const { sale, isEmpty, hasError, isLoading, load } = useOreSale()

    await load()
    consoleError.mockRestore()

    expect(sale.value).toBeNull()
    expect([isEmpty.value, hasError.value, isLoading.value]).toEqual([false, true, false])
  })
})

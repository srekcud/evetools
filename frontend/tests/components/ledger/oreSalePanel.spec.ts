import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import OreSalePanel from '@/components/ledger/OreSalePanel.vue'
import type { OreSaleResponse } from '@/composables/useOreSale'
import { apiRequest } from '@/services/api'
import { messageOf } from '../../localeTexts'
import { i18nPlugin } from './miningLedgerHarness'
import {
  COMPRESSED_BITUMENS_TYPE_ID,
  COMPRESSED_CLEAR_ICICLE_TYPE_ID,
  COMPRESSED_VELDSPAR_TYPE_ID,
  EMPTY_SALE,
  PRICED_SALE,
  PRICED_SALE_SUMMARY,
  SALE_STRUCTURE_ID,
  SALE_WITH_UNPRICED_BITUMENS,
  STRUCTURE_SALE,
  oreSaleRow,
} from './oreSaleHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LONG_NAME_TYPE_ID = 81900
const LONG_NAME = 'Compressed Exceptionally Lustrous Hypothetical Moon Ore With An Overly Long Name'

type SaleColumn =
  | 'quantity'
  | 'totalVolume'
  | 'jitaBuyPrice'
  | 'jitaSellPrice'
  | 'structureBuyPrice'
  | 'structureSellPrice'
  | 'bestPrice'
  | 'bestSource'
  | 'totalValue'

async function mountPanel(sale: () => Promise<OreSaleResponse>) {
  const pinia = createPinia()
  setActivePinia(pinia)
  vi.mocked(apiRequest).mockImplementation(endpoint => endpoint.startsWith('/mining/ore-sale') ? sale() : new Promise(() => {}))
  const wrapper = mount(OreSalePanel, { global: { plugins: [i18nPlugin(), pinia] } })
  await flushPromises()
  return wrapper
}

type PanelWrapper = Awaited<ReturnType<typeof mountPanel>>

function found(wrapper: PanelWrapper, selector: string) {
  const element = wrapper.find(selector)
  expect(element.exists(), selector).toBe(true)
  return element
}

function cell(wrapper: PanelWrapper, typeId: number, column: SaleColumn) {
  return found(wrapper, `tbody tr[data-type-id="${typeId}"] td[data-column="${column}"]`)
}

function expectUnknown(element: ReturnType<typeof found>) {
  expect(element.text()).toBe('—')
  expect(element.find('[title]').exists()).toBe(true)
  expect(element.get('[title]').attributes('title')).toBe(messageOf('en', 'common.unknownValue'))
}

function rowTypeIds(wrapper: PanelWrapper): string[] {
  return wrapper.findAll('tbody tr[data-type-id]').map(row => row.attributes('data-type-id') ?? '')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  localStorage.clear()
})

describe('OreSalePanel rows', () => {
  it('renders one row per held type in the order of the response, long names in full', async () => {
    const longNameRow = oreSaleRow({ typeId: LONG_NAME_TYPE_ID, typeName: LONG_NAME, quantity: 40, totalVolume: 0.04, jitaSellPrice: 52.4, bestPrice: 52.4, bestSource: 'jita_sell', totalValue: 2096 })
    const wrapper = await mountPanel(() => Promise.resolve({ ...PRICED_SALE, rows: [...PRICED_SALE.rows, longNameRow] }))

    expect(rowTypeIds(wrapper)).toEqual([String(COMPRESSED_CLEAR_ICICLE_TYPE_ID), String(COMPRESSED_VELDSPAR_TYPE_ID), String(LONG_NAME_TYPE_ID)])
    expect(found(wrapper, `tbody tr[data-type-id="${LONG_NAME_TYPE_ID}"]`).text()).toContain(LONG_NAME)
  })

  it('shows unknown prices and values as a dash with the unknown value tooltip', async () => {
    const wrapper = await mountPanel(() => Promise.resolve(SALE_WITH_UNPRICED_BITUMENS))

    for (const column of ['jitaBuyPrice', 'jitaSellPrice', 'bestPrice', 'bestSource', 'totalValue'] as const) {
      expectUnknown(cell(wrapper, COMPRESSED_BITUMENS_TYPE_ID, column))
    }
    expect(cell(wrapper, COMPRESSED_BITUMENS_TYPE_ID, 'quantity').text()).not.toBe('—')
    expect(cell(wrapper, COMPRESSED_VELDSPAR_TYPE_ID, 'totalValue').text()).not.toBe('—')
  })

  it('shows a known price of zero as a known value', async () => {
    const zeroPricedRow = oreSaleRow({ typeId: COMPRESSED_BITUMENS_TYPE_ID, typeName: 'Compressed Bitumens', quantity: 350, unitVolume: 0.1, totalVolume: 35, jitaBuyPrice: 0, bestPrice: 0, bestSource: 'jita_buy', totalValue: 0 })
    const wrapper = await mountPanel(() => Promise.resolve({
      structureId: null,
      rows: [zeroPricedRow],
      totals: { totalQuantity: 350, totalVolume: 35, totalValue: 0, missingPriceTypeIds: [], missingVolumeTypeIds: [] },
    }))

    for (const column of ['jitaBuyPrice', 'bestPrice', 'totalValue'] as const) {
      const zero = cell(wrapper, COMPRESSED_BITUMENS_TYPE_ID, column)
      expect(zero.text()).not.toBe('—')
      expect(zero.text()).toContain('0')
      expect(zero.find('[title]').exists()).toBe(false)
    }
  })

  it('names the source of the best price in text on each priced row', async () => {
    const wrapper = await mountPanel(() => Promise.resolve(STRUCTURE_SALE))

    const icicleSource = found(wrapper, `tbody tr[data-type-id="${COMPRESSED_CLEAR_ICICLE_TYPE_ID}"] [data-best-source]`)
    const veldsparSource = found(wrapper, `tbody tr[data-type-id="${COMPRESSED_VELDSPAR_TYPE_ID}"] [data-best-source]`)
    expect(icicleSource.attributes('data-best-source')).toBe('structure_sell')
    expect(veldsparSource.attributes('data-best-source')).toBe('structure_buy')
    expect(icicleSource.text().trim()).not.toBe('')
    expect(veldsparSource.text().trim()).not.toBe('')
    expect(icicleSource.text()).not.toBe(veldsparSource.text())
  })
})

describe('OreSalePanel structure prices', () => {
  it('shows the structure price columns when the sale is valued with a structure', async () => {
    localStorage.setItem('miningSelectedStructure', JSON.stringify({ id: SALE_STRUCTURE_ID, name: 'Perimeter - Tranquility Trading Tower' }))
    const wrapper = await mountPanel(() => Promise.resolve(STRUCTURE_SALE))

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toEqual([`/mining/ore-sale?structureId=${SALE_STRUCTURE_ID}`])
    expect(wrapper.find('th[data-column="structureBuyPrice"]').exists()).toBe(true)
    expect(wrapper.find('th[data-column="structureSellPrice"]').exists()).toBe(true)
    expect(cell(wrapper, COMPRESSED_VELDSPAR_TYPE_ID, 'structureBuyPrice').text()).not.toBe('—')
  })

  it('hides the structure price columns without a structure', async () => {
    const wrapper = await mountPanel(() => Promise.resolve(PRICED_SALE))

    expect(rowTypeIds(wrapper)).toHaveLength(2)
    expect(wrapper.find('[data-column="structureBuyPrice"]').exists()).toBe(false)
    expect(wrapper.find('[data-column="structureSellPrice"]').exists()).toBe(false)
  })
})

describe('OreSalePanel totals', () => {
  it('shows the known total volume and value', async () => {
    const wrapper = await mountPanel(() => Promise.resolve(PRICED_SALE))

    expect(found(wrapper, '[data-total="volume"]').text()).not.toBe('—')
    expect(found(wrapper, '[data-total="value"]').text()).not.toBe('—')
    expect(found(wrapper, '[data-total="quantity"]').text()).not.toBe('—')
  })

  it('shows an unknown total value as a dash with the unknown value tooltip, never zero', async () => {
    const wrapper = await mountPanel(() => Promise.resolve(SALE_WITH_UNPRICED_BITUMENS))

    expectUnknown(found(wrapper, '[data-total="value"]'))
    expect(found(wrapper, '[data-total="volume"]').text()).not.toBe('—')
  })
})

describe('OreSalePanel summary to copy', () => {
  it('shows the summary built from the response and copies it to the clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const wrapper = await mountPanel(() => Promise.resolve(PRICED_SALE))

    expect(found(wrapper, '[data-sale-summary]').text()).toBe(PRICED_SALE_SUMMARY)
    const copyButton = found(wrapper, 'button[data-copy-summary]')
    expect(copyButton.attributes('disabled')).toBeUndefined()
    await copyButton.trigger('click')

    expect(writeText).toHaveBeenCalledWith(PRICED_SALE_SUMMARY)
  })
})

describe('OreSalePanel empty and failed states', () => {
  it('shows that no compressed ore is held, without rows, and keeps the copy button inactive', async () => {
    const wrapper = await mountPanel(() => Promise.resolve(EMPTY_SALE))

    const emptyState = found(wrapper, '[data-empty-state]')
    expect(emptyState.text().trim()).not.toBe('')
    expect(emptyState.text()).not.toContain('—')
    expect(emptyState.text()).not.toContain(messageOf('en', 'common.unknownValue'))
    expect(rowTypeIds(wrapper)).toEqual([])
    expect(wrapper.find('[data-load-error]').exists()).toBe(false)
    expect(found(wrapper, 'button[data-copy-summary]').attributes('disabled')).toBeDefined()
  })

  it('shows a failed request as an error, not as an empty sale', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    const wrapper = await mountPanel(() => Promise.reject(new Error('HTTP 500')))
    consoleError.mockRestore()

    expect(wrapper.find('[data-load-error]').exists()).toBe(true)
    expect(wrapper.find('[data-empty-state]').exists()).toBe(false)
    expect(rowTypeIds(wrapper)).toEqual([])
  })
})

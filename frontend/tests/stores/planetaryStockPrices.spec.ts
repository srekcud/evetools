import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import { usePlanetaryStockPricesStore } from '@/stores/planetaryStockPrices'
import { COOLANT_PRICE_ROW, PLASMA_PRICE_ROW, PRICES_OF_ALL_KINDS, pricesOf } from '../planetaryStockPricesFixtures'
import { apiCalls, serve } from '../planetarySettingsFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function freshStore() {
  setActivePinia(createPinia())
  return usePlanetaryStockPricesStore()
}

describe('planetaryStockPrices store', () => {
  it('reads the stock prices with one request and exposes the header and the rows in the order received', async () => {
    serve({ 'GET /planetary/stock-prices': pricesOf([COOLANT_PRICE_ROW, PLASMA_PRICE_ROW]) })
    const store = freshStore()
    expect(store.prices).toBeNull()
    expect(store.rows).toEqual([])

    await store.load()

    expect(apiCalls()).toEqual([{ method: 'GET', endpoint: '/planetary/stock-prices', body: undefined }])
    expect(store.prices).toEqual(pricesOf([COOLANT_PRICE_ROW, PLASMA_PRICE_ROW]))
    expect(store.rows.map(row => row.typeId)).toEqual([COOLANT_PRICE_ROW.typeId, PLASMA_PRICE_ROW.typeId])
    expect(store.error).toBeNull()
  })

  it('exposes the translated load error and no rows when the read fails, then clears it on the next success', async () => {
    serve({ 'GET /planetary/stock-prices': new Error('Network down') })
    const store = freshStore()

    await store.load()

    expect(store.error).toBe(i18n.global.t('pi.stock.prices.loadError'))
    expect(store.error).not.toBe('pi.stock.prices.loadError')
    expect(store.rows).toEqual([])

    serve({ 'GET /planetary/stock-prices': PRICES_OF_ALL_KINDS })
    await store.load()

    expect(store.error).toBeNull()
    expect(store.rows).toHaveLength(4)
  })
})

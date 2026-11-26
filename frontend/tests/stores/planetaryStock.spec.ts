import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'
import {
  COOLANT_ROW,
  MECHANICAL_PARTS_ROW,
  NO_HOME_ROW,
  PLASMA_ROW,
  ROBOTICS_ROW,
  STOCK_WITH_PURCHASES,
  UNKNOWN_EXTRACTION_ROW,
  WATER_ROW,
  stockOf,
  stockRow,
} from '../planetaryStockFixtures'
import { ROBOTICS } from '../planetaryPlannerFixtures'
import { apiCalls, serve } from '../planetarySettingsFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function freshStore() {
  setActivePinia(createPinia())
  return usePlanetaryStockStore()
}

async function storeWithRows(rows: ReturnType<typeof stockOf>['rows']) {
  serve({ 'GET /planetary/stock': stockOf(rows) })
  const store = freshStore()
  await store.load()
  return store
}

describe('planetaryStock store', () => {
  it('reads the stock coverage with one request and exposes the header and the rows in the order received', async () => {
    serve({ 'GET /planetary/stock': STOCK_WITH_PURCHASES })
    const store = freshStore()
    expect(store.stock).toBeNull()
    expect(store.rows).toEqual([])

    await store.load()

    expect(apiCalls()).toEqual([{ method: 'GET', endpoint: '/planetary/stock', body: undefined }])
    expect(store.stock).toEqual(STOCK_WITH_PURCHASES)
    expect(store.rows.map(row => row.typeId)).toEqual([ROBOTICS_ROW.typeId, PLASMA_ROW.typeId, COOLANT_ROW.typeId])
    expect(store.isLoading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('exposes the translated load error and no rows when the read fails, then clears it on the next success', async () => {
    serve({ 'GET /planetary/stock': new Error('Network down') })
    const store = freshStore()

    await store.load()

    expect(store.error).toBe(i18n.global.t('pi.stock.loadError'))
    expect(store.error).not.toBe('pi.stock.loadError')
    expect(store.rows).toEqual([])
    expect(store.isLoading).toBe(false)

    serve({ 'GET /planetary/stock': STOCK_WITH_PURCHASES })
    await store.load()

    expect(store.error).toBeNull()
    expect(store.rows).toHaveLength(3)
  })

  it('lists in the purchase list the rows to buy in the order received, one unit included, zero and unknown quantities left out', async () => {
    const oneUnit = stockRow(MECHANICAL_PARTS_ROW.typeId, 'Mechanical Parts', { toBuy: 1, volumePerUnit: 0.8 })
    const store = await storeWithRows([ROBOTICS_ROW, WATER_ROW, UNKNOWN_EXTRACTION_ROW, PLASMA_ROW, NO_HOME_ROW, COOLANT_ROW, oneUnit])

    expect(store.purchaseList).toEqual([
      { typeId: ROBOTICS_ROW.typeId, typeName: 'Robotics', quantity: 1001 },
      { typeId: PLASMA_ROW.typeId, typeName: 'Plasma', quantity: 600 },
      { typeId: MECHANICAL_PARTS_ROW.typeId, typeName: 'Mechanical Parts', quantity: 1 },
    ])
  })

  it('has an empty purchase list and a zero volume when nothing is to buy or nothing is built', async () => {
    const nothingToBuy = await storeWithRows([COOLANT_ROW, WATER_ROW])
    const nothingBuilt = await storeWithRows([])

    expect(nothingToBuy.purchaseList).toEqual([])
    expect(nothingToBuy.purchaseVolume).toBe(0)
    expect(nothingBuilt.purchaseList).toEqual([])
    expect(nothingBuilt.purchaseVolume).toBe(0)
  })

  it('sums the quantity to buy times the volume per unit of every line of the purchase list', async () => {
    const store = await storeWithRows([ROBOTICS_ROW, PLASMA_ROW, COOLANT_ROW])

    expect(store.purchaseVolume).toBeCloseTo(1001 * 0.5 + 600 * 0.25, 6)
    expect(store.purchaseVolume).toBeCloseTo(650.5, 6)
  })

  it('has an unknown volume when one line of the purchase list has no volume, but ignores the volume of a row that is not to buy', async () => {
    const unknownVolumeToBuy = stockRow(ROBOTICS, 'Robotics', { toBuy: 1001, volumePerUnit: null })
    const unknownVolumeNotToBuy = stockRow(COOLANT_ROW.typeId, 'Coolant', { toBuy: 0, volumePerUnit: null })
    const withUnknownVolume = await storeWithRows([unknownVolumeToBuy, PLASMA_ROW])
    const ignored = await storeWithRows([PLASMA_ROW, unknownVolumeNotToBuy])

    expect(withUnknownVolume.purchaseVolume).toBeNull()
    expect(ignored.purchaseVolume).toBeCloseTo(600 * 0.25, 6)
  })
})

import { diagnoseBatchScan } from '@/utils/batchScanDiagnosis'
import type { BatchScanItem } from '@/stores/industry/types'

const SLASHER_TYPE_ID = 585
const RIFTER_TYPE_ID = 587
const BREACHER_TYPE_ID = 598
const MIN_MARGIN_PERCENT = 5
const MIN_DAILY_VOLUME = 50

function scannedProduct(typeId: number, overrides: Partial<BatchScanItem> = {}): BatchScanItem {
  return {
    typeId,
    typeName: `Product ${typeId}`,
    groupName: 'Frigate',
    categoryLabel: 'T1 Ships',
    marginPercent: 12.5,
    profitPerUnit: 600_000,
    dailyVolume: 120,
    iskPerDay: 72_000_000,
    materialCost: 4_000_000,
    importCost: 2_450,
    exportCost: 0,
    sellPrice: 5_000_000,
    meUsed: 10,
    activityType: 'manufacturing',
    isFactionBlueprint: false,
    bpcCostPerRun: null,
    hasAllSkills: true,
    missingSkillCount: 0,
    unknownReason: null,
    missingPriceTypeIds: [],
    ...overrides,
  }
}

describe('diagnoseBatchScan', () => {
  it('counts nothing for a scan that returned no product', () => {
    expect(diagnoseBatchScan([], MIN_MARGIN_PERCENT, MIN_DAILY_VOLUME)).toEqual({
      scanned: 0,
      withoutSalePrice: 0,
      withoutMaterialPrice: 0,
      withoutDailyVolume: 0,
      belowMinMargin: 0,
      belowMinDailyVolume: 0,
    })
  })

  it('counts the products without a sell price at the venue', () => {
    const rows = [
      scannedProduct(SLASHER_TYPE_ID, { sellPrice: null, marginPercent: null, unknownReason: 'missing_sale_price' }),
      scannedProduct(RIFTER_TYPE_ID, { sellPrice: null, marginPercent: null, unknownReason: 'missing_sale_price' }),
      scannedProduct(BREACHER_TYPE_ID),
    ]

    expect(diagnoseBatchScan(rows, MIN_MARGIN_PERCENT, MIN_DAILY_VOLUME)).toMatchObject({ scanned: 3, withoutSalePrice: 2 })
  })

  it('counts the products with a material that has no Jita price', () => {
    const rows = [
      scannedProduct(SLASHER_TYPE_ID, { materialCost: null, marginPercent: null, missingPriceTypeIds: [34], unknownReason: 'missing_material_price' }),
      scannedProduct(RIFTER_TYPE_ID),
    ]

    expect(diagnoseBatchScan(rows, MIN_MARGIN_PERCENT, MIN_DAILY_VOLUME)).toMatchObject({ withoutMaterialPrice: 1 })
  })

  it('counts the products without a daily volume apart from the products with a known zero volume', () => {
    const rows = [
      scannedProduct(SLASHER_TYPE_ID, { dailyVolume: null, iskPerDay: null, unknownReason: 'missing_daily_volume' }),
      scannedProduct(RIFTER_TYPE_ID, { dailyVolume: 0, iskPerDay: 0 }),
    ]

    expect(diagnoseBatchScan(rows, MIN_MARGIN_PERCENT, MIN_DAILY_VOLUME)).toMatchObject({ withoutDailyVolume: 1, belowMinDailyVolume: 1 })
  })

  it('counts the products with a known margin under the minimum margin, never the products with an unknown margin', () => {
    const rows = [
      scannedProduct(SLASHER_TYPE_ID, { marginPercent: 4.99 }),
      scannedProduct(RIFTER_TYPE_ID, { marginPercent: 5 }),
      scannedProduct(BREACHER_TYPE_ID, { marginPercent: null, sellPrice: null }),
    ]

    expect(diagnoseBatchScan(rows, MIN_MARGIN_PERCENT, MIN_DAILY_VOLUME)).toMatchObject({ belowMinMargin: 1 })
  })

  it('counts no product under a minimum that is not set', () => {
    const rows = [scannedProduct(SLASHER_TYPE_ID, { marginPercent: -3, dailyVolume: 1 })]

    expect(diagnoseBatchScan(rows, null, null)).toMatchObject({ belowMinMargin: 0, belowMinDailyVolume: 0 })
  })
})

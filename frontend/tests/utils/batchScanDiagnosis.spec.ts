import { diagnoseBatchScan } from '@/utils/batchScanDiagnosis'
import type { BatchScanItem } from '@/stores/industry/types'

const SLASHER_TYPE_ID = 585
const RIFTER_TYPE_ID = 587
const BREACHER_TYPE_ID = 598
const MIN_MARGIN_PERCENT = 5
const MIN_DAILY_VOLUME = 50
const RAITARU_HOME_STRUCTURE_ID = '0192f3a4-7b1c-7d2e-8f3a-4b5c6d7e8f91'
const TATARA_REACTIONS_STRUCTURE_ID = '0192f3a4-7b1c-7d2e-8f3a-4b5c6d7e8f92'

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
    teUsed: 20,
    activityType: 'manufacturing',
    isFactionBlueprint: false,
    bpcCostPerRun: null,
    hasAllSkills: true,
    missingSkillCount: 0,
    unknownReason: null,
    unknownReasonStructureId: null,
    missingPriceTypeIds: [],
    contractSales: null,
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
      unknownMargin: { byReason: {}, facilityTaxByStructure: {}, other: 0 },
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

function unknownMarginProduct(typeId: number, unknownReason: string, overrides: Partial<BatchScanItem> = {}): BatchScanItem {
  return scannedProduct(typeId, { marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason, ...overrides })
}

describe('diagnoseBatchScan unknown margins', () => {
  it('counts the products with an unknown margin by their reason', () => {
    const rows = [
      unknownMarginProduct(1001, 'missing_cost_index'),
      unknownMarginProduct(1002, 'missing_cost_index'),
      unknownMarginProduct(1003, 'missing_adjusted_price'),
      unknownMarginProduct(1004, 'missing_adjusted_price'),
      unknownMarginProduct(1005, 'missing_adjusted_price'),
      unknownMarginProduct(1006, 'missing_sales_fees'),
      unknownMarginProduct(1007, 'missing_invention_probability'),
      unknownMarginProduct(1008, 'missing_market_price'),
      unknownMarginProduct(1009, 'missing_market_price'),
      unknownMarginProduct(1010, 'missing_volume'),
      scannedProduct(1011),
    ]

    expect(diagnoseBatchScan(rows, null, null).unknownMargin).toEqual({
      byReason: {
        missing_cost_index: 2,
        missing_adjusted_price: 3,
        missing_sales_fees: 1,
        missing_invention_probability: 1,
        missing_market_price: 2,
        missing_volume: 1,
      },
      facilityTaxByStructure: {},
      other: 0,
    })
  })

  it('leaves the reasons already carried by the sale price, material price and daily volume lines out of the unknown margin counts', () => {
    const rows = [
      unknownMarginProduct(1001, 'missing_sale_price', { sellPrice: null }),
      unknownMarginProduct(1002, 'missing_material_price', { materialCost: null, missingPriceTypeIds: [34] }),
      unknownMarginProduct(1003, 'missing_daily_volume', { dailyVolume: null }),
    ]

    const diagnosis = diagnoseBatchScan(rows, null, null)

    expect(diagnosis).toMatchObject({ scanned: 3, withoutSalePrice: 1, withoutMaterialPrice: 1, withoutDailyVolume: 1 })
    expect(diagnosis.unknownMargin).toEqual({ byReason: {}, facilityTaxByStructure: {}, other: 0 })
  })

  it('groups the missing facility tax by the structure that has none', () => {
    const rows = [
      unknownMarginProduct(1001, 'missing_facility_tax', { unknownReasonStructureId: RAITARU_HOME_STRUCTURE_ID }),
      unknownMarginProduct(1002, 'missing_facility_tax', { unknownReasonStructureId: RAITARU_HOME_STRUCTURE_ID }),
      unknownMarginProduct(1003, 'missing_facility_tax', { unknownReasonStructureId: TATARA_REACTIONS_STRUCTURE_ID }),
      unknownMarginProduct(1004, 'missing_facility_tax', { unknownReasonStructureId: TATARA_REACTIONS_STRUCTURE_ID }),
    ]

    expect(diagnoseBatchScan(rows, null, null).unknownMargin).toEqual({
      byReason: { missing_facility_tax: 4 },
      facilityTaxByStructure: { [RAITARU_HOME_STRUCTURE_ID]: 2, [TATARA_REACTIONS_STRUCTURE_ID]: 2 },
      other: 0,
    })
  })

  it('counts a missing facility tax without structure id in the reason total but under no structure', () => {
    const rows = [
      unknownMarginProduct(1001, 'missing_facility_tax', { unknownReasonStructureId: null }),
      unknownMarginProduct(1002, 'missing_facility_tax', { unknownReasonStructureId: RAITARU_HOME_STRUCTURE_ID }),
    ]

    expect(diagnoseBatchScan(rows, null, null).unknownMargin).toEqual({
      byReason: { missing_facility_tax: 2 },
      facilityTaxByStructure: { [RAITARU_HOME_STRUCTURE_ID]: 1 },
      other: 0,
    })
  })

  it('counts a reason the front does not know under another reason instead of ignoring it', () => {
    const rows = [unknownMarginProduct(1001, 'missing_xyz'), unknownMarginProduct(1002, 'missing_cost_index')]

    expect(diagnoseBatchScan(rows, null, null).unknownMargin).toEqual({
      byReason: { missing_cost_index: 1 },
      facilityTaxByStructure: {},
      other: 1,
    })
  })

  it('counts no unknown margin for a product whose margin is known, whatever its reason', () => {
    const rows = [scannedProduct(1001, { marginPercent: 12.5, unknownReason: 'missing_sales_fees' })]

    expect(diagnoseBatchScan(rows, null, null).unknownMargin).toEqual({ byReason: {}, facilityTaxByStructure: {}, other: 0 })
  })

  it('reads the 126 products of a structure venue without minimums as 120 missing facility taxes, 3 missing sale prices and 3 missing adjusted prices', () => {
    const rows = [
      ...Array.from({ length: 120 }, (_, index) => unknownMarginProduct(2000 + index, 'missing_facility_tax', { unknownReasonStructureId: RAITARU_HOME_STRUCTURE_ID })),
      ...Array.from({ length: 3 }, (_, index) => unknownMarginProduct(3000 + index, 'missing_sale_price', { sellPrice: null })),
      ...Array.from({ length: 3 }, (_, index) => unknownMarginProduct(4000 + index, 'missing_adjusted_price')),
    ]

    const diagnosis = diagnoseBatchScan(rows, null, null)

    expect(diagnosis).toMatchObject({ scanned: 126, withoutSalePrice: 3 })
    expect(diagnosis.unknownMargin).toEqual({
      byReason: { missing_facility_tax: 120, missing_adjusted_price: 3 },
      facilityTaxByStructure: { [RAITARU_HOME_STRUCTURE_ID]: 120 },
      other: 0,
    })
  })
})

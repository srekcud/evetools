import type { BatchScanItem } from '@/stores/industry/types'

export type BatchScanDiagnosis = {
  scanned: number
  withoutSalePrice: number
  withoutMaterialPrice: number
  withoutDailyVolume: number
  belowMinMargin: number
  belowMinDailyVolume: number
  unknownMargin: {
    byReason: Record<string, number>
    facilityTaxByStructure: Record<string, number>
    other: number
  }
}

const UNKNOWN_MARGIN_REASONS = [
  'missing_cost_index',
  'missing_adjusted_price',
  'missing_facility_tax',
  'missing_sales_fees',
  'missing_invention_probability',
  'missing_market_price',
  'missing_volume',
]
const FACILITY_TAX_REASON = 'missing_facility_tax'
const REASONS_CARRIED_BY_OTHER_LINES = ['missing_sale_price', 'missing_material_price', 'missing_daily_volume']

function diagnoseUnknownMargins(rows: BatchScanItem[]): BatchScanDiagnosis['unknownMargin'] {
  const unknownMargin: BatchScanDiagnosis['unknownMargin'] = { byReason: {}, facilityTaxByStructure: {}, other: 0 }
  for (const row of rows) {
    if (row.marginPercent !== null || row.unknownReason === null) continue
    if (REASONS_CARRIED_BY_OTHER_LINES.includes(row.unknownReason)) continue
    if (!UNKNOWN_MARGIN_REASONS.includes(row.unknownReason)) {
      unknownMargin.other += 1
      continue
    }
    unknownMargin.byReason[row.unknownReason] = (unknownMargin.byReason[row.unknownReason] ?? 0) + 1
    if (row.unknownReason === FACILITY_TAX_REASON && row.unknownReasonStructureId !== null) {
      unknownMargin.facilityTaxByStructure[row.unknownReasonStructureId] = (unknownMargin.facilityTaxByStructure[row.unknownReasonStructureId] ?? 0) + 1
    }
  }
  return unknownMargin
}

export function diagnoseBatchScan(
  rows: BatchScanItem[],
  minMargin: number | null,
  minDailyVolume: number | null,
): BatchScanDiagnosis {
  return {
    scanned: rows.length,
    withoutSalePrice: rows.filter(row => row.sellPrice === null).length,
    withoutMaterialPrice: rows.filter(row => row.missingPriceTypeIds.length > 0).length,
    withoutDailyVolume: rows.filter(row => row.dailyVolume === null).length,
    belowMinMargin: minMargin === null
      ? 0
      : rows.filter(row => row.marginPercent !== null && row.marginPercent < minMargin).length,
    belowMinDailyVolume: minDailyVolume === null
      ? 0
      : rows.filter(row => row.dailyVolume !== null && row.dailyVolume < minDailyVolume).length,
    unknownMargin: diagnoseUnknownMargins(rows),
  }
}

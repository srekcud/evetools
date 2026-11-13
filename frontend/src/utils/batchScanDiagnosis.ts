import type { BatchScanItem } from '@/stores/industry/types'

export type BatchScanDiagnosis = {
  scanned: number
  withoutSalePrice: number
  withoutMaterialPrice: number
  withoutDailyVolume: number
  belowMinMargin: number
  belowMinDailyVolume: number
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
  }
}

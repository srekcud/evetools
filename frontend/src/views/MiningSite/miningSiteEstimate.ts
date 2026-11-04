import type { MiningSite, MiningSiteScan, MiningSiteScanType, MiningSiteStatus } from '@/stores/miningSite'

export const TIER_ORANGE_MINUTES = 15
export const TIER_RED_MINUTES = 5
export const IRREGULAR_DEVIATION_PERCENT = 10
export const RESCAN_MINUTES_DEFAULT = 10
export const RESCAN_MINUTES_MIN = 3
export const RESCAN_MINUTES_MAX = 30
export const RESCAN_MINUTES_STORAGE_KEY = 'miningSite.rescanMinutes'
export const CURVE_AXIS_MAX_MINUTES = 300

export type EtaTier = 'normal' | 'orange' | 'red' | 'reached' | 'depleted' | 'unknown'
export type CurveUnit = 'units' | 'm3'

export interface CurvePoint {
  minutes: number
  value: number
}

export interface CurveTypeSeries {
  typeId: number
  notDepleting: boolean
  points: CurvePoint[]
  trendLine: [CurvePoint, CurvePoint] | null
  recentSegment: [CurvePoint, CurvePoint] | null
  depletionMarker: CurvePoint | null
}

export interface CurveSeries {
  unit: CurveUnit
  m3Available: boolean
  types: CurveTypeSeries[]
}

export interface EtaAnnouncement {
  number: number
  scannedAt: string
  trendEta: string | null
  recentEta: string | null
}

export function etaTierOf(_status: MiningSiteStatus, _lastScan: MiningSiteScan, _now: Date): EtaTier | null {
  return 'unknown'
}

export function remainingVolumeRingOf(_scans: MiningSiteScan[]): number | null {
  return null
}

export function mostProfitableTypeOf(_lastScan: MiningSiteScan): MiningSiteScanType | null {
  return null
}

export function isIrregularRate(_type: MiningSiteScanType): boolean {
  return false
}

export function isRescanDue(_site: MiningSite, _now: Date, _delayMinutes: number): boolean {
  return false
}

export function clampRescanMinutes(minutes: number): number {
  return minutes
}

export function readRescanMinutes(_storage: Pick<Storage, 'getItem'>): number {
  return RESCAN_MINUTES_DEFAULT
}

export function etaEvolutionOf(_scans: MiningSiteScan[]): EtaAnnouncement[] {
  return []
}

export function curveSeriesOf(_scans: MiningSiteScan[], unit: CurveUnit): CurveSeries {
  return { unit, m3Available: false, types: [] }
}

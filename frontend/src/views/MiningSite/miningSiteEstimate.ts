import type { MiningSite, MiningSiteScan, MiningSiteScanType, MiningSiteStatus } from '@/stores/miningSite'

export const TIER_ORANGE_MINUTES = 15
export const TIER_RED_MINUTES = 5
export const IRREGULAR_DEVIATION_PERCENT = 10
export const RESCAN_MINUTES_DEFAULT = 10
export const RESCAN_MINUTES_MIN = 3
export const RESCAN_MINUTES_MAX = 30
export const RESCAN_MINUTES_STORAGE_KEY = 'miningSite.rescanMinutes'
export const CURVE_AXIS_MAX_MINUTES = 300
const MILLISECONDS_PER_MINUTE = 60_000
const PERCENT_MAX = 100

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

function chronological(scans: MiningSiteScan[]): MiningSiteScan[] {
  return [...scans].sort((first, second) => first.number - second.number)
}

function minutesBetween(from: string, to: string): number {
  return (new Date(to).getTime() - new Date(from).getTime()) / MILLISECONDS_PER_MINUTE
}

function isKnown<Value>(value: Value | null | undefined): value is Value {
  return value !== null && value !== undefined
}

export function isEverythingDepleted(scan: MiningSiteScan): boolean {
  return scan.types.length > 0 && scan.types.every(type => type.status === 'depleted')
}

export function etaTierOf(status: MiningSiteStatus, lastScan: MiningSiteScan, now: Date): EtaTier | null {
  if (isEverythingDepleted(lastScan)) return 'depleted'
  if (status === 'closed') return null
  if (lastScan.trendEta === null || lastScan.trendEta === undefined) return 'unknown'

  const remainingMinutes = (new Date(lastScan.trendEta).getTime() - now.getTime()) / MILLISECONDS_PER_MINUTE
  if (remainingMinutes <= 0) return 'reached'
  if (remainingMinutes < TIER_RED_MINUTES) return 'red'
  if (remainingMinutes < TIER_ORANGE_MINUTES) return 'orange'
  return 'normal'
}

export function remainingVolumeRingOf(scans: MiningSiteScan[]): number | null {
  const ordered = chronological(scans)
  const lastScan = ordered[ordered.length - 1]
  if (lastScan === undefined) return null

  const minedTypes = lastScan.types.filter(type => type.etaUnknownReason !== 'not_depleting')
  if (minedTypes.length === 0) return null

  let remainingVolume = 0
  let startingVolume = 0
  for (const type of minedTypes) {
    const firstAppearance = ordered.flatMap(scan => scan.types).find(entry => entry.typeId === type.typeId && entry.status === 'present')
    if (firstAppearance === undefined || !isKnown(type.volumeM3) || !isKnown(firstAppearance.volumeM3)) return null
    remainingVolume += type.volumeM3
    startingVolume += firstAppearance.volumeM3
  }
  return startingVolume > 0 ? (remainingVolume / startingVolume) * PERCENT_MAX : null
}

function valuePerCubicMetre(type: MiningSiteScanType): number | null {
  if (type.status !== 'present' || type.units <= 0 || !isKnown(type.unitPrice) || !isKnown(type.volumeM3) || type.volumeM3 <= 0) return null
  return (type.unitPrice * type.units) / type.volumeM3
}

export function mostProfitableTypeOf(lastScan: MiningSiteScan): MiningSiteScanType | null {
  let best: MiningSiteScanType | null = null
  let bestValue = 0
  for (const type of lastScan.types) {
    const value = valuePerCubicMetre(type)
    if (value !== null && (best === null || value > bestValue)) {
      best = type
      bestValue = value
    }
  }
  return best
}

export function isIrregularRate(type: MiningSiteScanType): boolean {
  return isKnown(type.maxDeviationPercent) && type.maxDeviationPercent > IRREGULAR_DEVIATION_PERCENT
}

export function isRescanDue(site: MiningSite, now: Date, delayMinutes: number): boolean {
  if (site.status !== 'active' || site.lastScanAt === null || site.lastScanAt === undefined) return false
  return now.getTime() - new Date(site.lastScanAt).getTime() > delayMinutes * MILLISECONDS_PER_MINUTE
}

export function scanAgeMinutesOf(scannedAt: string, now: number): number {
  return Math.floor((now - new Date(scannedAt).getTime()) / MILLISECONDS_PER_MINUTE)
}

export function clampRescanMinutes(minutes: number): number {
  return Math.min(RESCAN_MINUTES_MAX, Math.max(RESCAN_MINUTES_MIN, minutes))
}

export function readRescanMinutes(storage: Pick<Storage, 'getItem'>): number {
  const stored = storage.getItem(RESCAN_MINUTES_STORAGE_KEY)
  if (stored === null) return RESCAN_MINUTES_DEFAULT
  const minutes = Number.parseInt(stored, 10)
  return Number.isNaN(minutes) ? RESCAN_MINUTES_DEFAULT : clampRescanMinutes(minutes)
}

export function etaEvolutionOf(scans: MiningSiteScan[]): EtaAnnouncement[] {
  return chronological(scans)
    .reverse()
    .map(scan => ({ number: scan.number, scannedAt: scan.scannedAt, trendEta: scan.trendEta ?? null, recentEta: scan.recentEta ?? null }))
}

interface TypePoint {
  minutes: number
  entry: MiningSiteScanType
}

function pointsOfTypes(ordered: MiningSiteScan[]): Map<number, TypePoint[]> {
  const firstScan = ordered[0]
  const byType = new Map<number, TypePoint[]>()
  if (firstScan === undefined) return byType
  for (const scan of ordered) {
    const minutes = minutesBetween(firstScan.scannedAt, scan.scannedAt)
    for (const entry of scan.types) {
      byType.set(entry.typeId, [...(byType.get(entry.typeId) ?? []), { minutes, entry }])
    }
  }
  return byType
}

function lineTo(from: CurvePoint, rate: number, eta: string, firstScanAt: string, scale: number): [CurvePoint, CurvePoint] {
  const etaMinutes = minutesBetween(firstScanAt, eta)
  const endMinutes = Math.min(etaMinutes, CURVE_AXIS_MAX_MINUTES)
  return [from, { minutes: endMinutes, value: rate * (etaMinutes - endMinutes) * scale }]
}

export function curveSeriesOf(scans: MiningSiteScan[], unit: CurveUnit): CurveSeries {
  const ordered = chronological(scans)
  const firstScan = ordered[0]
  const byType = pointsOfTypes(ordered)
  const presentPoints = [...byType.values()].map(points => points.filter(point => point.entry.status === 'present'))
  const m3Available = presentPoints.every(points => points.every(point => isKnown(point.entry.volumeM3)))
  const shownUnit: CurveUnit = unit === 'm3' && m3Available ? 'm3' : 'units'

  const types: CurveTypeSeries[] = []
  for (const [typeId, allPoints] of byType) {
    const points = allPoints.filter(point => point.entry.status === 'present')
    const lastPoint = points[points.length - 1]
    const lastEntry = allPoints[allPoints.length - 1]
    if (firstScan === undefined || lastPoint === undefined || lastEntry === undefined) continue

    const scale = shownUnit === 'm3' && isKnown(lastPoint.entry.volumeM3) && lastPoint.entry.units > 0 ? lastPoint.entry.volumeM3 / lastPoint.entry.units : 1
    const valueOf = (point: TypePoint): number => (shownUnit === 'm3' && isKnown(point.entry.volumeM3) ? point.entry.volumeM3 : point.entry.units)
    const entry = lastEntry.entry
    const isPresentAtTheEnd = entry.status === 'present'
    const firstPoint = points[0]

    types.push({
      typeId,
      notDepleting: entry.etaUnknownReason === 'not_depleting',
      points: points.map(point => ({ minutes: point.minutes, value: valueOf(point) })),
      trendLine: isPresentAtTheEnd && firstPoint !== undefined && isKnown(entry.trendRate) && isKnown(entry.trendEta)
        ? lineTo({ minutes: firstPoint.minutes, value: entry.trendRate * (minutesBetween(firstScan.scannedAt, entry.trendEta) - firstPoint.minutes) * scale }, entry.trendRate, entry.trendEta, firstScan.scannedAt, scale)
        : null,
      recentSegment: isPresentAtTheEnd && isKnown(entry.recentRate) && isKnown(entry.recentEta)
        ? lineTo({ minutes: lastPoint.minutes, value: valueOf(lastPoint) }, entry.recentRate, entry.recentEta, firstScan.scannedAt, scale)
        : null,
      depletionMarker: entry.status === 'depleted' && entry.depletedBetween !== null && entry.depletedBetween !== undefined
        ? { minutes: minutesBetween(firstScan.scannedAt, entry.depletedBetween.to), value: 0 }
        : null,
    })
  }

  return { unit: shownUnit, m3Available, types }
}

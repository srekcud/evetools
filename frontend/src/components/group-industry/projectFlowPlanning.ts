import type { FlowCardState, FlowGrid, FlowRow } from './projectFlow'
import type { BomItem, JobMatch, PlanningActivity, PlanningJob, ProjectPlanning } from '@/stores/group-industry/types'

export type PlanningBarState = 'done' | 'running' | 'waiting' | 'planned'

export interface PlanningBar {
  lineId: string
  typeName: string
  activity: PlanningActivity
  runs: number
  start: number
  end: number
  state: PlanningBarState
}

export interface PlanningStation {
  key: string
  kind: 'station' | 'unresolved'
  locationId: number | null
  name: string | null
  bars: PlanningBar[]
}

export interface FlowPlanning {
  stations: PlanningStation[]
  undated: PlanningJob[]
  start: number | null
  end: number | null
}

export interface FlowPlanningInput {
  planning: ProjectPlanning
  grid: FlowGrid
  bomItems: BomItem[]
  now: number
}

const MINIMUM_AXIS_AHEAD_MS = 24 * 60 * 60 * 1000
const UNRESOLVED_KEY = 'unresolved'
const PLACED_CARD_STATES: FlowCardState[] = ['inProduction', 'toLaunch', 'out']

interface Placement {
  key: string
  kind: 'station' | 'unresolved'
  locationId: number | null
  name: string | null
}

const UNRESOLVED_PLACEMENT: Placement = { key: UNRESOLVED_KEY, kind: 'unresolved', locationId: null, name: null }

function stationKey(locationId: number): string {
  return `station:${locationId}`
}

function datesOf(job: PlanningJob): { start: number; end: number } | null {
  const period = job.real ?? job.planned
  return period === null ? null : { start: Date.parse(period.start), end: Date.parse(period.end) }
}

function stateOf(job: PlanningJob): PlanningBarState {
  if (job.real !== null) return job.real.status === 'done' ? 'done' : 'running'
  return job.wait === null ? 'planned' : 'waiting'
}

function latestLocatedMatch(line: BomItem | undefined): JobMatch | null {
  if (line === undefined) return null
  let latest: JobMatch | null = null
  for (const match of line.jobMatches) {
    if (match.facilityId === null) continue
    if (latest === null || Date.parse(match.matchedAt) > Date.parse(latest.matchedAt)) latest = match
  }
  return latest
}

function holdsTypeInPlace(row: FlowRow, typeId: number): boolean {
  return [...row.cells.inProduction, ...row.cells.out].some(card => card.typeId === typeId && PLACED_CARD_STATES.includes(card.state))
}

function placementOf(job: PlanningJob, linesById: Map<string, BomItem>, stationRows: Map<number, FlowRow>, grid: FlowGrid): Placement {
  const match = latestLocatedMatch(linesById.get(job.lineId))
  if (match !== null && match.facilityId !== null) {
    return { key: stationKey(match.facilityId), kind: 'station', locationId: match.facilityId, name: match.facilityName ?? stationRows.get(match.facilityId)?.name ?? null }
  }
  const row = grid.rows.find(candidate => candidate.kind === 'station' && candidate.locationId !== null && holdsTypeInPlace(candidate, job.typeId))
  if (row === undefined || row.locationId === null) return UNRESOLVED_PLACEMENT
  return { key: stationKey(row.locationId), kind: 'station', locationId: row.locationId, name: row.name }
}

function compareBars(left: PlanningBar, right: PlanningBar): number {
  return left.start - right.start || left.lineId.localeCompare(right.lineId)
}

function compareStations(gridOrder: Map<string, number>) {
  return (left: PlanningStation, right: PlanningStation): number => {
    if (left.kind !== right.kind) return left.kind === 'unresolved' ? 1 : -1
    const leftIndex = gridOrder.get(left.key)
    const rightIndex = gridOrder.get(right.key)
    if (leftIndex !== undefined && rightIndex !== undefined) return leftIndex - rightIndex
    if (leftIndex !== undefined || rightIndex !== undefined) return leftIndex === undefined ? 1 : -1
    if (left.name === null || right.name === null) return left.name === right.name ? 0 : left.name === null ? 1 : -1
    return left.name.localeCompare(right.name)
  }
}

export function buildFlowPlanning({ planning, grid, bomItems, now }: FlowPlanningInput): FlowPlanning {
  const linesById = new Map(bomItems.map(line => [line.id, line]))
  const stationRows = new Map<number, FlowRow>()
  const gridOrder = new Map<string, number>()
  for (const row of grid.rows) {
    if (row.kind !== 'station' || row.locationId === null) continue
    stationRows.set(row.locationId, row)
    gridOrder.set(stationKey(row.locationId), gridOrder.size)
  }

  const stationsByKey = new Map<string, PlanningStation>()
  const undated: PlanningJob[] = []
  const starts: number[] = [now]
  const ends: number[] = [now + MINIMUM_AXIS_AHEAD_MS]

  for (const job of planning.jobs) {
    const dates = datesOf(job)
    if (dates === null) {
      undated.push(job)
      continue
    }
    const placement = placementOf(job, linesById, stationRows, grid)
    let station = stationsByKey.get(placement.key)
    if (station === undefined) {
      station = { ...placement, bars: [] }
      stationsByKey.set(placement.key, station)
    }
    station.bars.push({ lineId: job.lineId, typeName: job.typeName, activity: job.activity, runs: job.runs, start: dates.start, end: dates.end, state: stateOf(job) })
    starts.push(dates.start)
    ends.push(dates.end)
  }

  const stations = [...stationsByKey.values()].sort(compareStations(gridOrder))
  for (const station of stations) station.bars.sort(compareBars)
  const hasBars = stations.length > 0
  return {
    stations,
    undated,
    start: hasBars ? Math.min(...starts) : null,
    end: hasBars ? Math.max(...ends) : null,
  }
}

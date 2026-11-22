import { jobDisplayOf, type JobDisplayState } from './jobDisplayState'
import { buildFlowGrid, type FlowCard, type FlowColumn } from './projectFlow'
import type {
  BomItem,
  GroupMemberRole,
  GroupProject,
  GroupProjectStatus,
  GroupSale,
  PlacedAction,
  TypeStationQuantities,
} from '@/stores/group-industry/types'
import { groupActionsByPlace, type PlaceGroup } from '@/utils/todoPlaces'

export interface StationProjectSource {
  project: GroupProject
  myRole: GroupMemberRole | null
  ownerName: string
  quantities: TypeStationQuantities[]
  bomItems: BomItem[]
  sales: GroupSale[]
}

export interface StationProjectRow {
  projectId: string
  projectName: string | null
  myRole: GroupMemberRole | null
  ownerName: string
  cells: Record<FlowColumn, FlowCard[]>
}

export interface StationUnavailableProject {
  projectId: string
  projectName: string | null
  reason: string
}

export interface StationAccess {
  granted: number
  unknown: number
}

export interface StationView {
  locationId: number
  name: string | null
  solarSystemName: string | null
  here: boolean | null
  occupiedLines: number
  expectedCount: number
  carryAwayCount: number
  access: StationAccess
  rows: StationProjectRow[]
  todo: PlaceGroup | null
  unavailable: StationUnavailableProject[]
}

export interface StationViewInput {
  locationId: number
  sources: StationProjectSource[]
  actions: PlacedAction[]
  now: number
}

const OPEN_PROJECT_STATUSES: GroupProjectStatus[] = ['published', 'in_progress', 'selling']
const LINE_OCCUPYING_JOB_STATES: JobDisplayState[] = ['running', 'paused', 'ready']

export function isStationProjectOpen(status: GroupProjectStatus): boolean {
  return OPEN_PROJECT_STATUSES.includes(status)
}

function isAtStation(action: PlacedAction, locationId: number): boolean {
  return action.place.kind === 'station' && action.place.locationId === locationId
}

function occupiedLinesOf(sources: StationProjectSource[], locationId: number, now: number): number {
  return sources
    .flatMap(source => source.bomItems)
    .flatMap(line => line.jobMatches)
    .filter(match => match.facilityId === locationId && LINE_OCCUPYING_JOB_STATES.includes(jobDisplayOf(match, now).state))
    .length
}

function accessOf(stationActions: PlacedAction[]): StationAccess {
  const launches = stationActions.filter(action => action.code === 'launch_job')
  return {
    granted: launches.filter(action => action.access === 'granted').length,
    unknown: launches.filter(action => action.access === 'unknown').length,
  }
}

export function buildStationView({ locationId, sources, actions, now }: StationViewInput): StationView {
  const openSources = sources.filter(source => isStationProjectOpen(source.project.status))
  const stationActions = actions.filter(action => isAtStation(action, locationId))
  const rows: StationProjectRow[] = []
  const rowNames: (string | null)[] = []
  const unavailable: StationUnavailableProject[] = []

  for (const source of openSources) {
    const { project } = source
    const grid = buildFlowGrid({
      quantities: source.quantities,
      actions: actions.filter(action => action.projectId === project.id),
      sales: source.sales,
      project,
      bomItems: source.bomItems,
      now,
    })
    if (grid.unavailableReason !== null) {
      unavailable.push({ projectId: project.id, projectName: project.name, reason: grid.unavailableReason })
    }
    const row = grid.rows.find(candidate => candidate.locationId === locationId)
    if (row === undefined) continue
    rowNames.push(row.name)
    rows.push({ projectId: project.id, projectName: project.name, myRole: source.myRole, ownerName: source.ownerName, cells: row.cells })
  }

  const todo = groupActionsByPlace(stationActions)[0] ?? null
  const namedPlace = stationActions.find(action => action.place.name !== null)?.place
  const systemPlace = stationActions.find(action => action.place.solarSystemName !== null)?.place

  return {
    locationId,
    name: namedPlace?.name ?? rowNames.find(name => name !== null) ?? null,
    solarSystemName: systemPlace?.solarSystemName ?? null,
    here: todo?.here ?? null,
    occupiedLines: occupiedLinesOf(openSources, locationId, now),
    expectedCount: rows.flatMap(row => row.cells.toBring).filter(card => card.state === 'inTransit').length,
    carryAwayCount: rows.reduce((total, row) => total + row.cells.out.length, 0),
    access: accessOf(stationActions),
    rows,
    todo,
    unavailable,
  }
}

import { activityGroupOf, type ActivityGroup } from './companionTasks'
import { jobDisplayOf, jobElapsedShareOf, type JobDisplayState } from './jobDisplayState'
import { buildProductSales } from './productSales'
import type { BomItem, GroupProject, GroupSale, PlacedAction, StationQuantity, TypeStationQuantities } from '@/stores/group-industry/types'

export type FlowColumn = 'toBring' | 'onSite' | 'inProduction' | 'out' | 'done'

export const FLOW_COLUMNS: FlowColumn[] = ['toBring', 'onSite', 'inProduction', 'out', 'done']

export type FlowCardState = 'toBring' | 'inTransit' | 'onSite' | 'consumed' | 'inProduction' | 'out' | 'toBuy' | 'toLaunch' | 'sold' | 'expected' | 'unknown'

export type FlowExpectedOrigin = 'out' | 'inProduction' | 'toLaunch' | 'toBuy'

export interface FlowCardExpected {
  origin: FlowExpectedOrigin
  fromKind: FlowRowKind
  fromName: string | null
  cardKey: string
}

export type FlowCardSource = 'stock' | 'blueprint' | 'invention'

export interface FlowCardDetails {
  activity: ActivityGroup
  source: FlowCardSource | null
  bpc: boolean
  progress: number | null
  ready: boolean
  remainingMs: number | null
  expected: FlowCardExpected | null
}

export interface FlowCard extends FlowCardDetails {
  key: string
  typeId: number | null
  typeName: string | null
  quantity: number | null
  state: FlowCardState
  unknownReason: string | null
}

type FlowCardBase = Omit<FlowCard, keyof FlowCardDetails>

export type FlowRowKind = 'market' | 'station' | 'unresolved'

export interface FlowRow {
  key: string
  kind: FlowRowKind
  locationId: number | null
  name: string | null
  here: boolean | null
  cells: Record<FlowColumn, FlowCard[]>
}

export interface FlowGrid {
  unavailableReason: string | null
  rows: FlowRow[]
}

export interface FlowGridInput {
  quantities: TypeStationQuantities[]
  actions: PlacedAction[]
  sales: GroupSale[]
  project: GroupProject
  bomItems: BomItem[]
  now: number
}

export interface FoldedCell {
  shown: FlowCard[]
  folded: FlowCard[]
  foldedKind: 'onSite' | 'consumed' | 'expected' | null
  foldLabelNames: (string | null)[]
  hasMoreNames: boolean
}

const MAX_CARDS_BEFORE_FOLD = 3
const MIN_FOLDED_CARDS = 2
const MAX_FOLD_LABEL_NAMES = 3
const STATIONS_UNAVAILABLE_REASON = 'plan_unavailable'
const CALM_STATES: FlowCardState[] = ['onSite', 'consumed']
const FULL_PROGRESS = 100
const BLUEPRINT_ACTIVITIES: ActivityGroup[] = ['copy', 'invention']
const EXPECTED_ORIGINS: FlowExpectedOrigin[] = ['out', 'inProduction', 'toLaunch', 'toBuy']
const EXPECTED_ORIGIN_OF_STATE: Partial<Record<FlowCardState, FlowExpectedOrigin>> = { out: 'out', inProduction: 'inProduction', toLaunch: 'toLaunch', toBuy: 'toBuy' }
const EXPECTED_SOURCE_COLUMNS: FlowColumn[] = ['onSite', 'inProduction', 'out']
const STOCK_SOURCE_STATES: FlowCardState[] = ['onSite', 'consumed']
const FINISHED_JOB_STATES: JobDisplayState[] = ['ready', 'delivered']
const RUNNING_JOB_STATES: JobDisplayState[] = ['running', 'paused']
const NO_PROGRESS = { progress: null, ready: false, remainingMs: null }
const UNKNOWN_DETAILS: FlowCardDetails = { activity: 'unknown', source: null, bpc: false, ...NO_PROGRESS, expected: null }

function emptyCells(): Record<FlowColumn, FlowCard[]> {
  return { toBring: [], onSite: [], inProduction: [], out: [], done: [] }
}

function compareNames(left: FlowRow, right: FlowRow): number {
  if (left.name === null || right.name === null) {
    return left.name === right.name ? 0 : left.name === null ? 1 : -1
  }
  return left.name.localeCompare(right.name)
}

function isPositive(quantity: number | null): quantity is number {
  return quantity !== null && quantity > 0
}

export function lineOfTypes(bomItems: BomItem[]): Map<number, BomItem> {
  const lines = new Map<number, BomItem>()
  for (const item of bomItems) {
    const known = lines.get(item.typeId)
    if (known === undefined || (!known.isJob && item.isJob)) lines.set(item.typeId, item)
  }
  return lines
}

function sourceOf(state: FlowCardState, line: BomItem, activity: ActivityGroup): FlowCardSource | null {
  if (activity === 'invention') return 'invention'
  if (activity === 'copy') return 'blueprint'
  const suppliedWithoutPurchase = !line.isJob && line.fulfilledQuantity > line.purchasedQuantity
  return suppliedWithoutPurchase && STOCK_SOURCE_STATES.includes(state) ? 'stock' : null
}

function progressOf(state: FlowCardState, line: BomItem, now: number): Pick<FlowCardDetails, 'progress' | 'ready' | 'remainingMs'> {
  if (!line.isJob) return NO_PROGRESS
  if (state === 'out') return { progress: FULL_PROGRESS, ready: true, remainingMs: null }
  if (state !== 'inProduction') return NO_PROGRESS
  const jobs = line.jobMatches
    .map(match => ({ runs: match.runs, display: jobDisplayOf(match, now), share: jobElapsedShareOf(match, now) }))
    .filter(job => FINISHED_JOB_STATES.includes(job.display.state) || RUNNING_JOB_STATES.includes(job.display.state))
  const finishedRuns = jobs
    .filter(job => FINISHED_JOB_STATES.includes(job.display.state))
    .reduce((total, job) => total + job.runs, 0)
  const remainingTimes = jobs.flatMap(job => (RUNNING_JOB_STATES.includes(job.display.state) && job.display.remainingMs !== null ? [job.display.remainingMs] : []))
  const remainingMs = remainingTimes.length === 0 ? null : Math.max(...remainingTimes)
  const elapsedRunsByJob: number[] = []
  for (const job of jobs) {
    if (job.share === null) return { progress: null, ready: false, remainingMs }
    elapsedRunsByJob.push(job.runs * job.share)
  }
  if (line.runs === null || line.runs <= 0) return { progress: null, ready: false, remainingMs }
  const elapsedRuns = elapsedRunsByJob.reduce((total, runs) => total + runs, 0)
  const progress = finishedRuns >= line.runs ? FULL_PROGRESS : Math.min(FULL_PROGRESS - 1, Math.round(elapsedRuns / line.runs * FULL_PROGRESS))
  return { progress, ready: progress === FULL_PROGRESS, remainingMs }
}

function detailsOf(state: FlowCardState, line: BomItem | undefined, now: number): FlowCardDetails {
  if (line === undefined) return UNKNOWN_DETAILS
  const activity = activityGroupOf(line)
  return {
    activity,
    source: sourceOf(state, line, activity),
    bpc: BLUEPRINT_ACTIVITIES.includes(activity),
    ...progressOf(state, line, now),
    expected: null,
  }
}

function stillLacks(station: StationQuantity): boolean {
  const remaining = station.needed - station.consumed
  if (remaining <= 0) return false
  if (station.toBring !== null) return station.toBring > 0
  return station.onSite === null || station.onSite < remaining
}

function lackingStationsOf(quantities: TypeStationQuantities[]): Map<number, StationQuantity[]> {
  const lacking = new Map<number, StationQuantity[]>()
  for (const type of quantities) {
    const stations = (type.stations ?? []).filter(stillLacks)
    if (stations.length > 0) lacking.set(type.typeId, stations)
  }
  return lacking
}

function expectedOf(card: FlowCard, destination: FlowRow, source: FlowRow, origin: FlowExpectedOrigin, destinationCount: number): FlowCard {
  return {
    ...card,
    key: `expected:${card.key}:${destination.key}`,
    state: 'expected',
    quantity: destinationCount === 1 ? card.quantity : null,
    unknownReason: null,
    source: null,
    progress: null,
    ready: false,
    expected: { origin, fromKind: source.kind, fromName: source.name, cardKey: card.key },
  }
}

function quantityCards(type: TypeStationQuantities, station: StationQuantity, rowKey: string): [FlowColumn, FlowCardBase][] {
  const card = (state: FlowCardState, quantity: number | null, unknownReason: string | null = null): FlowCardBase => ({
    key: `${state}:${type.typeId}:${rowKey}`,
    typeId: type.typeId,
    typeName: type.typeName,
    quantity,
    state,
    unknownReason,
  })
  const cards: [FlowColumn, FlowCardBase][] = []
  const readable = station.onSite !== null || station.out !== null || station.toBring !== null
  if (isPositive(station.toBring)) cards.push(['toBring', card('toBring', station.toBring)])
  if (isPositive(station.inTransit)) cards.push(['toBring', card('inTransit', station.inTransit)])
  if (!readable) cards.push(['onSite', card('unknown', null, station.unknownReason)])
  if (isPositive(station.onSite)) cards.push(['onSite', card('onSite', station.onSite)])
  if (station.consumed > 0) cards.push(['onSite', card('consumed', station.consumed)])
  if (station.inProduction > 0) cards.push(['inProduction', card('inProduction', station.inProduction)])
  if (isPositive(station.out)) cards.push(['out', card('out', station.out)])
  return cards
}

export function buildFlowGrid({ quantities, actions, sales, project, bomItems, now }: FlowGridInput): FlowGrid {
  const lineByTypeId = lineOfTypes(bomItems)
  const rowsByLocationId = new Map<number, FlowRow>()
  const marketRows: FlowRow[] = []
  const stationRows: FlowRow[] = []
  let unresolvedRow: FlowRow | null = null
  let unavailableReason: string | null = null

  function detailed(card: FlowCardBase): FlowCard {
    return { ...card, ...detailsOf(card.state, card.typeId === null ? undefined : lineByTypeId.get(card.typeId), now) }
  }

  function createRow(key: string, kind: FlowRowKind, locationId: number | null, name: string | null): FlowRow {
    return { key, kind, locationId, name, here: null, cells: emptyCells() }
  }

  function rowOfUnresolved(): FlowRow {
    unresolvedRow ??= createRow('unresolved', 'unresolved', null, null)
    return unresolvedRow
  }

  function rowOfLocation(locationId: number, name: string | null, kindWhenCreated: 'market' | 'station'): FlowRow {
    const known = rowsByLocationId.get(locationId)
    if (known !== undefined) {
      if (known.name === null) known.name = name
      return known
    }
    const row = createRow(`${kindWhenCreated}:${locationId}`, kindWhenCreated, locationId, name)
    rowsByLocationId.set(locationId, row)
    ;(kindWhenCreated === 'market' ? marketRows : stationRows).push(row)
    return row
  }

  function rowOfAction(action: PlacedAction, kindWhenCreated: 'market' | 'station'): FlowRow | null {
    const { place } = action
    if (place.kind === 'station' && place.locationId !== null) return rowOfLocation(place.locationId, place.name, kindWhenCreated)
    return place.kind === 'unknown' ? rowOfUnresolved() : null
  }

  for (const type of quantities) {
    if (type.stations === null) {
      unavailableReason ??= type.unknownReason ?? STATIONS_UNAVAILABLE_REASON
      continue
    }
    for (const station of type.stations) {
      const rowKey = station.locationId === null ? 'unresolved' : String(station.locationId)
      const cards = quantityCards(type, station, rowKey)
      if (cards.length === 0) continue
      const row = station.locationId === null ? rowOfUnresolved() : rowOfLocation(station.locationId, station.stationName, 'station')
      for (const [column, card] of cards) row.cells[column].push(detailed(card))
    }
  }

  for (const action of actions) {
    const isPurchase = action.code === 'source_material'
    if (!isPurchase && action.code !== 'launch_job') continue
    const row = rowOfAction(action, isPurchase ? 'market' : 'station')
    if (row === null) continue
    if (action.here === true) row.here = true
    row.cells[isPurchase ? 'onSite' : 'inProduction'].push(detailed({
      key: `action:${action.id}`,
      typeId: action.typeId,
      typeName: action.typeName,
      quantity: action.quantity,
      state: isPurchase ? 'toBuy' : 'toLaunch',
      unknownReason: action.place.kind === 'unknown' ? action.place.unknownReason : null,
    }))
  }

  if (!project.personalUse) {
    for (const product of buildProductSales(project.items, bomItems, sales)) {
      if (product.sold <= 0) continue
      const row = project.sellStructureId === null
        ? rowOfUnresolved()
        : rowOfLocation(project.sellStructureId, project.sellStructureName, 'market')
      row.cells.done.push(detailed({ key: `sold:${product.typeId}`, typeId: product.typeId, typeName: product.typeName, quantity: product.sold, state: 'sold', unknownReason: null }))
    }
  }

  const lacking = lackingStationsOf(quantities)
  const sourceRows = [...marketRows, ...stationRows, ...(unresolvedRow === null ? [] : [unresolvedRow])]
  const expectedPlacements: { rank: number; row: FlowRow; card: FlowCard }[] = []
  for (const source of sourceRows) {
    for (const column of EXPECTED_SOURCE_COLUMNS) {
      for (const card of source.cells[column]) {
        const origin = EXPECTED_ORIGIN_OF_STATE[card.state]
        if (origin === undefined || card.typeId === null) continue
        const destinations = (lacking.get(card.typeId) ?? []).filter(station => station.locationId !== source.locationId)
        for (const station of destinations) {
          const row = station.locationId === null ? rowOfUnresolved() : rowOfLocation(station.locationId, station.stationName, 'station')
          expectedPlacements.push({ rank: EXPECTED_ORIGINS.indexOf(origin), row, card: expectedOf(card, row, source, origin, destinations.length) })
        }
      }
    }
  }
  expectedPlacements.sort((left, right) => left.rank - right.rank)
  for (const { row, card } of expectedPlacements) row.cells.toBring.push(card)

  const rows = [...marketRows.sort(compareNames), ...stationRows]
  if (unresolvedRow !== null) rows.push(unresolvedRow)
  return { unavailableReason, rows }
}

function foldableOf(cards: FlowCard[]): { cards: FlowCard[]; kind: 'onSite' | 'consumed' | 'expected' } | null {
  const expectedCards = cards.filter(card => card.state === 'expected')
  if (expectedCards.length > MAX_CARDS_BEFORE_FOLD) {
    const notOutYet = expectedCards.filter(card => card.expected?.origin !== 'out')
    return notOutYet.length >= MIN_FOLDED_CARDS ? { cards: notOutYet, kind: 'expected' } : null
  }
  const calmCards = cards.filter(card => CALM_STATES.includes(card.state))
  if (cards.length <= MAX_CARDS_BEFORE_FOLD || calmCards.length < MIN_FOLDED_CARDS) return null
  return { cards: calmCards, kind: calmCards.every(card => card.state === 'consumed') ? 'consumed' : 'onSite' }
}

export function foldCell(cards: FlowCard[], open: boolean): FoldedCell {
  const foldable = foldableOf(cards)
  if (foldable === null) return { shown: cards, folded: [], foldedKind: null, foldLabelNames: [], hasMoreNames: false }
  return {
    shown: open ? cards : cards.filter(card => !foldable.cards.includes(card)),
    folded: foldable.cards,
    foldedKind: foldable.kind,
    foldLabelNames: foldable.cards.slice(0, MAX_FOLD_LABEL_NAMES).map(card => card.typeName),
    hasMoreNames: foldable.cards.length > MAX_FOLD_LABEL_NAMES,
  }
}

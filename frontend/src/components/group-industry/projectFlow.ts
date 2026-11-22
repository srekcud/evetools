import { buildProductSales } from './productSales'
import type { BomItem, GroupProject, GroupSale, PlacedAction, StationQuantity, TypeStationQuantities } from '@/stores/group-industry/types'

export type FlowColumn = 'toBring' | 'onSite' | 'inProduction' | 'out' | 'done'

export const FLOW_COLUMNS: FlowColumn[] = ['toBring', 'onSite', 'inProduction', 'out', 'done']

export type FlowCardState = 'toBring' | 'inTransit' | 'onSite' | 'consumed' | 'inProduction' | 'out' | 'toBuy' | 'toLaunch' | 'sold' | 'unknown'

export interface FlowCard {
  key: string
  typeId: number | null
  typeName: string | null
  quantity: number | null
  state: FlowCardState
  unknownReason: string | null
}

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
}

export interface FoldedCell {
  shown: FlowCard[]
  folded: FlowCard[]
  foldedKind: 'onSite' | 'consumed' | null
  foldLabelNames: (string | null)[]
  hasMoreNames: boolean
}

const MAX_CARDS_BEFORE_FOLD = 3
const MIN_CALM_CARDS_TO_FOLD = 2
const MAX_FOLD_LABEL_NAMES = 3
const STATIONS_UNAVAILABLE_REASON = 'plan_unavailable'
const CALM_STATES: FlowCardState[] = ['onSite', 'consumed']

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

function quantityCards(type: TypeStationQuantities, station: StationQuantity, rowKey: string): [FlowColumn, FlowCard][] {
  const card = (state: FlowCardState, quantity: number | null, unknownReason: string | null = null): FlowCard => ({
    key: `${state}:${type.typeId}:${rowKey}`,
    typeId: type.typeId,
    typeName: type.typeName,
    quantity,
    state,
    unknownReason,
  })
  const cards: [FlowColumn, FlowCard][] = []
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

export function buildFlowGrid({ quantities, actions, sales, project, bomItems }: FlowGridInput): FlowGrid {
  const rowsByLocationId = new Map<number, FlowRow>()
  const marketRows: FlowRow[] = []
  const stationRows: FlowRow[] = []
  let unresolvedRow: FlowRow | null = null
  let unavailableReason: string | null = null

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
      for (const [column, card] of cards) row.cells[column].push(card)
    }
  }

  for (const action of actions) {
    const isPurchase = action.code === 'source_material'
    if (!isPurchase && action.code !== 'launch_job') continue
    const row = rowOfAction(action, isPurchase ? 'market' : 'station')
    if (row === null) continue
    if (action.here === true) row.here = true
    row.cells[isPurchase ? 'onSite' : 'inProduction'].push({
      key: `action:${action.id}`,
      typeId: action.typeId,
      typeName: action.typeName,
      quantity: action.quantity,
      state: isPurchase ? 'toBuy' : 'toLaunch',
      unknownReason: action.place.kind === 'unknown' ? action.place.unknownReason : null,
    })
  }

  if (!project.personalUse) {
    for (const product of buildProductSales(project.items, bomItems, sales)) {
      if (product.sold <= 0) continue
      const row = project.sellStructureId === null
        ? rowOfUnresolved()
        : rowOfLocation(project.sellStructureId, project.sellStructureName, 'market')
      row.cells.done.push({ key: `sold:${product.typeId}`, typeId: product.typeId, typeName: product.typeName, quantity: product.sold, state: 'sold', unknownReason: null })
    }
  }

  const rows = [...marketRows.sort(compareNames), ...stationRows]
  if (unresolvedRow !== null) rows.push(unresolvedRow)
  return { unavailableReason, rows }
}

export function foldCell(cards: FlowCard[], open: boolean): FoldedCell {
  const calmCards = cards.filter(card => CALM_STATES.includes(card.state))
  if (cards.length <= MAX_CARDS_BEFORE_FOLD || calmCards.length < MIN_CALM_CARDS_TO_FOLD) {
    return { shown: cards, folded: [], foldedKind: null, foldLabelNames: [], hasMoreNames: false }
  }
  return {
    shown: open ? cards : cards.filter(card => !calmCards.includes(card)),
    folded: calmCards,
    foldedKind: calmCards.every(card => card.state === 'consumed') ? 'consumed' : 'onSite',
    foldLabelNames: calmCards.slice(0, MAX_FOLD_LABEL_NAMES).map(card => card.typeName),
    hasMoreNames: calmCards.length > MAX_FOLD_LABEL_NAMES,
  }
}

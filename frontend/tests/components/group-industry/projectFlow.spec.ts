import { buildFlowGrid, foldCell, type FlowCard, type FlowColumn, type FlowGrid, type FlowRow } from '@/components/group-industry/projectFlow'
import type { PlacedAction } from '@/stores/group-industry/types'
import {
  AMARR_ID,
  AMARR_NAME,
  COMPONENT_X,
  ISHTAR,
  JITA_ID,
  JITA_NAME,
  MEXALLON,
  STATION_A_ID,
  STATION_B_ID,
  STATION_C_ID,
  TRITANIUM,
  ZYDRINE,
  finalLine,
  flowCard,
  projectOf,
  saleOf,
  stationQuantity,
  typeQuantities,
} from './projectFlowFixtures'
import {
  AZBEL_PLACE,
  JITA_PLACE,
  placedAction,
  stationPlace,
  unknownPlace,
} from '../../todoPlaceFixtures'

const AZBEL = { locationId: STATION_A_ID, stationName: 'Azbel' }
const ATHANOR = { locationId: STATION_B_ID, stationName: 'Athanor' }
const AMARR_PLACE = stationPlace(AMARR_ID, AMARR_NAME, 'Amarr')
const RAITARU_PLACE = stationPlace(STATION_C_ID, 'Raitaru', 'Perimeter')

function gridOf(input: {
  quantities?: ReturnType<typeof typeQuantities>[]
  actions?: PlacedAction[]
  sales?: ReturnType<typeof saleOf>[]
  project?: ReturnType<typeof projectOf>
  bomItems?: ReturnType<typeof finalLine>[]
}): FlowGrid {
  return buildFlowGrid({
    quantities: input.quantities ?? [],
    actions: input.actions ?? [],
    sales: input.sales ?? [],
    project: input.project ?? projectOf(),
    bomItems: input.bomItems ?? [],
  })
}

function rowNamed(grid: FlowGrid, name: string | null): FlowRow {
  const row = grid.rows.find(candidate => candidate.name === name)
  if (row === undefined) throw new Error(`no row named ${String(name)}`)
  return row
}

function cellOf(row: FlowRow, column: FlowColumn): [string, string | null, number | null][] {
  return row.cells[column].map(card => [card.state, card.typeName, card.quantity])
}

function cardOf(row: FlowRow, column: FlowColumn, index = 0): FlowCard {
  return row.cells[column][index]
}

function rowNames(grid: FlowGrid): (string | null)[] {
  return grid.rows.map(row => row.name)
}

describe('buildFlowGrid cards from the quantities by station', () => {
  it('puts what is to bring in the to bring column and what is on site and consumed in the on site column', () => {
    const grid = gridOf({
      quantities: [typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, needed: 150, toBring: 40, onSite: 60, consumed: 50, out: 0 })])],
    })

    expect(grid.unavailableReason).toBeNull()
    expect(grid.rows).toHaveLength(1)
    const row = grid.rows[0]
    expect(row).toMatchObject({ kind: 'station', locationId: STATION_A_ID, name: 'Azbel', here: null })
    expect(cellOf(row, 'toBring')).toEqual([['toBring', 'Zydrine', 40]])
    expect(cellOf(row, 'onSite')).toEqual([['onSite', 'Zydrine', 60], ['consumed', 'Zydrine', 50]])
    expect(cellOf(row, 'inProduction')).toEqual([])
    expect(cellOf(row, 'out')).toEqual([])
    expect(cellOf(row, 'done')).toEqual([])
  })

  it('puts what is in production in the in production column and makes no on site card for a zero quantity', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, needed: 40, inProduction: 40, onSite: 0 })])],
    })

    const row = grid.rows[0]
    expect(cellOf(row, 'inProduction')).toEqual([['inProduction', 'Capital Armor Plates', 40]])
    expect(cellOf(row, 'onSite')).toEqual([])
  })

  it('puts what left the container in the out column and makes no card for the null on site quantity', () => {
    const grid = gridOf({
      quantities: [typeQuantities(MEXALLON, [stationQuantity({ ...AZBEL, onSite: null, out: 12, toBring: 0 })])],
    })

    const row = grid.rows[0]
    expect(cellOf(row, 'out')).toEqual([['out', 'Mexallon', 12]])
    expect(cellOf(row, 'onSite')).toEqual([])
    expect(cellOf(row, 'toBring')).toEqual([])
  })

  it('makes one unknown card with a null quantity and the reason when the station quantities are unreadable', () => {
    const grid = gridOf({
      quantities: [typeQuantities(TRITANIUM, [stationQuantity({
        ...AZBEL,
        needed: 900,
        onSite: null,
        out: null,
        toBring: null,
        inTransit: null,
        unknownReason: 'corporation_assets_not_synced',
      })])],
    })

    const row = grid.rows[0]
    expect(row.cells.onSite).toHaveLength(1)
    expect(cardOf(row, 'onSite')).toMatchObject({
      state: 'unknown',
      typeId: 34,
      typeName: 'Tritanium',
      quantity: null,
      unknownReason: 'corporation_assets_not_synced',
    })
    expect(cellOf(row, 'toBring')).toEqual([])
  })

  it('puts what is in transit in the to bring column', () => {
    const grid = gridOf({
      quantities: [typeQuantities(TRITANIUM, [stationQuantity({ ...ATHANOR, needed: 25, inTransit: 25, toBring: 0 })])],
    })

    expect(cellOf(grid.rows[0], 'toBring')).toEqual([['inTransit', 'Tritanium', 25]])
  })

  it('makes no card and no zero for an unreadable in transit quantity', () => {
    const grid = gridOf({
      quantities: [typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, toBring: 15, onSite: 10, inTransit: null })])],
    })

    const row = grid.rows[0]
    expect(cellOf(row, 'toBring')).toEqual([['toBring', 'Zydrine', 15]])
    expect(cellOf(row, 'onSite')).toEqual([['onSite', 'Zydrine', 10]])
  })

  it('makes no row for a station where every quantity is zero', () => {
    const grid = gridOf({
      quantities: [typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, needed: 30 })])],
    })

    expect(grid.rows).toEqual([])
    expect(grid.unavailableReason).toBeNull()
  })

  it('gives no row and the reason of the type when its stations are unavailable', () => {
    const grid = gridOf({
      quantities: [typeQuantities(TRITANIUM, null, 'plan_unavailable')],
    })

    expect(grid.unavailableReason).toBe('plan_unavailable')
    expect(grid.rows).toEqual([])
  })

  it('keeps the rows of the other types when one type is unavailable', () => {
    const grid = gridOf({
      quantities: [
        typeQuantities(TRITANIUM, null, 'plan_unavailable'),
        typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, toBring: 40 })]),
      ],
    })

    expect(grid.unavailableReason).toBe('plan_unavailable')
    expect(grid.rows).toHaveLength(1)
    expect(cellOf(grid.rows[0], 'toBring')).toEqual([['toBring', 'Zydrine', 40]])
  })

  it('keeps the order of the types inside a cell', () => {
    const grid = gridOf({
      quantities: [
        typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, toBring: 40 })]),
        typeQuantities(TRITANIUM, [stationQuantity({ ...AZBEL, toBring: 700 })]),
        typeQuantities(MEXALLON, [stationQuantity({ ...AZBEL, toBring: 90 })]),
      ],
    })

    expect(grid.rows).toHaveLength(1)
    expect(cellOf(grid.rows[0], 'toBring')).toEqual([
      ['toBring', 'Zydrine', 40],
      ['toBring', 'Tritanium', 700],
      ['toBring', 'Mexallon', 90],
    ])
  })

  it('names a station without name with a null name instead of a made up one', () => {
    const grid = gridOf({
      quantities: [typeQuantities(ZYDRINE, [stationQuantity({ locationId: STATION_B_ID, stationName: null, toBring: 8 })])],
    })

    expect(grid.rows[0]).toMatchObject({ kind: 'station', locationId: STATION_B_ID, name: null })
  })

  it('puts the entries without location in the unresolved row, after the stations', () => {
    const grid = gridOf({
      quantities: [
        typeQuantities(ZYDRINE, [
          stationQuantity({ locationId: null, stationName: null, onSite: 33 }),
          stationQuantity({ ...AZBEL, toBring: 40 }),
        ]),
      ],
    })

    expect(grid.rows.map(row => row.kind)).toEqual(['station', 'unresolved'])
    const unresolved = grid.rows[1]
    expect(unresolved.locationId).toBeNull()
    expect(cellOf(unresolved, 'onSite')).toEqual([['onSite', 'Zydrine', 33]])
  })
})

describe('buildFlowGrid cards from the actions by place', () => {
  it('puts a purchase at a market in a market row, as a to buy card in the on site column', () => {
    const grid = gridOf({
      actions: [placedAction({ id: 'buy-1', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: 400 })],
    })

    expect(grid.rows).toHaveLength(1)
    expect(grid.rows[0]).toMatchObject({ kind: 'market', locationId: JITA_ID, name: JITA_NAME })
    expect(cellOf(grid.rows[0], 'onSite')).toEqual([['toBuy', 'Tritanium', 400]])
  })

  it('puts a purchase at a station of the plan in the row of that station', () => {
    const grid = gridOf({
      quantities: [typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, toBring: 40 })])],
      actions: [placedAction({ id: 'buy-2', code: 'source_material', place: AZBEL_PLACE, ...MEXALLON, quantity: 250 })],
    })

    expect(grid.rows).toHaveLength(1)
    expect(grid.rows[0].locationId).toBe(STATION_A_ID)
    expect(cellOf(grid.rows[0], 'onSite')).toEqual([['toBuy', 'Mexallon', 250]])
    expect(cellOf(grid.rows[0], 'toBring')).toEqual([['toBring', 'Zydrine', 40]])
  })

  it('puts a purchase whose market is not chosen in the unresolved row with the reason of the place', () => {
    const grid = gridOf({
      actions: [placedAction({ id: 'buy-3', code: 'source_material', place: unknownPlace('market_not_chosen'), ...ZYDRINE, quantity: 90 })],
    })

    expect(grid.rows).toHaveLength(1)
    expect(grid.rows[0]).toMatchObject({ kind: 'unresolved', locationId: null })
    expect(cardOf(grid.rows[0], 'onSite')).toMatchObject({
      state: 'toBuy',
      typeName: 'Zydrine',
      quantity: 90,
      unknownReason: 'market_not_chosen',
    })
  })

  it('puts a job to launch at a station in the in production column of its row', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, toBring: 12 })])],
      actions: [placedAction({ id: 'launch-1', code: 'launch_job', place: AZBEL_PLACE, ...COMPONENT_X, quantity: 5 })],
    })

    expect(grid.rows).toHaveLength(1)
    expect(cellOf(grid.rows[0], 'inProduction')).toEqual([['toLaunch', 'Capital Armor Plates', 5]])
  })

  it('creates the row of a station missing from the quantities when an action cites it', () => {
    const grid = gridOf({
      quantities: [typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, toBring: 40 })])],
      actions: [placedAction({ id: 'launch-2', code: 'launch_job', place: RAITARU_PLACE, ...MEXALLON, quantity: 3 })],
    })

    expect(grid.rows).toHaveLength(2)
    expect(grid.rows[1]).toMatchObject({ kind: 'station', locationId: STATION_C_ID, name: 'Raitaru' })
    expect(cellOf(grid.rows[1], 'inProduction')).toEqual([['toLaunch', 'Mexallon', 3]])
  })

  it('puts a job to launch at an unknown place in the unresolved row', () => {
    const grid = gridOf({
      actions: [placedAction({ id: 'launch-3', code: 'launch_job', place: unknownPlace('station_unresolved'), ...COMPONENT_X, quantity: 2 })],
    })

    expect(grid.rows).toHaveLength(1)
    expect(grid.rows[0].kind).toBe('unresolved')
    expect(cellOf(grid.rows[0], 'inProduction')).toEqual([['toLaunch', 'Capital Armor Plates', 2]])
  })

  it('makes no card and no row for the review actions', () => {
    const grid = gridOf({
      actions: [
        placedAction({ id: 'review-1', code: 'review_contributions', place: { ...AZBEL_PLACE, kind: 'anywhere' }, quantity: 4 }),
        placedAction({ id: 'review-2', code: 'review_join_requests', place: { ...AZBEL_PLACE, kind: 'anywhere' }, quantity: 2 }),
      ],
    })

    expect(grid.rows).toEqual([])
    expect(grid.unavailableReason).toBeNull()
  })

  it('makes two cards, with distinct keys, for two actions on the same type at the same place', () => {
    const grid = gridOf({
      actions: [
        placedAction({ id: 'launch-4', code: 'launch_job', place: AZBEL_PLACE, bomLineId: 'line-1', ...COMPONENT_X, quantity: 5 }),
        placedAction({ id: 'launch-5', code: 'launch_job', place: AZBEL_PLACE, bomLineId: 'line-2', ...COMPONENT_X, quantity: 8 }),
      ],
    })

    const cards = grid.rows[0].cells.inProduction
    expect(cards.map(card => card.quantity)).toEqual([5, 8])
    expect(new Set(cards.map(card => card.key)).size).toBe(2)
  })

  it('keeps a null type name for an action without name, the component translates the fallback', () => {
    const grid = gridOf({
      actions: [placedAction({ id: 'buy-4', code: 'source_material', place: JITA_PLACE, typeId: 35, typeName: null, quantity: 70 })],
    })

    expect(cardOf(grid.rows[0], 'onSite')).toMatchObject({ state: 'toBuy', typeId: 35, typeName: null, quantity: 70 })
  })

  it('keeps a null quantity for an action without quantity, never zero', () => {
    const grid = gridOf({
      actions: [
        placedAction({ id: 'buy-5', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: null }),
        placedAction({ id: 'launch-6', code: 'launch_job', place: AZBEL_PLACE, ...COMPONENT_X, quantity: null }),
      ],
    })

    expect(cardOf(rowNamed(grid, JITA_NAME), 'onSite').quantity).toBeNull()
    expect(cardOf(rowNamed(grid, 'Azbel'), 'inProduction').quantity).toBeNull()
  })

  it('marks a row as here when one of its actions is here and leaves it null otherwise', () => {
    const grid = gridOf({
      actions: [
        placedAction({ id: 'launch-7', code: 'launch_job', place: AZBEL_PLACE, ...COMPONENT_X, quantity: 5, here: false }),
        placedAction({ id: 'launch-8', code: 'launch_job', place: AZBEL_PLACE, ...MEXALLON, quantity: 6, here: true }),
        placedAction({ id: 'buy-6', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: 400, here: false }),
        placedAction({ id: 'buy-7', code: 'source_material', place: AMARR_PLACE, ...ZYDRINE, quantity: 30, here: null }),
      ],
    })

    expect(rowNamed(grid, 'Azbel').here).toBe(true)
    expect(rowNamed(grid, JITA_NAME).here).toBeNull()
    expect(rowNamed(grid, AMARR_NAME).here).toBeNull()
  })
})

describe('buildFlowGrid rows order', () => {
  it('orders the market rows by name, then the stations of the server, then the stations of the actions, then the unresolved row', () => {
    const grid = gridOf({
      quantities: [
        typeQuantities(ZYDRINE, [
          stationQuantity({ locationId: null, stationName: null, onSite: 9 }),
          stationQuantity({ ...ATHANOR, toBring: 40 }),
          stationQuantity({ ...AZBEL, toBring: 41 }),
        ]),
      ],
      actions: [
        placedAction({ id: 'buy-8', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: 400 }),
        placedAction({ id: 'launch-9', code: 'launch_job', place: RAITARU_PLACE, ...COMPONENT_X, quantity: 5 }),
        placedAction({ id: 'buy-9', code: 'source_material', place: AMARR_PLACE, ...MEXALLON, quantity: 60 }),
      ],
    })

    expect(rowNames(grid)).toEqual([AMARR_NAME, JITA_NAME, 'Athanor', 'Azbel', 'Raitaru', null])
    expect(grid.rows.map(row => row.kind)).toEqual(['market', 'market', 'station', 'station', 'station', 'unresolved'])
  })
})

describe('buildFlowGrid sold column', () => {
  const ISHTAR_PROJECT_ITEMS = [ISHTAR]

  it('puts the quantity sold of a product in the done column of the sell structure row, created as a market row', () => {
    const grid = gridOf({
      project: projectOf({ items: ISHTAR_PROJECT_ITEMS, sellStructureId: JITA_ID, sellStructureName: JITA_NAME }),
      bomItems: [finalLine(12_005, 30)],
      sales: [saleOf(12_005, 'Ishtar', 12), saleOf(12_005, 'Ishtar', 8)],
    })

    expect(grid.rows).toHaveLength(1)
    expect(grid.rows[0]).toMatchObject({ kind: 'market', locationId: JITA_ID, name: JITA_NAME })
    expect(cellOf(grid.rows[0], 'done')).toEqual([['sold', 'Ishtar', 20]])
  })

  it('puts the sold card in the existing row of the sell structure', () => {
    const grid = gridOf({
      quantities: [typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, toBring: 40 })])],
      project: projectOf({ items: ISHTAR_PROJECT_ITEMS, sellStructureId: STATION_A_ID, sellStructureName: 'Azbel' }),
      bomItems: [finalLine(12_005, 30)],
      sales: [saleOf(12_005, 'Ishtar', 20)],
    })

    expect(grid.rows).toHaveLength(1)
    expect(cellOf(grid.rows[0], 'done')).toEqual([['sold', 'Ishtar', 20]])
    expect(cellOf(grid.rows[0], 'toBring')).toEqual([['toBring', 'Zydrine', 40]])
  })

  it('puts the sold card in the unresolved row when the project has no sell structure', () => {
    const grid = gridOf({
      project: projectOf({ items: ISHTAR_PROJECT_ITEMS }),
      bomItems: [finalLine(12_005, 30)],
      sales: [saleOf(12_005, 'Ishtar', 20)],
    })

    expect(grid.rows).toHaveLength(1)
    expect(grid.rows[0].kind).toBe('unresolved')
    expect(cellOf(grid.rows[0], 'done')).toEqual([['sold', 'Ishtar', 20]])
  })

  it('makes no sold card for a product nothing was sold of', () => {
    const grid = gridOf({
      project: projectOf({ items: ISHTAR_PROJECT_ITEMS, sellStructureId: JITA_ID, sellStructureName: JITA_NAME }),
      bomItems: [finalLine(12_005, 30)],
      sales: [],
    })

    expect(grid.rows).toEqual([])
  })

  it('makes no sold card for a personal use project', () => {
    const grid = gridOf({
      project: projectOf({ items: ISHTAR_PROJECT_ITEMS, sellStructureId: JITA_ID, sellStructureName: JITA_NAME, personalUse: true }),
      bomItems: [finalLine(12_005, 30)],
      sales: [saleOf(12_005, 'Ishtar', 20)],
    })

    expect(grid.rows).toEqual([])
  })
})

describe('buildFlowGrid without data', () => {
  it('has no row and no unavailable reason for a project without quantity and without action', () => {
    expect(gridOf({})).toEqual({ unavailableReason: null, rows: [] })
  })
})

describe('foldCell', () => {
  function namedCards(): FlowCard[] {
    return [
      flowCard('toBuy', { typeName: 'Tritanium', quantity: 400 }),
      flowCard('onSite', { typeName: 'a-Pyerite', quantity: 10 }),
      flowCard('onSite', { typeName: 'b-Isogen', quantity: 20 }),
      flowCard('consumed', { typeName: 'c-Nocxium', quantity: 30 }),
      flowCard('onSite', { typeName: 'd-Megacyte', quantity: 40 }),
    ]
  }

  it('folds the quiet cards of a cell of more than three cards and lists the first three names', () => {
    const cards = namedCards()

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual([cards[0]])
    expect(folded.folded).toEqual(cards.slice(1))
    expect(folded.foldedKind).toBe('onSite')
    expect(folded.foldLabelNames).toEqual(['a-Pyerite', 'b-Isogen', 'c-Nocxium'])
    expect(folded.hasMoreNames).toBe(true)
  })

  it('shows every card, in order, once the cell is open', () => {
    const cards = namedCards()

    expect(foldCell(cards, true).shown).toEqual(cards)
  })

  it('does not mark more names when exactly three cards are folded', () => {
    const cards = [
      flowCard('toBuy', { typeName: 'Tritanium', quantity: 400 }),
      flowCard('toBuy', { typeName: 'Zydrine', quantity: 90 }),
      flowCard('onSite', { typeName: 'a-Pyerite', quantity: 10 }),
      flowCard('onSite', { typeName: 'b-Isogen', quantity: 20 }),
      flowCard('consumed', { typeName: 'c-Nocxium', quantity: 30 }),
    ]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual(cards.slice(0, 2))
    expect(folded.foldLabelNames).toEqual(['a-Pyerite', 'b-Isogen', 'c-Nocxium'])
    expect(folded.hasMoreNames).toBe(false)
  })

  it('folds two quiet cards among four, the lowest count that folds', () => {
    const cards = [
      flowCard('toBuy', { typeName: 'Tritanium', quantity: 400 }),
      flowCard('unknown', { typeName: 'Zydrine', quantity: null, unknownReason: 'transfer_unreadable' }),
      flowCard('onSite', { typeName: 'a-Pyerite', quantity: 10 }),
      flowCard('consumed', { typeName: 'b-Isogen', quantity: 20 }),
    ]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual(cards.slice(0, 2))
    expect(folded.folded).toEqual(cards.slice(2))
    expect(folded.foldLabelNames).toEqual(['a-Pyerite', 'b-Isogen'])
    expect(folded.hasMoreNames).toBe(false)
  })

  it('folds nothing for a cell of three cards, even all quiet', () => {
    const cards = [
      flowCard('onSite', { typeName: 'a-Pyerite', quantity: 10 }),
      flowCard('onSite', { typeName: 'b-Isogen', quantity: 20 }),
      flowCard('consumed', { typeName: 'c-Nocxium', quantity: 30 }),
    ]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual(cards)
    expect(folded.folded).toEqual([])
    expect(folded.foldedKind).toBeNull()
    expect(folded.foldLabelNames).toEqual([])
    expect(folded.hasMoreNames).toBe(false)
  })

  it('folds nothing for a cell of more than three cards with a single quiet card', () => {
    const cards = [
      flowCard('toBuy', { typeName: 'Tritanium', quantity: 400 }),
      flowCard('toBuy', { typeName: 'Zydrine', quantity: 90 }),
      flowCard('unknown', { typeName: 'Mexallon', quantity: null, unknownReason: 'transfer_unreadable' }),
      flowCard('onSite', { typeName: 'a-Pyerite', quantity: 10 }),
    ]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual(cards)
    expect(folded.folded).toEqual([])
    expect(folded.foldedKind).toBeNull()
  })

  it('names the fold after the consumed cards when every folded card is consumed', () => {
    const cards = [
      flowCard('consumed', { typeName: 'a-Pyerite', quantity: 10 }),
      flowCard('consumed', { typeName: 'b-Isogen', quantity: 20 }),
      flowCard('consumed', { typeName: 'c-Nocxium', quantity: 30 }),
      flowCard('consumed', { typeName: 'd-Megacyte', quantity: 40 }),
    ]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual([])
    expect(folded.folded).toEqual(cards)
    expect(folded.foldedKind).toBe('consumed')
    expect(folded.foldLabelNames).toEqual(['a-Pyerite', 'b-Isogen', 'c-Nocxium'])
    expect(folded.hasMoreNames).toBe(true)
  })
})

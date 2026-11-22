import { buildFlowGrid, foldCell, type FlowCard, type FlowGrid, type FlowRow } from '@/components/group-industry/projectFlow'
import type { BomItem, JobMatch, PlacedAction } from '@/stores/group-industry/types'
import {
  COMPONENT_X,
  JITA_NAME,
  STATION_A_ID,
  STATION_B_ID,
  STATION_C_ID,
  TRITANIUM,
  ZYDRINE,
  flowCard,
  projectOf,
  stationQuantity,
  typeQuantities,
} from './projectFlowFixtures'
import { AZBEL_PLACE, JITA_PLACE, placedAction, unknownPlace } from '../../todoPlaceFixtures'

const NOW = Date.parse('2026-11-22T12:00:00Z')
const THREE_HOURS_MS = 3 * 60 * 60 * 1000
const AZBEL = { locationId: STATION_A_ID, stationName: 'Azbel' }
const ATHANOR = { locationId: STATION_B_ID, stationName: 'Athanor' }
const RAITARU = { locationId: STATION_C_ID, stationName: 'Raitaru' }
const UNRESOLVED = { locationId: null, stationName: null }

const REACTION_LINE = { typeId: COMPONENT_X.typeId, isJob: true, activityType: 'reaction', runs: 10, fulfilledQuantity: 0, purchasedQuantity: 0, jobMatches: [] as JobMatch[] } as unknown as BomItem

function gridOf(input: {
  quantities: ReturnType<typeof typeQuantities>[]
  actions?: PlacedAction[]
  bomItems?: BomItem[]
}): FlowGrid {
  return buildFlowGrid({
    quantities: input.quantities,
    actions: input.actions ?? [],
    sales: [],
    project: projectOf(),
    bomItems: input.bomItems ?? [],
    now: NOW,
  })
}

function rowNamed(grid: FlowGrid, name: string | null): FlowRow {
  const row = grid.rows.find(candidate => candidate.name === name)
  if (row === undefined) throw new Error(`no row named ${String(name)}`)
  return row
}

function expectedOf(row: FlowRow): FlowCard[] {
  return row.cells.toBring.filter(card => card.state === 'expected')
}

const LACKING_AT_ATHANOR = stationQuantity({ ...ATHANOR, needed: 40, toBring: 40, onSite: 0 })

describe('expected cards of a station', () => {
  it('shows what is out elsewhere dashed in the to bring cell of the station that lacks it', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40 }), LACKING_AT_ATHANOR])],
      bomItems: [REACTION_LINE],
    })

    const athanor = rowNamed(grid, 'Athanor')

    expect(athanor.cells.toBring.map(card => card.state)).toEqual(['toBring', 'expected'])
    expect(expectedOf(athanor)[0]).toMatchObject({
      typeId: COMPONENT_X.typeId,
      typeName: COMPONENT_X.typeName,
      quantity: 40,
      activity: 'reaction',
      expected: { origin: 'out', fromKind: 'station', fromName: 'Azbel', cardKey: `out:${COMPONENT_X.typeId}:${STATION_A_ID}` },
    })
  })

  it('keeps the source card where it is', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40 }), LACKING_AT_ATHANOR])],
      bomItems: [REACTION_LINE],
    })

    expect(rowNamed(grid, 'Azbel').cells.out.map(card => [card.state, card.quantity])).toEqual([['out', 40]])
  })

  it('gives the dashed card its own key, distinct from the source card', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40 }), LACKING_AT_ATHANOR])],
      bomItems: [REACTION_LINE],
    })

    const keys = grid.rows.flatMap(row => Object.values(row.cells).flat().map(card => card.key))

    expect(new Set(keys).size).toBe(keys.length)
  })

  it('shows what is in production elsewhere with its time left and no progress bar', () => {
    const activeJob = { status: 'active', runs: 10, endDate: new Date(NOW + THREE_HOURS_MS).toISOString() } as JobMatch
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, inProduction: 40 }), LACKING_AT_ATHANOR])],
      bomItems: [{ ...REACTION_LINE, jobMatches: [activeJob] }],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))[0]).toMatchObject({
      quantity: 40,
      progress: null,
      ready: false,
      remainingMs: THREE_HOURS_MS,
      expected: { origin: 'inProduction', fromName: 'Azbel', cardKey: `inProduction:${COMPONENT_X.typeId}:${STATION_A_ID}` },
    })
  })

  it('shows a job to launch elsewhere with the quantity of the action', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [LACKING_AT_ATHANOR])],
      actions: [placedAction({ id: 'launch-1', code: 'launch_job', place: AZBEL_PLACE, ...COMPONENT_X, quantity: 25 })],
      bomItems: [REACTION_LINE],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))[0]).toMatchObject({
      quantity: 25,
      expected: { origin: 'toLaunch', fromName: 'Azbel', cardKey: 'action:launch-1' },
    })
  })

  it('shows a purchase on the market dashed in the station that needs the material', () => {
    const grid = gridOf({
      quantities: [typeQuantities(TRITANIUM, [LACKING_AT_ATHANOR])],
      actions: [placedAction({ id: 'buy-1', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: 400 })],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))[0]).toMatchObject({
      quantity: 400,
      expected: { origin: 'toBuy', fromKind: 'market', fromName: JITA_NAME, cardKey: 'action:buy-1' },
    })
  })

  it('orders the dashed cards out, in production, to launch, then to buy, after the solid cards', () => {
    const grid = gridOf({
      quantities: [
        typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40, inProduction: 10 }), LACKING_AT_ATHANOR]),
        typeQuantities(TRITANIUM, [LACKING_AT_ATHANOR]),
      ],
      actions: [
        placedAction({ id: 'buy-1', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: 400 }),
        placedAction({ id: 'launch-1', code: 'launch_job', place: AZBEL_PLACE, ...COMPONENT_X, quantity: 25 }),
      ],
      bomItems: [REACTION_LINE],
    })

    expect(rowNamed(grid, 'Athanor').cells.toBring.map(card => card.expected?.origin ?? card.state)).toEqual(['toBring', 'toBring', 'out', 'inProduction', 'toLaunch', 'toBuy'])
  })

  it('does not show a dashed card in the row of its source', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, needed: 40, toBring: 40, onSite: 0, inProduction: 40 })])],
      bomItems: [REACTION_LINE],
    })

    expect(expectedOf(rowNamed(grid, 'Azbel'))).toEqual([])
  })

  it('shows nothing for a station that already holds what it needs', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40 }), stationQuantity({ ...ATHANOR, needed: 40, toBring: 0, onSite: 40 })])],
      bomItems: [REACTION_LINE],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))).toEqual([])
  })

  it('shows nothing for a station whose need is already consumed', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40 }), stationQuantity({ ...ATHANOR, needed: 40, consumed: 40, toBring: 0, onSite: 0 })])],
      bomItems: [REACTION_LINE],
    })

    expect(grid.rows.flatMap(expectedOf)).toEqual([])
  })

  it('shows nothing when no station needs the type', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40 })])],
      bomItems: [REACTION_LINE],
    })

    expect(grid.rows.map(row => row.name)).toEqual(['Azbel'])
    expect(grid.rows.flatMap(expectedOf)).toEqual([])
  })

  it('gives nothing to expect from what is on site, consumed or sold', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, onSite: 10, consumed: 5 }), LACKING_AT_ATHANOR])],
      bomItems: [REACTION_LINE],
    })

    expect(grid.rows.flatMap(expectedOf)).toEqual([])
  })

  it('does not match a source of another type', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40 })]), typeQuantities(ZYDRINE, [LACKING_AT_ATHANOR])],
      bomItems: [REACTION_LINE],
    })

    expect(grid.rows.flatMap(expectedOf)).toEqual([])
  })

  it('leaves the quantity empty when several stations lack the type', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [
        stationQuantity({ ...AZBEL, out: 40 }),
        LACKING_AT_ATHANOR,
        stationQuantity({ ...RAITARU, needed: 30, toBring: 30, onSite: 0 }),
      ])],
      bomItems: [REACTION_LINE],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))[0]).toMatchObject({ quantity: null, expected: { cardKey: `out:${COMPONENT_X.typeId}:${STATION_A_ID}` } })
    expect(expectedOf(rowNamed(grid, 'Raitaru'))[0]).toMatchObject({ quantity: null, expected: { cardKey: `out:${COMPONENT_X.typeId}:${STATION_A_ID}` } })
  })

  it('leaves the quantity empty when the source action has none', () => {
    const grid = gridOf({
      quantities: [typeQuantities(TRITANIUM, [LACKING_AT_ATHANOR])],
      actions: [placedAction({ id: 'buy-2', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: null })],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))[0].quantity).toBeNull()
  })

  it('shows the dashed card in a station whose transfers are unreadable but that still lacks the type', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [
        stationQuantity({ ...AZBEL, out: 40 }),
        stationQuantity({ ...ATHANOR, needed: 40, onSite: 10, toBring: null, inTransit: null, unknownReason: 'transfer_unreadable' }),
      ])],
      bomItems: [REACTION_LINE],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))).toHaveLength(1)
  })

  it('shows the dashed card in a station that cannot be read at all', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [
        stationQuantity({ ...AZBEL, out: 40 }),
        stationQuantity({ ...ATHANOR, needed: 40, onSite: null, out: null, toBring: null, inTransit: null, unknownReason: 'no_container_at_station' }),
      ])],
      bomItems: [REACTION_LINE],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))).toHaveLength(1)
  })

  it('shows nothing for a station whose transfers are unreadable but that holds the whole need', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [
        stationQuantity({ ...AZBEL, out: 40 }),
        stationQuantity({ ...ATHANOR, needed: 40, onSite: 40, toBring: null, inTransit: null, unknownReason: 'transfer_unreadable' }),
      ])],
      bomItems: [REACTION_LINE],
    })

    expect(grid.rows.flatMap(expectedOf)).toEqual([])
  })

  it('shows the dashed card in the place to choose when the needing station is unresolved', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [
        stationQuantity({ ...AZBEL, out: 40 }),
        stationQuantity({ ...UNRESOLVED, needed: 40, onSite: null, out: null, toBring: null, inTransit: 0, unknownReason: 'station_unresolved' }),
      ])],
      bomItems: [REACTION_LINE],
    })

    const unresolved = grid.rows.find(row => row.kind === 'unresolved')

    expect(unresolved === undefined ? [] : expectedOf(unresolved)).toHaveLength(1)
  })

  it('names the unresolved place as the origin of a dashed card', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [LACKING_AT_ATHANOR])],
      actions: [placedAction({ id: 'launch-2', code: 'launch_job', place: unknownPlace('station_unresolved'), ...COMPONENT_X, quantity: 2 })],
      bomItems: [REACTION_LINE],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))[0].expected).toMatchObject({ origin: 'toLaunch', fromKind: 'unresolved', fromName: null })
  })

  it('does not duplicate a dashed card when the source is in the unresolved row of the same type', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...UNRESOLVED, needed: 40, onSite: null, out: null, toBring: null, inTransit: 0, unknownReason: 'station_unresolved', inProduction: 40 })])],
      bomItems: [REACTION_LINE],
    })

    expect(grid.rows.flatMap(expectedOf)).toEqual([])
  })

  it('leaves the other cards without an expected field', () => {
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 40 }), LACKING_AT_ATHANOR])],
      bomItems: [REACTION_LINE],
    })

    const solid = grid.rows.flatMap(row => Object.values(row.cells).flat()).filter(card => card.state !== 'expected')

    expect(solid.every(card => card.expected === null)).toBe(true)
  })

  it('keeps the activity colour and the blueprint frame of the source type', () => {
    const copyLine = { ...REACTION_LINE, activityType: 'copy' } as BomItem
    const grid = gridOf({
      quantities: [typeQuantities(COMPONENT_X, [stationQuantity({ ...AZBEL, out: 3 }), LACKING_AT_ATHANOR])],
      bomItems: [copyLine],
    })

    expect(expectedOf(rowNamed(grid, 'Athanor'))[0]).toMatchObject({ activity: 'copy', bpc: true, source: null })
  })
})

describe('foldCell with expected cards', () => {
  function expectedCard(origin: 'out' | 'inProduction' | 'toLaunch' | 'toBuy', typeName: string): FlowCard {
    return flowCard('expected', {
      typeName,
      key: `expected:${typeName}`,
      expected: { origin, fromKind: 'station', fromName: 'Azbel', cardKey: `source:${typeName}` },
    })
  }

  it('shows up to three expected cards without folding', () => {
    const cards = [expectedCard('out', 'A'), expectedCard('inProduction', 'B'), expectedCard('toBuy', 'C')]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual(cards)
    expect(folded.foldedKind).toBeNull()
  })

  it('folds the expected cards not yet out once there are more than three, and keeps the ready ones', () => {
    const cards = [
      expectedCard('inProduction', 'b-Isogen'),
      expectedCard('out', 'a-Pyerite'),
      expectedCard('toLaunch', 'c-Nocxium'),
      expectedCard('toBuy', 'd-Megacyte'),
    ]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual([cards[1]])
    expect(folded.folded).toEqual([cards[0], cards[2], cards[3]])
    expect(folded.foldedKind).toBe('expected')
    expect(folded.foldLabelNames).toEqual(['b-Isogen', 'c-Nocxium', 'd-Megacyte'])
    expect(folded.hasMoreNames).toBe(false)
  })

  it('lists three names and marks more when more than three are folded', () => {
    const cards = [
      expectedCard('out', 'a'),
      expectedCard('inProduction', 'b'),
      expectedCard('inProduction', 'c'),
      expectedCard('toLaunch', 'd'),
      expectedCard('toBuy', 'e'),
    ]

    const folded = foldCell(cards, false)

    expect(folded.foldLabelNames).toEqual(['b', 'c', 'd'])
    expect(folded.hasMoreNames).toBe(true)
  })

  it('shows every card once the cell is open', () => {
    const cards = [expectedCard('inProduction', 'a'), expectedCard('inProduction', 'b'), expectedCard('toLaunch', 'c'), expectedCard('out', 'd')]

    expect(foldCell(cards, true).shown).toEqual(cards)
  })

  it('does not fold when fewer than two expected cards are still not out', () => {
    const cards = [expectedCard('out', 'a'), expectedCard('out', 'b'), expectedCard('out', 'c'), expectedCard('toBuy', 'd')]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual(cards)
    expect(folded.foldedKind).toBeNull()
  })

  it('counts only the expected cards towards the threshold, not the solid cards of the cell', () => {
    const cards = [
      flowCard('toBring', { typeName: 'Tritanium' }),
      flowCard('inTransit', { typeName: 'Zydrine' }),
      expectedCard('inProduction', 'a'),
      expectedCard('toLaunch', 'b'),
    ]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual(cards)
    expect(folded.foldedKind).toBeNull()
  })

  it('never folds the solid cards of the cell', () => {
    const cards = [
      flowCard('toBring', { typeName: 'Tritanium' }),
      expectedCard('inProduction', 'a'),
      expectedCard('inProduction', 'b'),
      expectedCard('toLaunch', 'c'),
      expectedCard('toBuy', 'd'),
    ]

    const folded = foldCell(cards, false)

    expect(folded.shown).toEqual([cards[0]])
  })
})

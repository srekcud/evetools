import { buildCompanionTasks, type CompanionFlowInput, type CompanionTasks } from '@/components/group-industry/companionTasks'
import type { BomItem, GroupMember, GroupProject } from '@/stores/group-industry/types'
import {
  ALT_EVE_CHARACTER_ID,
  bomLine,
  characterLocation,
  companionProject,
  JITA_STATION_ID,
  JITA_SYSTEM_ID,
  MAIN_EVE_CHARACTER_ID,
  member,
  PERIMETER_SYSTEM_ID,
  placedAction,
  projectTransportLegs,
  stationPlace,
  stationTransfer,
  stockProposal,
  stockProposalLine,
  transferPlace,
} from './companionFixtures'

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_MINUTE = 60_000
const ONE_HOUR = 3_600_000
const AMARR_STATION_ID = 60_008_494
const HEK_SYSTEM_ID = 30_002_053

function flowOf(overrides: Partial<CompanionFlowInput> = {}): CompanionFlowInput {
  return {
    placedActions: [],
    positions: [],
    transfers: [],
    transportLegs: projectTransportLegs(),
    stockProposal: null,
    myEveCharacterIds: [MAIN_EVE_CHARACTER_ID, ALT_EVE_CHARACTER_ID],
    ...overrides,
  }
}

function tasksOf(
  bomItems: BomItem[],
  flow: CompanionFlowInput | undefined,
  options: { project?: GroupProject; members?: GroupMember[] } = {},
): CompanionTasks {
  return buildCompanionTasks({
    bomItems,
    project: options.project ?? companionProject(),
    members: options.members ?? [],
    priceAlert: null,
    undercutOrders: [],
    chainNodes: null,
    unassignedJobCount: null,
    myCharacterNames: ['Alpha'],
    now: NOW,
    flow,
  })
}

function freeLine(id: string, typeId: number): BomItem {
  return bomLine({ id, typeId, typeName: `Type ${typeId}`, requiredQuantity: 100, remainingQuantity: 100, freeQuantity: 100 })
}

function reservedLine(id: string, typeId: number, expiresAt: string, isMine = true): BomItem {
  return bomLine({
    id,
    typeId,
    typeName: `Type ${typeId}`,
    requiredQuantity: 500,
    remainingQuantity: 500,
    reservations: [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity: 50, isMine, expiresAt }],
    reservedQuantity: 50,
    freeQuantity: 0,
  })
}

function actionAt(bomLineId: string, here: boolean | null) {
  return placedAction({ id: `action-${bomLineId}`, bomLineId, here })
}

describe('companion flow: position (R1)', () => {
  it('lists the distinct solar system names of the characters in the order of the characters', () => {
    const positions = [
      characterLocation({ eveCharacterId: MAIN_EVE_CHARACTER_ID, solarSystemId: JITA_SYSTEM_ID, solarSystemName: 'Jita' }),
      characterLocation({ eveCharacterId: ALT_EVE_CHARACTER_ID, solarSystemId: JITA_SYSTEM_ID, solarSystemName: 'Jita' }),
      characterLocation({ eveCharacterId: 2_112_000_003, solarSystemId: PERIMETER_SYSTEM_ID, solarSystemName: 'Perimeter' }),
    ]

    expect(tasksOf([], flowOf({ positions })).hereSystems).toEqual(['Jita', 'Perimeter'])
  })

  it.each([
    ['positions not read', null],
    ['no position', []],
    ['no system name', [characterLocation({ solarSystemId: null, solarSystemName: null, stationId: null })]],
  ])('has no known system when %s', (_label, positions) => {
    expect(tasksOf([], flowOf({ positions })).hereSystems).toBeNull()
  })
})

describe('companion flow: place of a line (R2)', () => {
  it('keeps the station and system of the launch and source actions with their here flag as read', () => {
    const placedActions = [
      placedAction({ id: 'a-1', code: 'launch_job', bomLineId: 'bom-hull', place: stationPlace(), here: true }),
      placedAction({
        id: 'a-2',
        code: 'source_material',
        bomLineId: 'bom-morphite',
        place: stationPlace({ locationId: AMARR_STATION_ID, name: 'Amarr VIII (Oris) - Emperor Family Academy', solarSystemName: 'Amarr' }),
        here: false,
      }),
      placedAction({ id: 'a-3', code: 'source_material', bomLineId: 'bom-nocxium', place: stationPlace({ name: 'Perimeter - Tranquility Trading Tower', solarSystemName: 'Perimeter' }), here: null }),
    ]

    expect(tasksOf([], flowOf({ placedActions })).linePlaces).toEqual({
      'bom-hull': { stationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', systemName: 'Jita', here: true },
      'bom-morphite': { stationName: 'Amarr VIII (Oris) - Emperor Family Academy', systemName: 'Amarr', here: false },
      'bom-nocxium': { stationName: 'Perimeter - Tranquility Trading Tower', systemName: 'Perimeter', here: null },
    })
  })

  it('gives no place to anywhere and unknown places, to actions without a line and to other action codes', () => {
    const placedActions = [
      placedAction({ id: 'a-1', bomLineId: 'bom-anywhere', place: stationPlace({ kind: 'anywhere', locationId: null, name: null, solarSystemId: null, solarSystemName: null }), here: null }),
      placedAction({ id: 'a-2', bomLineId: 'bom-unknown', place: stationPlace({ kind: 'unknown', locationId: null, name: null, unknownReason: 'no_station' }), here: null }),
      placedAction({ id: 'a-3', bomLineId: null, place: stationPlace(), here: true }),
      placedAction({ id: 'a-4', code: 'review_contributions', bomLineId: 'bom-review', place: stationPlace(), here: true }),
    ]

    expect(tasksOf([], flowOf({ placedActions })).linePlaces).toEqual({})
  })
})

describe('companion flow: here first (R3)', () => {
  it('moves the free line whose place is here before the others without touching the order of the rest', () => {
    const lines = [freeLine('line-a', 34), freeLine('line-b', 35), freeLine('line-c', 36)]
    const placedActions = [actionAt('line-a', false), actionAt('line-b', false), actionAt('line-c', true)]

    expect(tasksOf(lines, flowOf({ placedActions })).free.map(item => item.id)).toEqual(['line-c', 'line-a', 'line-b'])
  })

  it('applies the order before the cap of free lines so the eighth of nine lines comes first', () => {
    const lines = Array.from({ length: 9 }, (_, index) => freeLine(`line-${index + 1}`, 40 + index))
    const placedActions = [actionAt('line-8', true)]

    expect(tasksOf(lines, flowOf({ placedActions })).free.map(item => item.id)).toEqual([
      'line-8', 'line-1', 'line-2', 'line-3', 'line-4', 'line-5', 'line-6', 'line-7', 'line-9',
    ])
  })

  it('moves nothing when the here flag is false, null or has no action', () => {
    const lines = [freeLine('line-a', 34), freeLine('line-b', 35), freeLine('line-c', 36)]
    const placedActions = [actionAt('line-a', false), actionAt('line-b', null)]

    expect(tasksOf(lines, flowOf({ placedActions })).free.map(item => item.id)).toEqual(['line-a', 'line-b', 'line-c'])
  })

  it('moves the reservation to deliver whose place is here before the others', () => {
    const expiresAt = '2026-11-23T04:00:00+00:00'
    const lines = [reservedLine('line-a', 34, expiresAt), reservedLine('line-b', 35, expiresAt)]
    const placedActions = [actionAt('line-a', false), actionAt('line-b', true)]

    expect(tasksOf(lines, flowOf({ placedActions })).mine.map(entry => entry.item.id)).toEqual(['line-b', 'line-a'])
  })
})

describe('companion flow: carries (R4)', () => {
  it('lists my in transit leg leaving from a station where one of my characters is', () => {
    const transfer = stationTransfer({
      id: 'transfer-morphite',
      state: 'in_transit',
      volume: 900,
      expectedArrivalAt: '2026-11-21T14:00:00+00:00',
      lines: [{ ...stationTransfer().lines[0], typeName: 'Morphite', quantity: 300 }],
    })
    const positions = [characterLocation({ eveCharacterId: MAIN_EVE_CHARACTER_ID, stationId: JITA_STATION_ID })]

    expect(tasksOf([], flowOf({ transfers: [transfer], positions })).carries).toEqual([
      {
        id: 'transfer-morphite',
        state: 'in_transit',
        from: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
        to: 'Perimeter - Tranquility Trading Tower',
        fromSystem: 'Jita',
        toSystem: 'Perimeter',
        volume: 900,
        lines: [{ typeName: 'Morphite', quantity: 300 }],
        expectedArrivalAt: '2026-11-21T14:00:00+00:00',
        here: true,
      },
    ])
  })

  it('leaves out arrived legs, legs carried by another player and courier contracts', () => {
    const transfers = [
      stationTransfer({ id: 'transfer-arrived', state: 'arrived', arrivedAt: '2026-11-21T11:00:00+00:00' }),
      stationTransfer({ id: 'transfer-other-player', carrier: { kind: 'pilot', characterId: 2_112_000_777, contractId: null } }),
      stationTransfer({ id: 'transfer-courier', carrier: { kind: 'courier', characterId: null, contractId: 190_004_411 } }),
    ]

    expect(tasksOf([], flowOf({ transfers })).carries).toEqual([])
  })

  it('writes an unknown end as null and falls back to the system name when the station has no name', () => {
    const transfers = [
      stationTransfer({
        id: 'transfer-unnamed-start',
        from: transferPlace({ stationName: null, solarSystemName: null, locationId: null }),
        to: transferPlace({ stationName: null, solarSystemName: 'Hek', solarSystemId: HEK_SYSTEM_ID, locationId: AMARR_STATION_ID }),
      }),
    ]

    const carries = tasksOf([], flowOf({ transfers })).carries

    expect(carries.map(carry => [carry.from, carry.to, carry.fromSystem, carry.toSystem])).toEqual([[null, 'Hek', null, 'Hek']])
  })

  it('says the carries are unknown when the transfers could not be read, and known when there is no transfer', () => {
    expect({
      unreadable: tasksOf([], flowOf({ transfers: null })).carriesUnknown,
      none: tasksOf([], flowOf({ transfers: [] })).carriesUnknown,
      noFlow: tasksOf([], undefined).carriesUnknown,
    }).toEqual({ unreadable: true, none: false, noFlow: false })
  })

  it('keeps the volume as read, null meaning unknown', () => {
    const transfers = [stationTransfer({ volume: null })]

    expect(tasksOf([], flowOf({ transfers })).carries.map(carry => carry.volume)).toEqual([null])
  })

  it('does not know whether I am here when positions are not read or the start has no location', () => {
    const transfers = [stationTransfer({ id: 'transfer-known-start' }), stationTransfer({ id: 'transfer-unlocated-start', from: transferPlace({ locationId: null }) })]
    const positions = [characterLocation({ stationId: JITA_STATION_ID })]

    expect({
      withoutPositions: tasksOf([], flowOf({ transfers, positions: null })).carries.map(carry => carry.here),
      withPositions: tasksOf([], flowOf({ transfers, positions })).carries.map(carry => carry.here),
    }).toEqual({ withoutPositions: [null, null], withPositions: [true, null] })
  })

  it('puts the legs where I am first, then keeps the received order, and says false for the others', () => {
    const transfers = [
      stationTransfer({ id: 'transfer-amarr', from: transferPlace({ locationId: AMARR_STATION_ID, stationName: 'Amarr VIII (Oris) - Emperor Family Academy', solarSystemName: 'Amarr' }) }),
      stationTransfer({ id: 'transfer-jita-first' }),
      stationTransfer({ id: 'transfer-jita-second' }),
    ]
    const positions = [characterLocation({ eveCharacterId: ALT_EVE_CHARACTER_ID, stationId: null, structureId: JITA_STATION_ID })]

    const carries = tasksOf([], flowOf({ transfers, positions })).carries

    expect(carries.map(carry => [carry.id, carry.here])).toEqual([
      ['transfer-jita-first', true],
      ['transfer-jita-second', true],
      ['transfer-amarr', false],
    ])
  })
})

describe('companion flow: supplies (R5)', () => {
  const morphite = bomLine({ id: 'bom-morphite', typeId: 11_399, typeName: 'Morphite' })
  const nocxium = bomLine({ id: 'bom-nocxium', typeId: 38, typeName: 'Nocxium' })

  it('turns a ready proposal line into a supply taken from the first station of its stock', () => {
    const proposal = stockProposal({
      lines: [stockProposalLine({
        bomItemId: 'bom-morphite',
        proposedQuantity: 300,
        stockByStation: [
          { locationId: JITA_STATION_ID, locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', systemName: 'Jita', quantity: 420, onSite: true },
          { locationId: AMARR_STATION_ID, locationName: 'Amarr VIII (Oris) - Emperor Family Academy', systemName: 'Amarr', quantity: 80, onSite: false },
        ],
      })],
    })

    expect(tasksOf([morphite, nocxium], flowOf({ stockProposal: proposal })).supplies).toEqual([
      { item: morphite, quantity: 300, locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', systemName: 'Jita', here: true },
    ])
  })

  it('has no supply for a zero proposed quantity, a line missing from the BOM or a proposal that is not ready', () => {
    const lines = [
      stockProposalLine({ bomItemId: 'bom-morphite', proposedQuantity: 0 }),
      stockProposalLine({ bomItemId: 'bom-removed-line', proposedQuantity: 75 }),
    ]

    expect({
      ready: tasksOf([morphite], flowOf({ stockProposal: stockProposal({ lines }) })).supplies,
      notSolo: tasksOf([morphite], flowOf({ stockProposal: stockProposal({ state: 'not_solo', lines: [stockProposalLine({ bomItemId: 'bom-morphite', proposedQuantity: 300 })] }) })).supplies,
      assetsUnknown: tasksOf([morphite], flowOf({ stockProposal: stockProposal({ state: 'assets_unknown', unknownReason: 'personal_assets_not_synced', lines: [stockProposalLine({ bomItemId: 'bom-morphite', proposedQuantity: 300 })] }) })).supplies,
      absent: tasksOf([morphite], flowOf({ stockProposal: null })).supplies,
    }).toEqual({ ready: [], notSolo: [], assetsUnknown: [], absent: [] })
  })

  it('puts the supplies available here first and has no here flag without a station', () => {
    const lines = [
      stockProposalLine({ bomItemId: 'bom-morphite', proposedQuantity: 300, stockByStation: [{ locationId: AMARR_STATION_ID, locationName: 'Amarr VIII (Oris) - Emperor Family Academy', systemName: 'Amarr', quantity: 300, onSite: false }] }),
      stockProposalLine({ bomItemId: 'bom-nocxium', typeId: 38, typeName: 'Nocxium', proposedQuantity: 60, stockByStation: [{ locationId: JITA_STATION_ID, locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', systemName: 'Jita', quantity: 60, onSite: true }] }),
    ]
    const withoutStation = stockProposal({ lines: [stockProposalLine({ bomItemId: 'bom-morphite', proposedQuantity: 300, stockByStation: [] })] })

    expect({
      order: tasksOf([morphite, nocxium], flowOf({ stockProposal: stockProposal({ lines }) })).supplies.map(supply => [supply.item.id, supply.quantity, supply.here]),
      withoutStation: tasksOf([morphite], flowOf({ stockProposal: withoutStation })).supplies.map(supply => [supply.locationName, supply.systemName, supply.here]),
    }).toEqual({
      order: [['bom-nocxium', 60, true], ['bom-morphite', 300, false]],
      withoutStation: [[null, null, null]],
    })
  })
})

describe('companion flow: reservation expiry (R6)', () => {
  it('gives the expiry in milliseconds of my reservation to deliver', () => {
    const expiresAt = new Date(NOW + 41 * ONE_HOUR).toISOString()

    const tasks = tasksOf([reservedLine('line-a', 34, expiresAt)], flowOf())

    expect(tasks.reservationExpiries).toEqual({ 'line-a': NOW + 41 * ONE_HOUR })
  })

  it('keeps the expiry of an elapsed reservation so the line can say it expired', () => {
    const expiresAt = new Date(NOW - ONE_MINUTE).toISOString()

    expect(tasksOf([reservedLine('line-a', 34, expiresAt)], flowOf()).reservationExpiries).toEqual({ 'line-a': NOW - ONE_MINUTE })
  })

  it('has no entry for an unreadable date or a reservation that is not mine', () => {
    const lines = [reservedLine('line-a', 34, 'not-a-date'), reservedLine('line-b', 35, '2026-11-23T04:00:00+00:00', false)]

    expect(tasksOf(lines, flowOf()).reservationExpiries).toEqual({})
  })
})

describe('companion flow: join requests (R7)', () => {
  const members = [
    member({ id: 'member-kaelen', status: 'pending', characterName: 'Kaelen' }),
    member({ id: 'member-unnamed', status: 'pending', characterName: null }),
    member({ id: 'member-ilsa', status: 'accepted', characterName: 'Ilsa Verhoeven' }),
  ]

  it('names the pending requests with a known character name for an admin', () => {
    const tasks = tasksOf([], flowOf(), { project: companionProject({ myRole: 'admin' }), members })

    expect({ names: tasks.joinRequestNames, count: tasks.joinRequests }).toEqual({ names: ['Kaelen'], count: 2 })
  })

  it('names nothing for a member who is not an admin', () => {
    const tasks = tasksOf([], flowOf(), { project: companionProject({ myRole: 'member' }), members })

    expect({ names: tasks.joinRequestNames, count: tasks.joinRequests }).toEqual({ names: [], count: null })
  })
})

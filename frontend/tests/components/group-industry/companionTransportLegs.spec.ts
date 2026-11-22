import { buildCompanionTasks, type CompanionFlowInput, type CompanionTasks } from '@/components/group-industry/companionTasks'
import type { ProjectTransportLeg } from '@/stores/group-industry/types'
import {
  ALT_EVE_CHARACTER_ID,
  amarrLegPlace,
  characterLocation,
  companionProject,
  HEK_STATION_ID,
  HEK_SYSTEM_ID,
  JITA_STATION_ID,
  JITA_SYSTEM_ID,
  legPlace,
  MAIN_EVE_CHARACTER_ID,
  PERIMETER_STATION_ID,
  PERIMETER_SYSTEM_ID,
  perimeterLegPlace,
  projectTransportLegs,
  stationTransfer,
  transferPlace,
  transportLeg,
  transportLegLine,
} from './companionFixtures'

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const AMARR_TO_PERIMETER = 'amarr>perimeter'
const HEK_TRANSFER_PLACE = { solarSystemId: HEK_SYSTEM_ID, solarSystemName: 'Hek', locationId: HEK_STATION_ID, stationName: 'Hek VIII - Moon 12 - Boundless Creation Factory' }

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

function tasksOf(flow: CompanionFlowInput | undefined): CompanionTasks {
  return buildCompanionTasks({
    bomItems: [],
    project: companionProject(),
    members: [],
    priceAlert: null,
    undercutOrders: [],
    chainNodes: null,
    unassignedJobCount: null,
    myCharacterNames: ['Alpha'],
    now: NOW,
    flow,
  })
}

function tasksWithLegs(legs: ProjectTransportLeg[], overrides: Partial<CompanionFlowInput> = {}): CompanionTasks {
  return tasksOf(flowOf({ transportLegs: projectTransportLegs({ legs }), ...overrides }))
}

describe('companion transport legs: legs of the endpoint (R2)', () => {
  it('writes each leg with its stations, systems and volume as read and the declaration of its ready lines only', () => {
    const morphiteLeg = transportLeg()
    const tritaniumLeg = transportLeg({
      key: AMARR_TO_PERIMETER,
      departure: amarrLegPlace(),
      volume: 0.4,
      lines: [
        transportLegLine({ typeId: 34, typeName: 'Tritanium', quantity: 15, unitVolume: 0.01, volume: 0.15 }),
        transportLegLine({ typeId: 34, typeName: 'Tritanium', quantity: 25, state: 'in_production', readyAt: '2026-11-23T10:00:00+00:00', unitVolume: 0.01, volume: 0.25 }),
      ],
    })
    const nocxiumLeg = transportLeg({
      key: 'hek>perimeter',
      departure: legPlace({ locationId: HEK_STATION_ID, stationName: null, solarSystemId: HEK_SYSTEM_ID, solarSystemName: 'Hek' }),
      volume: null,
      lines: [transportLegLine({ typeId: 38, typeName: 'Nocxium', quantity: 5, unitVolume: null, volume: null })],
    })
    const zydrineLeg = transportLeg({
      key: 's30000142>perimeter',
      departure: legPlace({ locationId: null, stationName: null }),
      arrival: perimeterLegPlace({ stationName: null, solarSystemName: null }),
      volume: 1,
      lines: [transportLegLine({ typeId: 39, typeName: 'Zydrine', quantity: 100, source: 'purchase', unitVolume: 0.01, volume: 1 })],
    })

    const tasks = tasksWithLegs([morphiteLeg, tritaniumLeg, nocxiumLeg, zydrineLeg])

    expect({
      legs: tasks.legs.map(leg => ({
        key: leg.key,
        from: leg.from,
        to: leg.to,
        fromSystem: leg.fromSystem,
        toSystem: leg.toSystem,
        volume: leg.volume,
        lines: leg.lines,
        declaration: leg.declaration,
      })),
      readyLegCount: tasks.readyLegCount,
    }).toEqual({
      legs: [
        {
          key: `${JITA_STATION_ID}>${PERIMETER_STATION_ID}`,
          from: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
          to: 'Perimeter - Tranquility Trading Tower',
          fromSystem: 'Jita',
          toSystem: 'Perimeter',
          volume: 400,
          lines: morphiteLeg.lines,
          declaration: {
            fromSolarSystemId: JITA_SYSTEM_ID,
            fromLocationId: JITA_STATION_ID,
            toSolarSystemId: PERIMETER_SYSTEM_ID,
            toLocationId: PERIMETER_STATION_ID,
            lines: [{ typeId: 11_399, quantity: 40 }],
            carrierCharacterId: MAIN_EVE_CHARACTER_ID,
            contractId: null,
          },
        },
        {
          key: AMARR_TO_PERIMETER,
          from: 'Amarr VIII (Oris) - Emperor Family Academy',
          to: 'Perimeter - Tranquility Trading Tower',
          fromSystem: 'Amarr',
          toSystem: 'Perimeter',
          volume: 0.4,
          lines: tritaniumLeg.lines,
          declaration: {
            fromSolarSystemId: 30_002_187,
            fromLocationId: 60_008_494,
            toSolarSystemId: PERIMETER_SYSTEM_ID,
            toLocationId: PERIMETER_STATION_ID,
            lines: [{ typeId: 34, quantity: 15 }],
            carrierCharacterId: MAIN_EVE_CHARACTER_ID,
            contractId: null,
          },
        },
        {
          key: 'hek>perimeter',
          from: 'Hek',
          to: 'Perimeter - Tranquility Trading Tower',
          fromSystem: 'Hek',
          toSystem: 'Perimeter',
          volume: null,
          lines: nocxiumLeg.lines,
          declaration: {
            fromSolarSystemId: HEK_SYSTEM_ID,
            fromLocationId: HEK_STATION_ID,
            toSolarSystemId: PERIMETER_SYSTEM_ID,
            toLocationId: PERIMETER_STATION_ID,
            lines: [{ typeId: 38, quantity: 5 }],
            carrierCharacterId: MAIN_EVE_CHARACTER_ID,
            contractId: null,
          },
        },
        {
          key: 's30000142>perimeter',
          from: 'Jita',
          to: null,
          fromSystem: 'Jita',
          toSystem: null,
          volume: 1,
          lines: zydrineLeg.lines,
          declaration: {
            fromSolarSystemId: JITA_SYSTEM_ID,
            fromLocationId: null,
            toSolarSystemId: PERIMETER_SYSTEM_ID,
            toLocationId: PERIMETER_STATION_ID,
            lines: [{ typeId: 39, quantity: 100 }],
            carrierCharacterId: MAIN_EVE_CHARACTER_ID,
            contractId: null,
          },
        },
      ],
      readyLegCount: 4,
    })
  })

  it('puts the legs leaving from where I am first in a stable order and does not know where I am without positions or a departure station', () => {
    const elsewhere = transportLeg({ key: AMARR_TO_PERIMETER, departure: amarrLegPlace() })
    const here = transportLeg()
    const hub = transportLeg({ key: 's30000142>perimeter', departure: legPlace({ locationId: null, stationName: null }) })
    const positions = [characterLocation({ eveCharacterId: ALT_EVE_CHARACTER_ID, stationId: JITA_STATION_ID })]

    const located = tasksWithLegs([elsewhere, here, hub], { positions })
    const unlocated = tasksWithLegs([elsewhere, here, hub], { positions: null })

    expect({
      located: located.legs.map(leg => [leg.key, leg.here]),
      unlocated: unlocated.legs.map(leg => [leg.key, leg.here]),
    }).toEqual({
      located: [[`${JITA_STATION_ID}>${PERIMETER_STATION_ID}`, true], [AMARR_TO_PERIMETER, false], ['s30000142>perimeter', null]],
      unlocated: [[AMARR_TO_PERIMETER, null], [`${JITA_STATION_ID}>${PERIMETER_STATION_ID}`, null], ['s30000142>perimeter', null]],
    })
  })
})

describe('companion transport legs: declared legs and counter (R4, R5)', () => {
  it('marks as declared only the leg of my own transfer awaiting departure on the same route, and keeps it out of the counter', () => {
    const declaredLeg = transportLeg()
    const otherPlayerLeg = transportLeg({ key: AMARR_TO_PERIMETER, departure: amarrLegPlace() })
    const arrivedLeg = transportLeg({ key: 'jita>hek', arrival: legPlace(HEK_TRANSFER_PLACE) })
    const inTransitLeg = transportLeg({ key: 'amarr>hek', departure: amarrLegPlace(), arrival: legPlace(HEK_TRANSFER_PLACE) })
    const amarrPlace = transferPlace({ solarSystemId: 30_002_187, solarSystemName: 'Amarr', locationId: 60_008_494, stationName: 'Amarr VIII (Oris) - Emperor Family Academy' })
    const transfers = [
      stationTransfer({ id: 'transfer-mine', state: 'awaiting_departure' }),
      stationTransfer({ id: 'transfer-other-player', state: 'awaiting_departure', from: amarrPlace, carrier: { kind: 'pilot', characterId: 2_112_000_777, contractId: null } }),
      stationTransfer({ id: 'transfer-arrived', state: 'arrived', arrivedAt: '2026-11-21T11:00:00+00:00', to: transferPlace(HEK_TRANSFER_PLACE) }),
      stationTransfer({ id: 'transfer-moving', state: 'in_transit', from: amarrPlace, to: transferPlace(HEK_TRANSFER_PLACE) }),
    ]

    const tasks = tasksWithLegs([declaredLeg, otherPlayerLeg, arrivedLeg, inTransitLeg], { transfers })

    expect({
      declared: tasks.legs.map(leg => leg.declared),
      canDeclare: tasks.legs.map(leg => leg.canDeclare),
      declarations: tasks.legs.map(leg => leg.declaration === null),
      readyLegCount: tasks.readyLegCount,
    }).toEqual({
      declared: [true, false, false, false],
      canDeclare: [false, true, true, true],
      declarations: [true, false, false, false],
      readyLegCount: 3,
    })
  })

  it('declares as the first carrier of the leg or, when the access is unknown, as my first character, and refuses a leg that cannot be declared', () => {
    const unknownAccess = transportLeg({ key: 'unknown-access', access: 'unknown', carrierCharacterIds: [] })
    const altFirst = transportLeg({ key: 'alt-first', carrierCharacterIds: [ALT_EVE_CHARACTER_ID, MAIN_EVE_CHARACTER_ID] })
    const noDepartureSystem = transportLeg({ key: 'no-departure-system', departure: legPlace({ solarSystemId: null, solarSystemName: null }) })
    const noArrivalSystem = transportLeg({ key: 'no-arrival-system', arrival: perimeterLegPlace({ solarSystemId: null, solarSystemName: null }) })
    const inProductionOnly = transportLeg({ key: 'in-production-only', lines: [transportLegLine({ typeId: 35, typeName: 'Pyerite', quantity: 60, state: 'in_production', readyAt: '2026-11-21T14:00:00+00:00' })] })
    const emptyReadyLine = transportLeg({ key: 'empty-ready-line', lines: [transportLegLine({ typeId: 36, typeName: 'Mexallon', quantity: 0, volume: 0 })] })

    const tasks = tasksWithLegs([unknownAccess, altFirst, noDepartureSystem, noArrivalSystem, inProductionOnly, emptyReadyLine])
    const withoutCharacter = tasksWithLegs([unknownAccess], { myEveCharacterIds: [] })

    expect({
      accessUnknown: tasks.legs.map(leg => leg.accessUnknown),
      canDeclare: tasks.legs.map(leg => leg.canDeclare),
      carriers: tasks.legs.map(leg => leg.declaration?.carrierCharacterId ?? null),
      withoutCharacter: withoutCharacter.legs.map(leg => [leg.canDeclare, leg.declaration]),
      readyLegCount: tasks.readyLegCount,
    }).toEqual({
      accessUnknown: [true, false, false, false, false, false],
      canDeclare: [true, true, false, false, false, false],
      carriers: [MAIN_EVE_CHARACTER_ID, ALT_EVE_CHARACTER_ID, null, null, null, null],
      withoutCharacter: [[false, null]],
      readyLegCount: 4,
    })
  })
})

describe('companion transport legs: unknown and empty readings (R2, R8)', () => {
  it('says the legs are unknown when they could not be read, counts the unreadable readings and has no legs without flow', () => {
    const unreadable = [
      { typeId: 11_399, typeName: 'Morphite', locationId: PERIMETER_STATION_ID, reason: 'corporation_assets_not_synced' },
      { typeId: 38, typeName: 'Nocxium', locationId: null, reason: 'plan_unavailable' },
    ]

    const notRead = tasksOf(flowOf({ transportLegs: null }))
    const noLeg = tasksOf(flowOf({ transportLegs: projectTransportLegs() }))
    const partial = tasksOf(flowOf({ transportLegs: projectTransportLegs({ legs: [transportLeg()], unreadable }) }))
    const noFlow = tasksOf(undefined)

    expect([notRead, noLeg, partial, noFlow].map(tasks => [tasks.legs.length, tasks.legsUnknown, tasks.legsUnreadableCount, tasks.readyLegCount])).toEqual([
      [0, true, 0, 0],
      [0, false, 0, 0],
      [1, false, 2, 1],
      [0, false, 0, 0],
    ])
  })
})

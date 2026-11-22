import { describe, expect, it } from 'vitest'
import { buildStationView, type StationProjectSource } from '@/components/group-industry/stationView'
import type { BomItem, GroupMemberRole, GroupProject, GroupProjectStatus, JobMatch, PlacedAction, TypeStationQuantities } from '@/stores/group-industry/types'
import { ANYWHERE_PLACE, AZBEL_PLACE, JITA_PLACE, placedAction } from '../../todoPlaceFixtures'
import {
  JITA_ID,
  STATION_A_ID,
  STATION_B_ID,
  TRITANIUM,
  ZYDRINE,
  projectOf,
  stationQuantity,
  typeQuantities,
} from './projectFlowFixtures'

const NOW = Date.parse('2026-11-22T12:00:00Z')
const AZBEL = { locationId: STATION_A_ID, stationName: 'Azbel' }
const RAITARU = { locationId: STATION_B_ID, stationName: 'Raitaru' }
const OTHER_FACILITY_ID = 1_040_000_000_099
const IN_ONE_HOUR = '2026-11-22T13:00:00Z'
const AN_HOUR_AGO = '2026-11-22T11:00:00Z'

function source(
  id: string,
  fields: {
    status?: GroupProjectStatus
    role?: GroupMemberRole | null
    ownerName?: string
    quantities?: TypeStationQuantities[]
    bomItems?: BomItem[]
  } = {},
): StationProjectSource {
  return {
    project: { ...projectOf(), id, name: `Project ${id}`, status: fields.status ?? 'in_progress' } as GroupProject,
    myRole: fields.role === undefined ? 'owner' : fields.role,
    ownerName: fields.ownerName ?? 'Mira Thessaly',
    quantities: fields.quantities ?? [],
    bomItems: fields.bomItems ?? [],
    sales: [],
  }
}

function atAzbel(fields: Partial<Parameters<typeof stationQuantity>[0]>, type = TRITANIUM): TypeStationQuantities {
  return typeQuantities(type, [stationQuantity({ ...AZBEL, ...fields })])
}

function jobLineAt(facilityId: number | null, ...statuses: [string, string | null][]): BomItem {
  const jobMatches = statuses.map(([status, endDate]) => ({ status, endDate, runs: 2, facilityId }) as JobMatch)
  return {
    typeId: ZYDRINE.typeId,
    isJob: true,
    activityType: 'manufacturing',
    runs: 4,
    fulfilledQuantity: 0,
    purchasedQuantity: 0,
    jobMatches,
  } as BomItem
}

function actionAt(id: string, fields: Partial<PlacedAction>): PlacedAction {
  return placedAction({ id, code: 'launch_job', place: AZBEL_PLACE, ...fields })
}

function view(sources: StationProjectSource[], actions: PlacedAction[] = [], locationId = STATION_A_ID) {
  return buildStationView({ locationId, sources, actions, now: NOW })
}

describe('projects shown at a station', () => {
  it('lists one row per open project that has something at the station', () => {
    const result = view([
      source('p1', { quantities: [atAzbel({ onSite: 5 })] }),
      source('p2', { quantities: [typeQuantities(TRITANIUM, [stationQuantity({ ...RAITARU, onSite: 5 })])] }),
      source('p3', { quantities: [atAzbel({ toBring: 8 })] }),
    ])

    expect(result.rows.map(row => row.projectId)).toEqual(['p1', 'p3'])
  })

  it('leaves out draft and completed projects', () => {
    const quantities = [atAzbel({ onSite: 5 })]
    const result = view([
      source('draft', { status: 'draft', quantities }),
      source('done', { status: 'completed', quantities }),
      source('published', { status: 'published', quantities }),
      source('selling', { status: 'selling', quantities }),
    ])

    expect(result.rows.map(row => row.projectId)).toEqual(['published', 'selling'])
  })

  it('names the project and tells the role of the user and the owner', () => {
    const result = view([source('p1', { role: 'member', ownerName: 'Kaelen Voss', quantities: [atAzbel({ onSite: 5 })] })])

    expect(result.rows[0]).toMatchObject({ projectName: 'Project p1', myRole: 'member', ownerName: 'Kaelen Voss' })
  })

  it('shows nothing when no project has anything at the station', () => {
    expect(view([source('p1', { quantities: [atAzbel({ onSite: 5 })] })], [], STATION_B_ID).rows).toEqual([])
  })
})

describe('cards of a project row', () => {
  it('places the quantities of the station in the flow columns', () => {
    const result = view([source('p1', { quantities: [atAzbel({ toBring: 10, onSite: 5, inProduction: 3, out: 2 })] })])

    const { cells } = result.rows[0]
    expect({
      toBring: cells.toBring.map(card => [card.state, card.quantity]),
      onSite: cells.onSite.map(card => [card.state, card.quantity]),
      inProduction: cells.inProduction.map(card => [card.state, card.quantity]),
      out: cells.out.map(card => [card.state, card.quantity]),
    }).toEqual({
      toBring: [['toBring', 10]],
      onSite: [['onSite', 5]],
      inProduction: [['inProduction', 3]],
      out: [['out', 2]],
    })
  })

  it('keeps the cards of the other stations out of the row', () => {
    const result = view([source('p1', {
      quantities: [
        typeQuantities(TRITANIUM, [stationQuantity({ ...AZBEL, onSite: 5 }), stationQuantity({ ...RAITARU, onSite: 7 })]),
      ],
    })])

    expect(result.rows[0].cells.onSite.map(card => card.quantity)).toEqual([5])
  })

  it('shows the purchases to make at a market station next to what is already there', () => {
    const result = view(
      [source('p1', { quantities: [typeQuantities(TRITANIUM, [stationQuantity({ locationId: JITA_ID, stationName: 'Jita', onSite: 5 })])] })],
      [placedAction({ id: 'buy', code: 'source_material', place: JITA_PLACE, projectId: 'p1', typeId: ZYDRINE.typeId, typeName: ZYDRINE.typeName, quantity: 40 })],
      JITA_ID,
    )

    expect(result.rows[0].cells.onSite.map(card => [card.state, card.typeName])).toEqual([['onSite', 'Tritanium'], ['toBuy', 'Zydrine']])
  })
})

describe('station summary', () => {
  it('counts the jobs of this station that still occupy a line, across projects', () => {
    const result = view([
      source('p1', {
        quantities: [atAzbel({ inProduction: 4 })],
        bomItems: [jobLineAt(STATION_A_ID, ['active', IN_ONE_HOUR], ['paused', IN_ONE_HOUR], ['delivered', AN_HOUR_AGO])],
      }),
      source('p2', {
        quantities: [atAzbel({ inProduction: 1 })],
        bomItems: [jobLineAt(STATION_A_ID, ['active', AN_HOUR_AGO], ['cancelled', null], ['active', IN_ONE_HOUR])],
      }),
    ])

    expect(result.occupiedLines).toBe(4)
  })

  it('does not count the jobs of another facility or without a facility', () => {
    const result = view([source('p1', {
      quantities: [atAzbel({ inProduction: 4 })],
      bomItems: [jobLineAt(OTHER_FACILITY_ID, ['active', IN_ONE_HOUR]), jobLineAt(null, ['active', IN_ONE_HOUR])],
    })])

    expect(result.occupiedLines).toBe(0)
  })

  it('counts the lots on their way here and the lots to carry away from here', () => {
    const result = view([
      source('p1', { quantities: [atAzbel({ inTransit: 20, toBring: 5 })] }),
      source('p2', { quantities: [atAzbel({ out: 3 }, ZYDRINE), atAzbel({ out: 9, inTransit: 1 }, TRITANIUM)] }),
    ])

    expect({ expected: result.expectedCount, carryAway: result.carryAwayCount }).toEqual({ expected: 2, carryAway: 2 })
  })

  it('reads the access of the user from the jobs to launch here', () => {
    const result = view([], [
      actionAt('a1', { access: 'granted' }),
      actionAt('a2', { access: 'granted', projectId: 'p2' }),
      actionAt('a3', { access: 'unknown' }),
      actionAt('a4', { access: null }),
      actionAt('a5', { access: 'granted', code: 'source_material' }),
      actionAt('a6', { access: 'granted', place: JITA_PLACE }),
    ])

    expect(result.access).toEqual({ granted: 2, unknown: 1 })
  })

  it('has no access to read without a job to launch here', () => {
    expect(view([], [actionAt('a1', { code: 'source_material' })]).access).toEqual({ granted: 0, unknown: 0 })
  })
})

describe('to do here', () => {
  it('gathers the actions of every project at this station and nothing else', () => {
    const result = view([], [
      actionAt('a1', { projectId: 'p1' }),
      actionAt('a2', { projectId: 'p2', code: 'source_material' }),
      actionAt('a3', { place: JITA_PLACE }),
      actionAt('a4', { place: ANYWHERE_PLACE }),
    ])

    expect(result.todo?.actions.map(action => action.id)).toEqual(['a1', 'a2'])
  })

  it('has no to do group when nothing is to be done here', () => {
    expect(view([], [actionAt('a1', { place: JITA_PLACE })]).todo).toBeNull()
  })

  it('tells whether the user is here from the actions of the station', () => {
    expect(view([], [actionAt('a1', { here: false }), actionAt('a2', { here: true })]).here).toBe(true)
    expect(view([], [actionAt('a1', { here: false })]).here).toBe(false)
    expect(view([], [actionAt('a1', {})]).here).toBeNull()
  })
})

describe('station identity', () => {
  it('takes the name and the system of the station from the actions', () => {
    const result = view([], [actionAt('a1', {})])

    expect({ name: result.name, system: result.solarSystemName }).toEqual({ name: 'Azbel', system: 'Perimeter' })
  })

  it('falls back to the name read in the quantities when there is no action', () => {
    const result = view([source('p1', { quantities: [atAzbel({ onSite: 5 })] })])

    expect({ name: result.name, system: result.solarSystemName }).toEqual({ name: 'Azbel', system: null })
  })

  it('has no name when nothing tells it', () => {
    expect(view([]).name).toBeNull()
  })
})

describe('projects whose quantities cannot be read', () => {
  it('lists them with the reason instead of dropping them', () => {
    const unreadable = typeQuantities(TRITANIUM, null, 'plan_unavailable')
    const result = view([
      source('p1', { quantities: [unreadable] }),
      source('p2', { quantities: [atAzbel({ onSite: 5 })] }),
    ])

    expect({
      unavailable: result.unavailable.map(entry => [entry.projectId, entry.reason]),
      rows: result.rows.map(row => row.projectId),
    }).toEqual({ unavailable: [['p1', 'plan_unavailable']], rows: ['p2'] })
  })
})

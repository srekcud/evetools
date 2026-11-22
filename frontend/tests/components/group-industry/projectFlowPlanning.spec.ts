import { buildFlowPlanning, type PlanningBar, type PlanningStation } from '@/components/group-industry/projectFlowPlanning'
import type { FlowGrid } from '@/components/group-industry/projectFlow'
import type { BomItem, PlanningJob } from '@/stores/group-industry/types'
import { HECATE_LINE_ID, ISHTAR_LINE_ID, NOCXIUM_LINE_ID, RAM_LINE_ID, planningJob } from '../../projectPlanningData'
import { JITA_ID, JITA_NAME, STATION_A_ID, STATION_B_ID, STATION_C_ID, flowCard, flowGrid, flowRow } from './projectFlowFixtures'
import {
  HECATE_PLANNED_JOB,
  ISHTAR_PLANNED_JOB,
  NOCXIUM_PLANNED_JOB,
  PLANNING_NOW,
  RAM_PLANNED_JOB,
  bomLineOf,
  hoursFromNow,
  hoursFromNowMs,
  jobMatchOf,
  planningOfJobs,
  span,
} from './projectFlowPlanningFixtures'

const ISHTAR_TYPE_ID = 12_005
const HECATE_TYPE_ID = 35_683
const ZETA_STATION_ID = 1_040_000_000_003
const ALPHA_STATION_ID = 1_040_000_000_009

function stationRow(locationId: number, name: string, cells: Parameters<typeof flowRow>[1] = {}): ReturnType<typeof flowRow> {
  return flowRow({ key: `station:${locationId}`, locationId, name }, cells)
}

function build(jobs: PlanningJob[], grid: FlowGrid = flowGrid([]), bomItems: BomItem[] = []) {
  return buildFlowPlanning({ planning: planningOfJobs(jobs), grid, bomItems, now: PLANNING_NOW })
}

function barOf(stations: PlanningStation[], lineId: string): PlanningBar | undefined {
  return stations.flatMap(station => station.bars).find(bar => bar.lineId === lineId)
}

function stationOf(stations: PlanningStation[], key: string): PlanningStation | undefined {
  return stations.find(station => station.key === key)
}

describe('buildFlowPlanning dates and states of a bar', () => {
  it('draws the bar from the real job when there is one, from the planned dates otherwise, and from the real job alone when nothing was planned', () => {
    const plannedAndDone = planningJob({
      ...HECATE_PLANNED_JOB,
      planned: span(-4, 0),
      real: { ...span(-3, -1), status: 'done' },
    })
    const plannedOnly = RAM_PLANNED_JOB
    const realOnly = planningJob({
      ...ISHTAR_PLANNED_JOB,
      planned: null,
      real: { ...span(-1, 2), status: 'active' },
    })

    const { stations } = build([plannedAndDone, plannedOnly, realOnly])

    expect([HECATE_LINE_ID, RAM_LINE_ID, ISHTAR_LINE_ID].map(lineId => {
      const bar = barOf(stations, lineId)
      return [lineId, bar?.start, bar?.end]
    })).toEqual([
      [HECATE_LINE_ID, hoursFromNowMs(-3), hoursFromNowMs(-1)],
      [RAM_LINE_ID, hoursFromNowMs(1), hoursFromNowMs(3)],
      [ISHTAR_LINE_ID, hoursFromNowMs(-1), hoursFromNowMs(2)],
    ])
  })

  it('names the state of each bar from the real job status and the wait of the planned job, and carries the type name, activity and runs', () => {
    const done = planningJob({ ...HECATE_PLANNED_JOB, real: { ...span(-3, -1), status: 'done' } })
    const running = planningJob({ ...ISHTAR_PLANNED_JOB, real: { ...span(-1, 2), status: 'active' } })
    const waiting = planningJob({ ...RAM_PLANNED_JOB, wait: { kind: 'waiting_materials', names: ['Tritanium'], lateSeconds: 3_600 } })
    const planned = NOCXIUM_PLANNED_JOB

    const { stations } = build([done, running, waiting, planned])

    expect([HECATE_LINE_ID, ISHTAR_LINE_ID, RAM_LINE_ID, NOCXIUM_LINE_ID].map(lineId => [lineId, barOf(stations, lineId)?.state])).toEqual([
      [HECATE_LINE_ID, 'done'],
      [ISHTAR_LINE_ID, 'running'],
      [RAM_LINE_ID, 'waiting'],
      [NOCXIUM_LINE_ID, 'planned'],
    ])
    expect(barOf(stations, NOCXIUM_LINE_ID)).toEqual({
      lineId: NOCXIUM_LINE_ID,
      typeName: 'Nocxium Reaction',
      activity: 'reaction',
      runs: 7,
      start: hoursFromNowMs(6),
      end: hoursFromNowMs(9),
      state: 'planned',
    })
  })
})

describe('buildFlowPlanning station of a job', () => {
  it('takes the station of the most recent job link that has one, whatever the order of the links', () => {
    const bomItems = [bomLineOf(ISHTAR_LINE_ID, [
      jobMatchOf({ matchedAt: hoursFromNow(-10), facilityId: STATION_A_ID, facilityName: 'Azbel' }),
      jobMatchOf({ matchedAt: hoursFromNow(-2), facilityId: STATION_B_ID, facilityName: 'Athanor' }),
      jobMatchOf({ matchedAt: hoursFromNow(-30), facilityId: STATION_C_ID, facilityName: 'Raitaru' }),
    ])]

    const { stations } = build([ISHTAR_PLANNED_JOB], flowGrid([]), bomItems)

    expect(stations.map(station => [station.key, station.kind, station.locationId, station.name, station.bars.map(bar => bar.lineId)])).toEqual([
      [`station:${STATION_B_ID}`, 'station', STATION_B_ID, 'Athanor', [ISHTAR_LINE_ID]],
    ])
  })

  it('falls back on the first station of the grid holding a card of the type in production or out, and on the place to choose when no station does', () => {
    const grid = flowGrid([
      flowRow({ key: `market:${JITA_ID}`, kind: 'market', locationId: JITA_ID, name: JITA_NAME }, {
        out: [flowCard('out', { typeName: 'Ishtar', typeId: ISHTAR_TYPE_ID })],
      }),
      stationRow(STATION_A_ID, 'Azbel', {
        onSite: [flowCard('onSite', { typeName: 'Hecate', typeId: HECATE_TYPE_ID })],
      }),
      stationRow(STATION_B_ID, 'Athanor', {
        inProduction: [flowCard('toLaunch', { typeName: 'Ishtar', typeId: ISHTAR_TYPE_ID })],
      }),
      stationRow(STATION_C_ID, 'Raitaru', {
        inProduction: [flowCard('inProduction', { typeName: 'Ishtar', typeId: ISHTAR_TYPE_ID })],
        out: [flowCard('out', { typeName: 'Hecate', typeId: HECATE_TYPE_ID })],
      }),
    ])

    const { stations } = build([ISHTAR_PLANNED_JOB, HECATE_PLANNED_JOB, NOCXIUM_PLANNED_JOB], grid)

    expect(stations.map(station => [station.key, station.kind, station.locationId, station.name, station.bars.map(bar => bar.lineId)])).toEqual([
      [`station:${STATION_B_ID}`, 'station', STATION_B_ID, 'Athanor', [ISHTAR_LINE_ID]],
      [`station:${STATION_C_ID}`, 'station', STATION_C_ID, 'Raitaru', [HECATE_LINE_ID]],
      ['unresolved', 'unresolved', null, null, [NOCXIUM_LINE_ID]],
    ])
  })

  it('names a station after its job link, then after its row of the grid, and leaves the name empty when neither has one', () => {
    const grid = flowGrid([stationRow(STATION_B_ID, 'Athanor from the grid'), stationRow(STATION_A_ID, 'Azbel from the grid')])
    const bomItems = [
      bomLineOf(ISHTAR_LINE_ID, [jobMatchOf({ matchedAt: hoursFromNow(-2), facilityId: STATION_B_ID, facilityName: 'Athanor from the link' })]),
      bomLineOf(HECATE_LINE_ID, [jobMatchOf({ matchedAt: hoursFromNow(-2), facilityId: STATION_A_ID, facilityName: null })]),
      bomLineOf(RAM_LINE_ID, [jobMatchOf({ matchedAt: hoursFromNow(-2), facilityId: STATION_C_ID, facilityName: null })]),
    ]

    const { stations } = build([ISHTAR_PLANNED_JOB, HECATE_PLANNED_JOB, RAM_PLANNED_JOB], grid, bomItems)

    expect([STATION_A_ID, STATION_B_ID, STATION_C_ID].map(id => stationOf(stations, `station:${id}`)?.name)).toEqual([
      'Azbel from the grid',
      'Athanor from the link',
      null,
    ])
  })
})

describe('buildFlowPlanning undated jobs, order and axis', () => {
  it('lists a job with neither planned nor real dates as undated, with no bar and no axis of its own', () => {
    const undated = planningJob({ ...ISHTAR_PLANNED_JOB, planned: null, real: null })

    expect(build([undated])).toEqual({ stations: [], undated: [undated], start: null, end: null })
  })

  it('orders the stations of the grid first in the grid order, then the others by name, the place to choose last, and the bars of a station by start then line', () => {
    const grid = flowGrid([
      stationRow(STATION_B_ID, 'Athanor', { inProduction: [flowCard('inProduction', { typeName: 'Ishtar', typeId: ISHTAR_TYPE_ID })] }),
      stationRow(STATION_A_ID, 'Azbel', {
        inProduction: [
          flowCard('inProduction', { typeName: 'Hecate', typeId: HECATE_TYPE_ID }),
          flowCard('inProduction', { typeName: 'R.A.M.- Starship Tech', typeId: 11_478 }),
        ],
      }),
    ])
    const bomItems = [
      bomLineOf(NOCXIUM_LINE_ID, [jobMatchOf({ matchedAt: hoursFromNow(-2), facilityId: ZETA_STATION_ID, facilityName: 'Zeta Fortizar' })]),
    ]
    const lateInAzbel = planningJob({ ...ISHTAR_PLANNED_JOB, lineId: 'line-late-in-azbel', typeId: HECATE_TYPE_ID, planned: span(8, 9) })
    const alphaJob = planningJob({ ...RAM_PLANNED_JOB, lineId: 'line-alpha', typeId: 99, typeName: 'Alpha Job', planned: span(0, 1) })
    const alphaBomItem = bomLineOf('line-alpha', [jobMatchOf({ matchedAt: hoursFromNow(-2), facilityId: ALPHA_STATION_ID, facilityName: 'Alpha Keepstar' })])
    const unplacedJob = planningJob({ ...HECATE_PLANNED_JOB, lineId: 'line-unplaced', typeId: 98, typeName: 'Unplaced Job' })

    const { stations } = build(
      [NOCXIUM_PLANNED_JOB, unplacedJob, ISHTAR_PLANNED_JOB, lateInAzbel, alphaJob, RAM_PLANNED_JOB, HECATE_PLANNED_JOB],
      grid,
      [...bomItems, alphaBomItem],
    )

    expect(stations.map(station => [station.key, station.bars.map(bar => bar.lineId)])).toEqual([
      [`station:${STATION_B_ID}`, [ISHTAR_LINE_ID]],
      [`station:${STATION_A_ID}`, [HECATE_LINE_ID, RAM_LINE_ID, 'line-late-in-azbel']],
      [`station:${ALPHA_STATION_ID}`, ['line-alpha']],
      [`station:${ZETA_STATION_ID}`, [NOCXIUM_LINE_ID]],
      ['unresolved', ['line-unplaced']],
    ])
  })

  it('starts the axis at now and ends it 24 hours after now when every bar lies within a day, and spans the bars when they reach further on both sides', () => {
    const upcoming = planningJob({ ...ISHTAR_PLANNED_JOB, planned: span(2, 4) })
    const spanning = planningJob({ ...HECATE_PLANNED_JOB, planned: span(-5, 30) })

    const withinADay = build([upcoming])
    const reachingFurther = build([upcoming, spanning])

    expect([
      [withinADay.start, withinADay.end],
      [reachingFurther.start, reachingFurther.end],
    ]).toEqual([
      [PLANNING_NOW, hoursFromNowMs(24)],
      [hoursFromNowMs(-5), hoursFromNowMs(30)],
    ])
  })
})

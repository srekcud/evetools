import { buildCompanionTasks, type CompanionTasks } from '@/components/group-industry/companionTasks'
import type { BomItem } from '@/stores/group-industry/types'
import { bomLine, companionProject, jobMatch } from './companionFixtures'

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_HOUR = 3_600_000
const PAST_END = new Date(NOW - ONE_HOUR).toISOString()
const FUTURE_END = new Date(NOW + ONE_HOUR).toISOString()

function reactionLine(id: string, matches: BomItem['jobMatches'], runs = 2): BomItem {
  return bomLine({ id, typeId: 2_000 + id.length, typeName: id, isJob: true, activityType: 'reaction', runs, jobMatches: matches })
}

function progressOf(bomItems: BomItem[]): CompanionTasks['activityProgress'] {
  return buildCompanionTasks({
    bomItems,
    chainNodes: null,
    project: companionProject(),
    members: [],
    myCharacterNames: [],
    priceAlert: null,
    undercutOrders: [],
    unassignedJobCount: null,
    now: NOW,
  }).activityProgress
}

describe('activity progress computed from the flow', () => {
  it('counts a job line whose every run is finished in game even before it is delivered', () => {
    const finished = reactionLine('finished', [jobMatch({ id: 'm1', esiJobId: 1, status: 'ready', runs: 2 })])
    const pending = reactionLine('pending', [])

    expect(progressOf([finished, pending])).toEqual([{ group: 'reaction', delivered: 1, total: 2, percent: 50 }])
  })

  it('counts an active job whose end date has passed as finished', () => {
    const overdue = reactionLine('overdue', [jobMatch({ id: 'm1', esiJobId: 1, status: 'active', endDate: PAST_END, runs: 2 })])

    expect(progressOf([overdue])).toEqual([{ group: 'reaction', delivered: 1, total: 1, percent: 100 }])
  })

  it('does not count a job still running', () => {
    const running = reactionLine('running', [jobMatch({ id: 'm1', esiJobId: 1, status: 'active', endDate: FUTURE_END, runs: 2 })])

    expect(progressOf([running])).toEqual([{ group: 'reaction', delivered: 0, total: 1, percent: 0 }])
  })

  it('does not count a line when the finished runs are fewer than the runs of the line', () => {
    const half = reactionLine('half', [
      jobMatch({ id: 'm1', esiJobId: 1, status: 'ready', runs: 1 }),
      jobMatch({ id: 'm2', esiJobId: 2, status: 'active', endDate: FUTURE_END, runs: 1 }),
    ])

    expect(progressOf([half])).toEqual([{ group: 'reaction', delivered: 0, total: 1, percent: 0 }])
  })

  it('adds the runs of several finished jobs and ignores the cancelled and reverted ones', () => {
    const split = reactionLine('split', [
      jobMatch({ id: 'm1', esiJobId: 1, status: 'ready', runs: 1 }),
      jobMatch({ id: 'm2', esiJobId: 2, status: 'delivered', runs: 1 }),
      jobMatch({ id: 'm3', esiJobId: 3, status: 'cancelled', runs: 5 }),
      jobMatch({ id: 'm4', esiJobId: 4, status: 'reverted', runs: 5 }),
    ])
    const onlyCancelled = reactionLine('only-cancelled', [jobMatch({ id: 'm5', esiJobId: 5, status: 'cancelled', runs: 2 })])

    expect(progressOf([split, onlyCancelled])).toEqual([{ group: 'reaction', delivered: 1, total: 2, percent: 50 }])
  })

  it('does not count a job line without a known number of runs', () => {
    const unknownRuns = bomLine({
      id: 'unknown-runs',
      typeId: 2_999,
      typeName: 'unknown-runs',
      isJob: true,
      activityType: 'reaction',
      runs: null,
      jobMatches: [jobMatch({ id: 'm1', esiJobId: 1, status: 'ready', runs: 2 })],
    })

    expect(progressOf([unknownRuns])).toEqual([{ group: 'reaction', delivered: 0, total: 1, percent: 0 }])
  })

  it('keeps a delivered line counted and never counts a material by its jobs', () => {
    const delivered = reactionLine('delivered', [], 2)
    const material = bomLine({ id: 'tritanium', typeId: 34, typeName: 'Tritanium', jobMatches: [jobMatch({ id: 'm1', esiJobId: 1, status: 'ready', runs: 1 })] })

    expect(progressOf([{ ...delivered, isFulfilled: true }, material])).toEqual([
      { group: 'reaction', delivered: 1, total: 1, percent: 100 },
      { group: 'material', delivered: 0, total: 1, percent: 0 },
    ])
  })
})

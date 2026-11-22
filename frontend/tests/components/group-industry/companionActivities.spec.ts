import { activityGroupOf, buildCompanionTasks, groupByActivity, jobTimingOf, type CompanionTasks } from '@/components/group-industry/companionTasks'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'
import { bomLine, companionProject, jobMatch } from './companionFixtures'

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_MINUTE = 60_000
const ONE_HOUR = 3_600_000

function line(id: string, overrides: Partial<BomItem> = {}): BomItem {
  return bomLine({ id, typeId: 1_000 + id.length, typeName: id, ...overrides })
}

function job(id: string, activityType: string | null, overrides: Partial<BomItem> = {}): BomItem {
  return line(id, { isJob: true, activityType, runs: 2, ...overrides })
}

function tasksOf(bomItems: BomItem[], project: GroupProject = companionProject(), myCharacterNames: string[] = ['Alpha']): CompanionTasks {
  return buildCompanionTasks({ bomItems, chainNodes: null, project, members: [], myCharacterNames, priceAlert: null, undercutOrders: [], unassignedJobCount: null, now: NOW })
}

describe('activity groups of the companion', () => {
  it('puts a material line in material, a job line in its activity and any other job line in unknown', () => {
    expect([
      activityGroupOf(line('tritanium')),
      activityGroupOf(job('vexor-copy', 'copy')),
      activityGroupOf(job('ishtar-invention', 'invention')),
      activityGroupOf(job('carbonide', 'reaction')),
      activityGroupOf(job('ishtar', 'manufacturing')),
      activityGroupOf(job('no-activity', null)),
      activityGroupOf(job('salvage', 'salvage')),
    ]).toEqual(['material', 'copy', 'invention', 'reaction', 'manufacturing', 'unknown', 'unknown'])
  })

  it('sorts lines by activity group, keeps the incoming order inside a group and drops the empty groups', () => {
    const entries = [
      line('tritanium'),
      job('carbonide', 'reaction'),
      job('salvage', 'salvage'),
      job('vexor-copy', 'copy'),
      job('ferrogel', 'reaction'),
      job('no-activity', null),
    ]

    const groups = groupByActivity(entries, entry => entry)

    expect(groups.map(({ group, entries: lines }) => [group, lines.map(entry => entry.id)])).toEqual([
      ['copy', ['vexor-copy']],
      ['reaction', ['carbonide', 'ferrogel']],
      ['material', ['tritanium']],
      ['unknown', ['salvage', 'no-activity']],
    ])
  })

  it('counts the delivered lines of each activity group over the lines of that group and skips an empty group', () => {
    const tasks = tasksOf([
      job('reaction-1', 'reaction', { isFulfilled: true }),
      job('reaction-2', 'reaction'),
      job('reaction-3', 'reaction'),
      job('reaction-4', 'reaction'),
      line('tritanium', { isFulfilled: true }),
      line('pyerite', { isFulfilled: true }),
    ])

    expect(tasks.activityProgress).toEqual([
      { group: 'reaction', delivered: 1, total: 4, percent: 25 },
      { group: 'material', delivered: 2, total: 2, percent: 100 },
    ])
  })

  it('counts the delivered lines over every line of the BOM', () => {
    const tasks = tasksOf([
      ...Array.from({ length: 14 }, (_, index) => line(`done-${index}`, { isFulfilled: true })),
      ...Array.from({ length: 8 }, (_, index) => job(`todo-${index}`, 'manufacturing')),
    ])

    expect([tasks.deliveredLines, tasks.totalLines]).toEqual([14, 22])
  })

  it('adds up the free value of the priced material lines and counts the lines without price, never as zero', () => {
    const partlyPriced = tasksOf([
      line('tritanium', { freeQuantity: 1_000, estimatedPrice: 5.5 }),
      line('nocxium', { freeQuantity: 80, estimatedPrice: null }),
      job('ishtar', 'manufacturing', { freeQuantity: 2, estimatedPrice: 900 }),
    ])
    const unpriced = tasksOf([line('nocxium', { freeQuantity: 80, estimatedPrice: null })])
    const nothingFree = tasksOf([line('tritanium', { freeQuantity: 0, isFulfilled: true })])

    expect([partlyPriced.freeValue, unpriced.freeValue, nothingFree.freeValue]).toEqual([
      { total: 5_500, unknownLines: 1 },
      { total: null, unknownLines: 1 },
      { total: 0, unknownLines: 0 },
    ])
  })

  it('exposes the first product of the project with the number of the other products, and none without product', () => {
    const twoProducts = companionProject({
      items: [
        { typeId: 12_005, typeName: 'Ishtar', meLevel: 8, teLevel: 16, runs: 2 },
        { typeId: 11_993, typeName: 'Hammerhead II', meLevel: 0, teLevel: 0, runs: 6 },
      ],
    })

    expect([
      tasksOf([], twoProducts).product,
      tasksOf([], companionProject({ items: [] })).product,
    ]).toEqual([{ typeId: 12_005, typeName: 'Ishtar', runs: 2, otherCount: 1 }, null])
  })

  it('counts the ready jobs of my characters and carries the clock it was built with', () => {
    const ishtar = job('ishtar', 'manufacturing')
    ishtar.jobMatches = [
      jobMatch({ id: 'match-ready', status: 'ready', esiJobId: 1, characterName: 'Alpha' }),
      jobMatch({ id: 'match-over', status: 'active', esiJobId: 2, characterName: 'Alpha', endDate: new Date(NOW - ONE_MINUTE).toISOString() }),
      jobMatch({ id: 'match-running', status: 'active', esiJobId: 3, characterName: 'Alpha', endDate: new Date(NOW + ONE_HOUR).toISOString() }),
      jobMatch({ id: 'match-other', status: 'ready', esiJobId: 4, characterName: 'Beta' }),
    ]

    const tasks = tasksOf([ishtar])

    expect([tasks.readyJobCount, tasks.now]).toEqual([2, NOW])
  })
})

describe('jobTimingOf', () => {
  it.each([
    { label: 'a running job ending in 90 minutes', status: 'active', endDate: NOW + 90 * ONE_MINUTE, expected: { state: 'running', remainingMs: 90 * ONE_MINUTE } },
    { label: 'a running job whose end date passed a minute ago', status: 'active', endDate: NOW - ONE_MINUTE, expected: { state: 'ready', remainingMs: 0 } },
    { label: 'a ready job', status: 'ready', endDate: NOW - ONE_HOUR, expected: { state: 'ready', remainingMs: 0 } },
    { label: 'a paused job ending in 50 hours', status: 'paused', endDate: NOW + 50 * ONE_HOUR, expected: { state: 'paused', remainingMs: 50 * ONE_HOUR } },
    { label: 'a running job without end date', status: 'active', endDate: null, expected: { state: 'running', remainingMs: null } },
  ])('reads $label', ({ status, endDate, expected }) => {
    const match = jobMatch({ id: 'match', status, esiJobId: 1, endDate: endDate === null ? null : new Date(endDate).toISOString() })

    expect(jobTimingOf(match, NOW)).toEqual(expected)
  })
})

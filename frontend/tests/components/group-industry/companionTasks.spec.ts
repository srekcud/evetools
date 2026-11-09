import { buildCompanionTasks, type CompanionTasks } from '@/components/group-industry/companionTasks'
import type { BomItem, GroupMember, GroupProject } from '@/stores/group-industry/types'
import { bomLine, companionProject, jobMatch, member } from './companionFixtures'

const NOW = Date.parse('2026-11-09T12:00:00+00:00')
const ONE_MINUTE = 60_000
const ONE_HOUR = 3_600_000

function tasksOf(
  bomItems: BomItem[],
  options: { project?: GroupProject; members?: GroupMember[] } = {},
): CompanionTasks {
  return buildCompanionTasks({
    bomItems,
    project: options.project ?? companionProject(),
    members: options.members ?? [],
    myCharacterNames: ['Alpha'],
    now: NOW,
  })
}

function manufacturingJobLine(id: string, typeId: number, overrides: Partial<BomItem>): BomItem {
  return bomLine({ id, typeId, typeName: `Job ${typeId}`, isJob: true, activityType: 'manufacturing', runs: 2, ...overrides })
}

describe('buildCompanionTasks', () => {
  it('lists the incomplete lines with a free quantity and no linked job as free, and the incomplete lines I reserved as mine', () => {
    const nocxium = bomLine({
      id: 'bom-nocxium',
      typeId: 38,
      typeName: 'Nocxium',
      requiredQuantity: 150,
      remainingQuantity: 150,
      reservations: [
        { participantId: 'participant-alpha', characterName: 'Alpha', quantity: 50, isMine: true },
        { participantId: 'participant-zeta', characterName: 'Zeta', quantity: 20, isMine: false },
      ],
      reservedQuantity: 70,
      freeQuantity: 80,
    })
    const tritanium = bomLine({
      id: 'bom-tritanium',
      typeId: 34,
      typeName: 'Tritanium',
      requiredQuantity: 5000,
      fulfilledQuantity: 5000,
      remainingQuantity: 0,
      isFulfilled: true,
      reservations: [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity: 10, isMine: true }],
      reservedQuantity: 10,
      freeQuantity: 400,
    })
    const morphite = bomLine({
      id: 'bom-morphite',
      typeId: 11_399,
      typeName: 'Morphite',
      requiredQuantity: 300,
      remainingQuantity: 300,
      reservations: [{ participantId: 'participant-zeta', characterName: 'Zeta', quantity: 300, isMine: false }],
      reservedQuantity: 300,
      freeQuantity: 0,
    })
    const hammerhead = manufacturingJobLine('bom-hammerhead', 2_185, {
      jobGroup: 'component',
      freeQuantity: 4,
      jobMatches: [jobMatch({ id: 'match-hammerhead', esiJobId: 910_001, status: 'active', characterName: 'Zeta' })],
    })
    const vexor = manufacturingJobLine('bom-vexor', 626, { jobGroup: 'component', runs: 3, freeQuantity: 3 })

    const tasks = tasksOf([nocxium, tritanium, morphite, hammerhead, vexor])

    expect({
      free: tasks.free.map(item => item.id),
      mine: tasks.mine.map(entry => ({ id: entry.item.id, quantity: entry.quantity })),
    }).toEqual({
      free: ['bom-nocxium', 'bom-vexor'],
      mine: [{ id: 'bom-nocxium', quantity: 50 }],
    })
  })

  it('keeps only the ready or running jobs of my characters and raises one job ready alert per ready job of mine', () => {
    const alphaLine = manufacturingJobLine('bom-ferox', 16_227, {
      jobMatches: [
        jobMatch({ id: 'match-alpha-ready', esiJobId: 920_001, status: 'ready', characterName: 'Alpha', runs: 2 }),
        jobMatch({ id: 'match-alpha-delivered', esiJobId: 920_002, status: 'delivered', characterName: 'Alpha', runs: 5 }),
      ],
    })
    const zetaLine = manufacturingJobLine('bom-cormorant', 16_233, {
      jobMatches: [jobMatch({ id: 'match-zeta-ready', esiJobId: 920_003, status: 'ready', characterName: 'Zeta', runs: 3 })],
    })

    const tasks = tasksOf([alphaLine, zetaLine])

    expect({
      jobs: tasks.jobs.map(job => ({ match: job.match.id, line: job.item.id })),
      alerts: tasks.alerts,
    }).toEqual({
      jobs: [{ match: 'match-alpha-ready', line: 'bom-ferox' }],
      alerts: [{ key: 'job_ready:match-alpha-ready', kind: 'job_ready' }],
    })
  })

  it('raises a job ready alert for a running job whose end date passed a minute ago and none for one ending in an hour', () => {
    const line = manufacturingJobLine('bom-thorax', 16_240, {
      jobMatches: [
        jobMatch({ id: 'match-late', esiJobId: 930_001, status: 'active', characterName: 'Alpha', endDate: new Date(NOW - ONE_MINUTE).toISOString() }),
        jobMatch({ id: 'match-later', esiJobId: 930_002, status: 'active', characterName: 'Alpha', endDate: new Date(NOW + ONE_HOUR).toISOString() }),
      ],
    })

    const tasks = tasksOf([line])

    expect({
      jobs: tasks.jobs.map(job => job.match.id),
      alerts: tasks.alerts,
    }).toEqual({
      jobs: ['match-late', 'match-later'],
      alerts: [{ key: 'job_ready:match-late', kind: 'job_ready' }],
    })
  })

  it('raises the BOM complete alert only when every one of at least one material is complete', () => {
    const materials = (incomplete: number): BomItem[] => Array.from({ length: 13 }, (_, index) => bomLine({
      id: `bom-material-${index}`,
      typeId: 34 + index,
      typeName: `Material ${index}`,
      requiredQuantity: 100 + index,
      fulfilledQuantity: index < incomplete ? 100 + index : 0,
      isFulfilled: index < incomplete,
    }))
    const onlyAJob = [manufacturingJobLine('bom-only-job', 16_227, { isFulfilled: true })]

    expect({
      thirteenOfThirteen: tasksOf(materials(13)).alerts,
      twelveOfThirteen: tasksOf(materials(12)).alerts,
      noMaterial: tasksOf(onlyAJob).alerts,
    }).toEqual({
      thirteenOfThirteen: [{ key: 'bom_complete', kind: 'bom_complete' }],
      twelveOfThirteen: [],
      noMaterial: [],
    })
  })

  it('counts the contributions to review and the pending join requests for an admin only, and raises the join request alert for them', () => {
    const members = [
      member({ id: 'member-1', status: 'pending', characterName: 'Brann Holt' }),
      member({ id: 'member-2', status: 'pending', characterName: 'Aria Solberg' }),
      member({ id: 'member-3', status: 'accepted', characterName: 'Selene Vance' }),
    ]

    const admin = tasksOf([], { project: companionProject({ myRole: 'admin', pendingContributionCount: 3 }), members })
    const plainMember = tasksOf([], { project: companionProject({ myRole: 'member', pendingContributionCount: null }), members })

    expect({
      admin: { toReview: admin.toReview, joinRequests: admin.joinRequests, alerts: admin.alerts },
      plainMember: { toReview: plainMember.toReview, joinRequests: plainMember.joinRequests, alerts: plainMember.alerts },
    }).toEqual({
      admin: { toReview: 3, joinRequests: 2, alerts: [{ key: 'join_request', kind: 'join_request' }] },
      plainMember: { toReview: null, joinRequests: null, alerts: [] },
    })
  })

  it('reports the project fulfillment percent and counts the running linked jobs of the final lines only', () => {
    const finalOne = manufacturingJobLine('bom-final-one', 12_005, {
      jobGroup: 'final',
      jobMatches: [
        jobMatch({ id: 'match-final-1a', esiJobId: 940_001, status: 'active', characterName: 'Zeta' }),
        jobMatch({ id: 'match-final-1b', esiJobId: 940_002, status: 'active', characterName: 'Alpha' }),
        jobMatch({ id: 'match-final-1c', esiJobId: 940_003, status: 'delivered', characterName: 'Zeta' }),
      ],
    })
    const finalTwo = manufacturingJobLine('bom-final-two', 12_011, {
      jobGroup: 'final',
      jobMatches: [jobMatch({ id: 'match-final-2a', esiJobId: 940_004, status: 'active', characterName: 'Brann Holt' })],
    })
    const component = manufacturingJobLine('bom-component', 11_539, {
      jobGroup: 'component',
      jobMatches: [jobMatch({ id: 'match-component', esiJobId: 940_005, status: 'active', characterName: 'Zeta' })],
    })

    const tasks = tasksOf([finalOne, finalTwo, component], { project: companionProject({ fulfillmentPercent: 37 }) })

    expect({ bomPercent: tasks.bomPercent, finalJobsRunning: tasks.finalJobsRunning }).toEqual({ bomPercent: 37, finalJobsRunning: 3 })
  })
})

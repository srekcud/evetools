import { buildCompanionTasks, type CompanionTasks } from '@/components/group-industry/companionTasks'
import type { BomItem, ChainNode, GroupMember, GroupProject, ProjectPriceAlert, ProjectSellOrder } from '@/stores/group-industry/types'
import { chainNode } from '../../productionChainNodes'
import { sellOrder } from '../../sellOrderFixtures'
import { bomLine, companionProject, jobLocations, jobMatch, member } from './companionFixtures'

const NOW = Date.parse('2026-11-09T12:00:00+00:00')
const ONE_MINUTE = 60_000
const ONE_HOUR = 3_600_000

function tasksOf(
  bomItems: BomItem[],
  options: { project?: GroupProject; members?: GroupMember[]; priceAlert?: ProjectPriceAlert | null; undercutOrders?: ProjectSellOrder[]; chainNodes?: ChainNode[] | null; unassignedJobCount?: number | null } = {},
): CompanionTasks {
  return buildCompanionTasks({
    bomItems,
    project: options.project ?? companionProject(),
    members: options.members ?? [],
    priceAlert: options.priceAlert ?? null,
    undercutOrders: options.undercutOrders ?? [],
    chainNodes: options.chainNodes ?? null,
    unassignedJobCount: options.unassignedJobCount ?? null,
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

  it('keeps a job line free when its only linked jobs were cancelled or reverted and hides it once a linked job is running, paused, ready or delivered', () => {
    const lineLinkedTo = (id: string, statuses: string[]): BomItem => manufacturingJobLine(id, 2_185, {
      freeQuantity: 4,
      jobMatches: statuses.map((status, index) => jobMatch({ id: `${id}-match-${index}`, esiJobId: 960_000 + index, status, characterName: 'Zeta' })),
    })

    const tasks = tasksOf([
      lineLinkedTo('bom-cancelled', ['cancelled']),
      lineLinkedTo('bom-reverted', ['reverted']),
      lineLinkedTo('bom-cancelled-then-active', ['cancelled', 'active']),
      lineLinkedTo('bom-paused', ['paused']),
      lineLinkedTo('bom-ready', ['ready']),
      lineLinkedTo('bom-delivered', ['delivered']),
      lineLinkedTo('bom-cancelled-then-delivered', ['cancelled', 'delivered']),
    ])

    expect(tasks.free.map(item => item.id)).toEqual(['bom-cancelled', 'bom-reverted'])
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

  it('raises the jobs to assign alert after the join request one when the count is above zero, and none for zero or an unknown count', () => {
    const pendingMember = [member({ id: 'member-1', status: 'pending', characterName: 'Brann Holt' })]
    const admin = companionProject({ myRole: 'admin' })

    const three = tasksOf([], { unassignedJobCount: 3 })
    const zero = tasksOf([], { unassignedJobCount: 0 })
    const unknown = tasksOf([], { unassignedJobCount: null })
    const withJoinRequest = tasksOf([], { project: admin, members: pendingMember, unassignedJobCount: 2 })

    expect({
      three: { count: three.unassignedJobCount, alerts: three.alerts },
      zero: { count: zero.unassignedJobCount, alerts: zero.alerts },
      unknown: { count: unknown.unassignedJobCount, alerts: unknown.alerts },
      withJoinRequest: withJoinRequest.alerts,
    }).toEqual({
      three: { count: 3, alerts: [{ key: 'jobs_to_assign', kind: 'jobs_to_assign' }] },
      zero: { count: 0, alerts: [] },
      unknown: { count: null, alerts: [] },
      withJoinRequest: [{ key: 'join_request', kind: 'join_request' }, { key: 'jobs_to_assign', kind: 'jobs_to_assign' }],
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

  describe('move products', () => {
    const NO_MOVE = { quantity: 0, jobCount: 0, unknownJobCount: 0 }
    const MOVE_PRODUCTS_ALERT = { key: 'move_products', kind: 'move_products' }

    function hammerheadLine(jobMatches: BomItem['jobMatches'], overrides: Partial<BomItem> = {}): BomItem {
      return manufacturingJobLine('bom-hammerhead-ii', 2_185, { runs: 4, requiredQuantity: 20, jobMatches, ...overrides })
    }

    const CONTROL_ONLY = { quantity: 5, jobCount: 1, unknownJobCount: 0 }
    const CONTROL_EXPECTED = { moveProducts: CONTROL_ONLY, alerts: [MOVE_PRODUCTS_ALERT] }

    function controlLine(): BomItem {
      return manufacturingJobLine('bom-control-ferox', 16_227, {
        runs: 8,
        requiredQuantity: 8,
        jobMatches: [
          jobMatch({ id: 'match-control', esiJobId: 950_900, status: 'active', characterName: 'Alpha', runs: 5, locations: jobLocations('outside') }),
        ],
      })
    }

    function moveProductsAlerts(tasks: CompanionTasks): CompanionTasks['alerts'] {
      return tasks.alerts.filter(alert => alert.kind === 'move_products')
    }

    it('adds the product quantity of a job of mine whose output is outside the container and raises the move products alert', () => {
      const line = hammerheadLine([
        jobMatch({ id: 'match-out', esiJobId: 950_001, status: 'ready', characterName: 'Alpha', runs: 2, locations: jobLocations('outside') }),
      ])

      const tasks = tasksOf([line])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual({
        moveProducts: { quantity: 10, jobCount: 1, unknownJobCount: 0 },
        alerts: [MOVE_PRODUCTS_ALERT],
      })
    })

    it('adds up the quantities of several jobs outside the container and raises a single move products alert', () => {
      const line = hammerheadLine([
        jobMatch({ id: 'match-out-a', esiJobId: 950_011, status: 'active', characterName: 'Alpha', runs: 2, locations: jobLocations('outside') }),
        jobMatch({ id: 'match-out-b', esiJobId: 950_012, status: 'paused', characterName: 'Alpha', runs: 2, locations: jobLocations('outside') }),
      ], { activityType: 'reaction' })

      const tasks = tasksOf([line])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual({
        moveProducts: { quantity: 20, jobCount: 2, unknownJobCount: 0 },
        alerts: [MOVE_PRODUCTS_ALERT],
      })
    })

    it('ignores a job whose output is already inside the container', () => {
      const line = hammerheadLine([
        jobMatch({ id: 'match-in', esiJobId: 950_021, status: 'ready', characterName: 'Alpha', runs: 2, locations: jobLocations('inside') }),
      ])

      const tasks = tasksOf([line, controlLine()])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual(CONTROL_EXPECTED)
    })

    it('counts a job with an unknown output location as unknown, never as a quantity, and raises no move products alert', () => {
      const line = hammerheadLine([
        jobMatch({ id: 'match-unknown', esiJobId: 950_031, status: 'active', characterName: 'Alpha', runs: 3, locations: jobLocations('unknown', { unknownReason: 'location_unresolved' }) }),
      ])

      const tasks = tasksOf([line])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual({
        moveProducts: { quantity: 0, jobCount: 0, unknownJobCount: 1 },
        alerts: [],
      })
    })

    it('keeps the unknown job out of the quantity while the job outside the container raises the alert', () => {
      const line = hammerheadLine([
        jobMatch({ id: 'match-out', esiJobId: 950_041, status: 'active', characterName: 'Alpha', runs: 2, locations: jobLocations('outside') }),
        jobMatch({ id: 'match-unknown', esiJobId: 950_042, status: 'active', characterName: 'Alpha', runs: 1, locations: jobLocations('unknown', { unknownReason: 'location_unresolved' }) }),
      ])

      const tasks = tasksOf([line])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual({
        moveProducts: { quantity: 10, jobCount: 1, unknownJobCount: 1 },
        alerts: [MOVE_PRODUCTS_ALERT],
      })
    })

    it('ignores the jobs of another player outside the container', () => {
      const line = hammerheadLine([
        jobMatch({ id: 'match-zeta', esiJobId: 950_051, status: 'ready', characterName: 'Zeta', runs: 2, locations: jobLocations('outside') }),
      ])

      const tasks = tasksOf([line, controlLine()])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual(CONTROL_EXPECTED)
    })

    it('ignores a delivered or cancelled job of mine outside the container', () => {
      const line = hammerheadLine([
        jobMatch({ id: 'match-delivered', esiJobId: 950_061, status: 'delivered', characterName: 'Alpha', runs: 2, locations: jobLocations('outside') }),
        jobMatch({ id: 'match-cancelled', esiJobId: 950_062, status: 'cancelled', characterName: 'Alpha', runs: 1, locations: jobLocations('outside') }),
      ])

      const tasks = tasksOf([line, controlLine()])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual(CONTROL_EXPECTED)
    })

    it('evaluates a job of mine without locations neither as outside nor as unknown', () => {
      const withoutLocations = hammerheadLine([
        jobMatch({ id: 'match-absent', esiJobId: 950_071, status: 'ready', characterName: 'Alpha', runs: 2 }),
      ])
      const nullLocations = hammerheadLine([
        jobMatch({ id: 'match-null', esiJobId: 950_072, status: 'ready', characterName: 'Alpha', runs: 2, locations: null }),
      ])

      const absent = tasksOf([withoutLocations, controlLine()])
      const nulled = tasksOf([nullLocations, controlLine()])

      expect({
        absent: { moveProducts: absent.moveProducts, alerts: moveProductsAlerts(absent) },
        nulled: { moveProducts: nulled.moveProducts, alerts: moveProductsAlerts(nulled) },
      }).toEqual({
        absent: CONTROL_EXPECTED,
        nulled: CONTROL_EXPECTED,
      })
    })

    it('ignores the jobs of a line whose activity is not manufacturing or reaction', () => {
      const copyLine = bomLine({
        id: 'bom-copy-hammerhead',
        typeId: 2_186,
        typeName: 'Hammerhead II Blueprint',
        isJob: true,
        activityType: 'copy',
        runs: 4,
        requiredQuantity: 20,
        jobMatches: [
          jobMatch({ id: 'match-copy', esiJobId: 950_081, status: 'active', characterName: 'Alpha', runs: 2, locations: jobLocations('outside') }),
        ],
      })

      const tasks = tasksOf([copyLine, controlLine()])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual(CONTROL_EXPECTED)
    })

    it('counts a job as unknown when the planned runs of its line are missing, never as a zero quantity', () => {
      const line = hammerheadLine([
        jobMatch({ id: 'match-no-runs', esiJobId: 950_091, status: 'active', characterName: 'Alpha', runs: 2, locations: jobLocations('outside') }),
      ], { runs: null })

      const tasks = tasksOf([line])

      expect({ moveProducts: tasks.moveProducts, alerts: moveProductsAlerts(tasks) }).toEqual({
        moveProducts: { quantity: 0, jobCount: 0, unknownJobCount: 1 },
        alerts: [],
      })
    })

    it('reads only the output location: a blueprint outside with the output inside is ignored, an output outside with the blueprint inside is counted', () => {
      const outputOutside = hammerheadLine([
        jobMatch({ id: 'match-output-out', esiJobId: 950_101, status: 'active', characterName: 'Alpha', runs: 2, locations: jobLocations('outside', { blueprint: 'inside' }) }),
      ])
      const blueprintOutside = hammerheadLine([
        jobMatch({ id: 'match-blueprint-out', esiJobId: 950_102, status: 'active', characterName: 'Alpha', runs: 3, locations: jobLocations('inside', { blueprint: 'outside' }) }),
      ])

      const counted = tasksOf([outputOutside])
      const ignored = tasksOf([blueprintOutside])

      expect({ counted: counted.moveProducts, ignored: ignored.moveProducts }).toEqual({
        counted: { quantity: 10, jobCount: 1, unknownJobCount: 0 },
        ignored: NO_MOVE,
      })
    })
  })
})

describe('buildCompanionTasks price alert', () => {
  const PRICE_ALERT: ProjectPriceAlert = {
    typeId: 11_993,
    typeName: 'Hammerhead II',
    quantity: 6,
    jitaUnitPrice: 690_000,
    breakEvenPerUnit: 760_000,
    gapPerUnit: 70_000,
  }

  function priceAlertsOf(tasks: CompanionTasks): CompanionTasks['alerts'] {
    return tasks.alerts.filter(alert => alert.kind === 'price_below_break_even')
  }

  it('adds one visual alert and keeps the price alert of the project when Jita is below the break-even', () => {
    const tasks = tasksOf([], { priceAlert: PRICE_ALERT })

    expect({ alerts: priceAlertsOf(tasks), priceAlert: tasks.priceAlert }).toEqual({
      alerts: [{ key: 'price_below_break_even', kind: 'price_below_break_even' }],
      priceAlert: PRICE_ALERT,
    })
  })

  it('adds no price alert when the project has none, and leaves the other alerts untouched', () => {
    const withoutAlert = tasksOf([], { priceAlert: null })
    const withAlert = tasksOf([], { priceAlert: PRICE_ALERT })

    expect({
      priceAlert: withoutAlert.priceAlert,
      alerts: withoutAlert.alerts,
      otherAlertsWithAlert: withAlert.alerts.filter(alert => alert.kind !== 'price_below_break_even'),
    }).toEqual({ priceAlert: null, alerts: [], otherAlertsWithAlert: [] })
  })
})

describe('buildCompanionTasks undercut sell orders', () => {
  function undercutAlertsOf(tasks: CompanionTasks): CompanionTasks['alerts'] {
    return tasks.alerts.filter(alert => alert.kind === 'undercut_sell_order')
  }

  it('adds one visual alert for all the undercut orders and keeps them for the alert text', () => {
    const undercutOrders = [sellOrder(), sellOrder({ orderId: 6_350_000_002, typeName: 'Sabre' })]

    const tasks = tasksOf([], { undercutOrders })

    expect({ alerts: undercutAlertsOf(tasks), undercutOrders: tasks.undercutOrders }).toEqual({
      alerts: [{ key: 'undercut_sell_order', kind: 'undercut_sell_order' }],
      undercutOrders,
    })
  })

  it('adds no alert without an undercut order, and leaves the other alerts untouched', () => {
    const withoutOrder = tasksOf([])
    const withOrder = tasksOf([], { undercutOrders: [sellOrder()] })

    expect({
      undercutOrders: withoutOrder.undercutOrders,
      alerts: withoutOrder.alerts,
      otherAlertsWithOrder: withOrder.alerts.filter(alert => alert.kind !== 'undercut_sell_order'),
    }).toEqual({ undercutOrders: [], alerts: [], otherAlertsWithOrder: [] })
  })
})

describe('buildCompanionTasks awaiting approval', () => {
  function reservedLine(quantity: number, pendingQuantity: number, isMine = true): BomItem {
    return bomLine({
      id: 'bom-nocxium',
      typeId: 38,
      typeName: 'Nocxium',
      requiredQuantity: 150,
      remainingQuantity: 150,
      reservations: [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity, isMine }],
      pendingContributions: pendingQuantity > 0
        ? [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity: pendingQuantity, isMine }]
        : [],
      reservedQuantity: quantity,
      freeQuantity: 150 - quantity,
    })
  }

  function summaryOf(tasks: CompanionTasks) {
    return {
      mine: tasks.mine.map(entry => ({ id: entry.item.id, quantity: entry.quantity })),
      awaitingApproval: tasks.awaitingApproval.map(entry => ({ id: entry.item.id, quantity: entry.quantity })),
    }
  }

  it('moves a line whose reserved quantity is fully covered by my pending contributions out of mine and into awaiting approval', () => {
    const tasks = tasksOf([reservedLine(50, 50)])

    expect(summaryOf(tasks)).toEqual({ mine: [], awaitingApproval: [{ id: 'bom-nocxium', quantity: 50 }] })
  })

  it('keeps only the remaining quantity to deliver when the delivery is partial', () => {
    const tasks = tasksOf([reservedLine(50, 20)])

    expect(summaryOf(tasks)).toEqual({
      mine: [{ id: 'bom-nocxium', quantity: 30 }],
      awaitingApproval: [{ id: 'bom-nocxium', quantity: 20 }],
    })
  })

  it('brings the whole reservation back to deliver once the pending contribution is rejected', () => {
    const tasks = tasksOf([reservedLine(50, 0)])

    expect(summaryOf(tasks)).toEqual({ mine: [{ id: 'bom-nocxium', quantity: 50 }], awaitingApproval: [] })
  })

  it('ignores the pending contributions of other participants', () => {
    const tasks = tasksOf([reservedLine(50, 50, false)])

    expect(summaryOf(tasks)).toEqual({ mine: [], awaitingApproval: [] })
  })

  it('lists a completed line whose contribution of mine still awaits approval, without any reservation', () => {
    const completed = bomLine({
      id: 'bom-construction-blocks',
      typeId: 3_828,
      typeName: 'Construction Blocks',
      isFulfilled: true,
      remainingQuantity: 0,
      pendingContributions: [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity: 2_672, isMine: true }],
    })

    expect(summaryOf(tasksOf([completed]))).toEqual({
      mine: [],
      awaitingApproval: [{ id: 'bom-construction-blocks', quantity: 2_672 }],
    })
  })

  it('lists an incomplete line with a pending contribution of mine and no reservation', () => {
    const unreserved = bomLine({
      id: 'bom-neodymium',
      typeId: 16_660,
      typeName: 'Neodymium',
      pendingContributions: [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity: 2_300, isMine: true }],
    })

    expect(summaryOf(tasksOf([unreserved]))).toEqual({ mine: [], awaitingApproval: [{ id: 'bom-neodymium', quantity: 2_300 }] })
  })

  it('counts only my own quantity when other participants also have pending contributions on the line', () => {
    const shared = bomLine({
      id: 'bom-thulium',
      typeId: 16_665,
      typeName: 'Thulium',
      isFulfilled: true,
      remainingQuantity: 0,
      pendingContributions: [
        { participantId: 'participant-zeta', characterName: 'Zeta', quantity: 88, isMine: false },
        { participantId: 'participant-alpha', characterName: 'Alpha', quantity: 9_800, isMine: true },
      ],
    })

    expect(summaryOf(tasksOf([shared])).awaitingApproval).toEqual([{ id: 'bom-thulium', quantity: 9_800 }])
  })

  it('does not subtract the pending quantity of another participant from my reservation', () => {
    const shared = {
      ...reservedLine(50, 0),
      pendingContributions: [{ participantId: 'participant-zeta', characterName: 'Zeta', quantity: 88, isMine: false }],
    }

    expect(summaryOf(tasksOf([shared]))).toEqual({ mine: [{ id: 'bom-nocxium', quantity: 50 }], awaitingApproval: [] })
  })

  it('drops a line from mine when my pending quantity exceeds my reservation', () => {
    expect(summaryOf(tasksOf([reservedLine(30, 40)]))).toEqual({ mine: [], awaitingApproval: [{ id: 'bom-nocxium', quantity: 40 }] })
  })

  it('does not list a completed line as to deliver even when I hold a reservation on it', () => {
    const completed = { ...reservedLine(50, 50), isFulfilled: true }

    expect(summaryOf(tasksOf([completed])).mine).toEqual([])
  })
  describe('craft line order of the production chain', () => {
    const ISHTAR = manufacturingJobLine('bom-ishtar', 12_005, { typeName: 'Ishtar', jobGroup: 'final', freeQuantity: 2 })
    const HULL = manufacturingJobLine('bom-hull', 12_006, { typeName: 'Hull', jobGroup: 'component', freeQuantity: 2 })
    const SENSOR_CLUSTER = manufacturingJobLine('bom-sensor-cluster', 12_007, { typeName: 'Sensor Cluster', jobGroup: 'component', freeQuantity: 2 })
    const ARMOR_PLATE = manufacturingJobLine('bom-armor-plate', 12_008, { typeName: 'Armor Plate', jobGroup: 'component', freeQuantity: 2 })
    const CAPACITOR_CONSOLE = manufacturingJobLine('bom-capacitor-console', 12_009, { typeName: 'Capacitor Console', jobGroup: 'component', freeQuantity: 2 })
    const TRITANIUM = bomLine({ id: 'bom-tritanium', typeId: 34, typeName: 'Tritanium', freeQuantity: 400 })
    const BOM_ORDER = [ISHTAR, HULL, SENSOR_CLUSTER, ARMOR_PLATE, CAPACITOR_CONSOLE]
    const CHAIN = [
      chainNode({ id: CAPACITOR_CONSOLE.id, stage: 'component', status: 'todo' }),
      chainNode({ id: ARMOR_PLATE.id, stage: 'component', status: 'missing_materials' }),
      chainNode({ id: SENSOR_CLUSTER.id, stage: 'component', status: 'unknown' }),
      chainNode({ id: HULL.id, stage: 'component', status: 'active' }),
      chainNode({ id: ISHTAR.id, stage: 'product', status: 'blocked' }),
    ]
    const idsOf = (items: BomItem[]): string[] => items.map(item => item.id)

    it('puts the launchable component lines first and the final product last in the free lines', () => {
      expect(idsOf(tasksOf(BOM_ORDER, { chainNodes: CHAIN }).free)).toEqual([
        'bom-capacitor-console',
        'bom-armor-plate',
        'bom-sensor-cluster',
        'bom-hull',
        'bom-ishtar',
      ])
    })

    it('orders my build lines to deliver like the chain', () => {
      const reserved = BOM_ORDER.map(item => ({
        ...item,
        reservations: [{ participantId: 'participant-alpha', characterName: 'Alpha', quantity: 1, isMine: true }],
      }))

      expect(tasksOf(reserved, { chainNodes: CHAIN }).mine.map(entry => entry.item.id)).toEqual([
        'bom-capacitor-console',
        'bom-armor-plate',
        'bom-sensor-cluster',
        'bom-hull',
        'bom-ishtar',
      ])
    })

    it('keeps a material at its own slot while the job lines are reordered around it', () => {
      const bom = [ISHTAR, TRITANIUM, CAPACITOR_CONSOLE]

      expect(idsOf(tasksOf(bom, { chainNodes: CHAIN }).free)).toEqual(['bom-capacitor-console', 'bom-tritanium', 'bom-ishtar'])
    })

    it('keeps the BOM order and tags nothing while the chain is not loaded', () => {
      const tasks = tasksOf(BOM_ORDER, { chainNodes: null })

      expect({ free: idsOf(tasks.free), waiting: tasks.waitingForComponents }).toEqual({
        free: ['bom-ishtar', 'bom-hull', 'bom-sensor-cluster', 'bom-armor-plate', 'bom-capacitor-console'],
        waiting: [],
      })
    })

    it('lists the blocked job lines as waiting for components, and no other status', () => {
      expect(tasksOf(BOM_ORDER, { chainNodes: CHAIN }).waitingForComponents).toEqual(['bom-ishtar'])
    })

    it('puts a job line missing from the loaded chain after every ranked job line', () => {
      const chainWithoutHull = CHAIN.filter(node => node.id !== HULL.id)

      expect(idsOf(tasksOf(BOM_ORDER, { chainNodes: chainWithoutHull }).free)).toEqual([
        'bom-capacitor-console',
        'bom-armor-plate',
        'bom-sensor-cluster',
        'bom-ishtar',
        'bom-hull',
      ])
    })
  })
})

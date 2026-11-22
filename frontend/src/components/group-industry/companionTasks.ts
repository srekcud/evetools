import { jobDisplayOf, type JobDisplay, type JobDisplayState } from './jobDisplayState'
import { pendingQuantityOf } from './pendingContributions'
import type { BomItem, ChainNode, GroupMember, GroupProject, JobMatch, ProjectPriceAlert, ProjectSellOrder } from '@/stores/group-industry/types'

export type CompanionAlertKind = 'job_ready' | 'bom_complete' | 'join_request' | 'jobs_to_assign' | 'move_products' | 'price_below_break_even' | 'undercut_sell_order'

export type CompanionAlert = { key: string; kind: CompanionAlertKind }

export type MoveProducts = { quantity: number; jobCount: number; unknownJobCount: number }

export type ActivityGroup = 'copy' | 'invention' | 'reaction' | 'manufacturing' | 'material' | 'unknown'

export type ActivityProgress = { group: ActivityGroup; delivered: number; total: number; percent: number }

export type CompanionProduct = { typeId: number; typeName: string; runs: number; otherCount: number }

export type FreeValue = { total: number | null; unknownLines: number }

export type JobTiming = JobDisplay

export type CompanionTasks = {
  now: number
  product: CompanionProduct | null
  activityProgress: ActivityProgress[]
  deliveredLines: number
  totalLines: number
  freeValue: FreeValue
  readyJobCount: number
  free: BomItem[]
  mine: { item: BomItem; quantity: number }[]
  awaitingApproval: { item: BomItem; quantity: number }[]
  toReview: number | null
  joinRequests: number | null
  unassignedJobCount: number | null
  jobs: { match: JobMatch; item: BomItem }[]
  bomPercent: number
  finalJobsRunning: number
  alerts: CompanionAlert[]
  moveProducts: MoveProducts
  priceAlert: ProjectPriceAlert | null
  undercutOrders: ProjectSellOrder[]
  waitingForComponents: string[]
}

export type CompanionTasksInput = {
  bomItems: BomItem[]
  chainNodes: ChainNode[] | null
  project: GroupProject
  members: GroupMember[]
  myCharacterNames: string[]
  priceAlert: ProjectPriceAlert | null
  undercutOrders: ProjectSellOrder[]
  unassignedJobCount: number | null
  now: number
}

const MY_JOB_STATUSES = ['active', 'paused', 'ready']
const WORKED_JOB_STATUSES = ['active', 'paused', 'ready', 'delivered']
const RUNNING_STATE: JobDisplayState = 'running'
const FINAL_JOB_GROUP = 'final'
const PENDING_MEMBER_STATUS = 'pending'
const PRODUCT_ACTIVITIES = ['manufacturing', 'reaction']
const OUTPUT_OUTSIDE = 'outside'
const OUTPUT_UNKNOWN = 'unknown'
const MOVE_PRODUCTS_ALERT_KEY = 'move_products'
const JOBS_TO_ASSIGN_ALERT_KEY = 'jobs_to_assign'
const PRICE_ALERT_KEY = 'price_below_break_even'
const UNDERCUT_ORDER_ALERT_KEY = 'undercut_sell_order'
const BLOCKED_CHAIN_STATUS = 'blocked'
const PERCENT_BASE = 100
const MS_PER_MINUTE = 60_000
const MINUTES_PER_HOUR = 60
const MINUTES_PER_DAY = 1_440
const MATERIAL_GROUP: ActivityGroup = 'material'
const UNKNOWN_GROUP: ActivityGroup = 'unknown'
const KNOWN_JOB_ACTIVITIES: ActivityGroup[] = ['copy', 'invention', 'reaction', 'manufacturing']
export const ACTIVITY_GROUP_ORDER: ActivityGroup[] = ['copy', 'invention', 'reaction', 'manufacturing', 'material', 'unknown']

function isFree(item: BomItem): boolean {
  const alreadyWorked = item.isJob && item.jobMatches.some(match => WORKED_JOB_STATUSES.includes(match.status))
  return !item.isFulfilled && item.freeQuantity > 0 && !alreadyWorked
}

function isReadyToDeliver(match: JobMatch, now: number): boolean {
  return jobDisplayOf(match, now).state === 'ready'
}

function productQuantityOf(match: JobMatch, item: BomItem): number | null {
  if (item.runs === null || item.runs === 0) return null
  return match.runs * item.requiredQuantity / item.runs
}

function moveProductsOf(jobs: CompanionTasks['jobs']): MoveProducts {
  const moveProducts: MoveProducts = { quantity: 0, jobCount: 0, unknownJobCount: 0 }
  for (const { match, item } of jobs) {
    if (match.locations === undefined || match.locations === null) continue
    if (item.activityType === null || !PRODUCT_ACTIVITIES.includes(item.activityType)) continue
    const outputStatus = match.locations.output.status
    if (outputStatus !== OUTPUT_OUTSIDE && outputStatus !== OUTPUT_UNKNOWN) continue
    const quantity = productQuantityOf(match, item)
    if (outputStatus === OUTPUT_UNKNOWN || quantity === null) {
      moveProducts.unknownJobCount += 1
      continue
    }
    moveProducts.quantity += quantity
    moveProducts.jobCount += 1
  }
  return moveProducts
}

function inChainOrder<Entry>(entries: Entry[], lineOf: (entry: Entry) => BomItem, chainNodes: ChainNode[] | null): Entry[] {
  if (chainNodes === null) return entries
  const rankByLineId = new Map(chainNodes.map((node, index) => [node.id, index]))
  const rankOf = (entry: Entry): number => rankByLineId.get(lineOf(entry).id) ?? chainNodes.length
  const orderedJobs = entries.filter(entry => lineOf(entry).isJob).sort((left, right) => rankOf(left) - rankOf(right))
  let nextJob = 0
  return entries.map(entry => (lineOf(entry).isJob ? orderedJobs[nextJob++] : entry))
}

function isAdmin(project: GroupProject): boolean {
  return project.myRole === 'owner' || project.myRole === 'admin'
}

export function activityGroupOf(item: BomItem): ActivityGroup {
  if (!item.isJob) return MATERIAL_GROUP
  const known = KNOWN_JOB_ACTIVITIES.find(activity => activity === item.activityType)
  return known ?? UNKNOWN_GROUP
}

export function groupByActivity<Entry>(entries: Entry[], itemOf: (entry: Entry) => BomItem): { group: ActivityGroup; entries: Entry[] }[] {
  return ACTIVITY_GROUP_ORDER
    .map(group => ({ group, entries: entries.filter(entry => activityGroupOf(itemOf(entry)) === group) }))
    .filter(grouped => grouped.entries.length > 0)
}

export function jobTimingOf(match: JobMatch, now: number): JobTiming {
  return jobDisplayOf(match, now)
}

export function countdownPartsOf(remainingMs: number): { days: number; hours: number; minutes: number } {
  const totalMinutes = Math.floor(remainingMs / MS_PER_MINUTE)
  return {
    days: Math.floor(totalMinutes / MINUTES_PER_DAY),
    hours: Math.floor((totalMinutes % MINUTES_PER_DAY) / MINUTES_PER_HOUR),
    minutes: totalMinutes % MINUTES_PER_HOUR,
  }
}

function activityProgressOf(bomItems: BomItem[]): ActivityProgress[] {
  return groupByActivity(bomItems, item => item)
    .map(({ group, entries }) => {
      const delivered = entries.filter(item => item.isFulfilled).length
      return { group, delivered, total: entries.length, percent: Math.round(delivered / entries.length * PERCENT_BASE) }
    })
}

function freeValueOf(free: BomItem[]): FreeValue {
  const materials = free.filter(item => !item.isJob)
  const pricedValues = materials.flatMap(item => (item.estimatedPrice === null ? [] : [item.freeQuantity * item.estimatedPrice]))
  const unknownLines = materials.length - pricedValues.length
  if (materials.length > 0 && pricedValues.length === 0) return { total: null, unknownLines }
  return { total: pricedValues.reduce((sum, value) => sum + value, 0), unknownLines }
}

function productOf(project: GroupProject): CompanionProduct | null {
  const [first, ...others] = project.items
  if (first === undefined) return null
  return { typeId: first.typeId, typeName: first.typeName, runs: first.runs, otherCount: others.length }
}

export function buildCompanionTasks(input: CompanionTasksInput): CompanionTasks {
  const { bomItems, chainNodes, project, members, myCharacterNames, priceAlert, undercutOrders, unassignedJobCount, now } = input

  const free = inChainOrder(bomItems.filter(isFree), item => item, chainNodes)

  const myReservations = bomItems.flatMap((item) => {
    const reservation = item.reservations.find(candidate => candidate.isMine)
    return !item.isFulfilled && reservation !== undefined ? [{ item, reservation }] : []
  })

  const reservationsToDeliver = myReservations
    .map(({ item, reservation }) => ({ item, quantity: reservation.quantity - pendingQuantityOf(item, reservation.participantId) }))
    .filter(entry => entry.quantity > 0)
  const mine = inChainOrder(reservationsToDeliver, entry => entry.item, chainNodes)

  const awaitingApproval = bomItems.flatMap((item) => {
    const quantity = item.pendingContributions
      .filter(contribution => contribution.isMine)
      .reduce((total, contribution) => total + contribution.quantity, 0)
    return quantity > 0 ? [{ item, quantity }] : []
  })

  const jobs = bomItems.flatMap(item => item.jobMatches
    .filter(match => match.characterName !== null
      && myCharacterNames.includes(match.characterName)
      && MY_JOB_STATUSES.includes(match.status))
    .map(match => ({ match, item })))

  const toReview = project.pendingContributionCount ?? null
  const joinRequests = isAdmin(project)
    ? members.filter(member => member.status === PENDING_MEMBER_STATUS).length
    : null

  const finalJobsRunning = bomItems
    .filter(item => item.jobGroup === FINAL_JOB_GROUP)
    .reduce((count, item) => count + item.jobMatches.filter(match => jobDisplayOf(match, now).state === RUNNING_STATE).length, 0)

  const materials = bomItems.filter(item => !item.isJob)

  const alerts: CompanionAlert[] = jobs
    .filter(({ match }) => isReadyToDeliver(match, now))
    .map(({ match }) => ({ key: `job_ready:${match.id}`, kind: 'job_ready' }))
  const moveProducts = moveProductsOf(jobs)
  if (moveProducts.jobCount > 0) alerts.push({ key: MOVE_PRODUCTS_ALERT_KEY, kind: 'move_products' })
  if (priceAlert !== null) alerts.push({ key: PRICE_ALERT_KEY, kind: 'price_below_break_even' })
  if (undercutOrders.length > 0) alerts.push({ key: UNDERCUT_ORDER_ALERT_KEY, kind: 'undercut_sell_order' })
  if (joinRequests !== null && joinRequests > 0) alerts.push({ key: 'join_request', kind: 'join_request' })
  if (materials.length > 0 && materials.every(material => material.isFulfilled)) {
    alerts.push({ key: 'bom_complete', kind: 'bom_complete' })
  }
  if (unassignedJobCount !== null && unassignedJobCount > 0) alerts.push({ key: JOBS_TO_ASSIGN_ALERT_KEY, kind: 'jobs_to_assign' })

  const waitingForComponents = (chainNodes ?? [])
    .filter(node => node.status === BLOCKED_CHAIN_STATUS)
    .map(node => node.id)
    .filter(nodeId => bomItems.some(item => item.isJob && item.id === nodeId))

  return {
    now,
    product: productOf(project),
    activityProgress: activityProgressOf(bomItems),
    deliveredLines: bomItems.filter(item => item.isFulfilled).length,
    totalLines: bomItems.length,
    freeValue: freeValueOf(free),
    readyJobCount: jobs.filter(({ match }) => isReadyToDeliver(match, now)).length,
    free,
    mine,
    awaitingApproval,
    toReview,
    joinRequests,
    unassignedJobCount,
    jobs,
    bomPercent: project.fulfillmentPercent,
    finalJobsRunning,
    alerts,
    moveProducts,
    priceAlert,
    undercutOrders,
    waitingForComponents,
  }
}

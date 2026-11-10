import type { BomItem, GroupMember, GroupProject, JobMatch } from '@/stores/group-industry/types'

export type CompanionAlertKind = 'job_ready' | 'bom_complete' | 'join_request' | 'move_products'

export type CompanionAlert = { key: string; kind: CompanionAlertKind }

export type MoveProducts = { quantity: number; jobCount: number; unknownJobCount: number }

export type CompanionTasks = {
  free: BomItem[]
  mine: { item: BomItem; quantity: number }[]
  toReview: number | null
  joinRequests: number | null
  jobs: { match: JobMatch; item: BomItem }[]
  bomPercent: number
  finalJobsRunning: number
  alerts: CompanionAlert[]
  moveProducts: MoveProducts
}

export type CompanionTasksInput = {
  bomItems: BomItem[]
  project: GroupProject
  members: GroupMember[]
  myCharacterNames: string[]
  now: number
}

const MY_JOB_STATUSES = ['active', 'paused', 'ready']
const READY_STATUS = 'ready'
const RUNNING_STATUS = 'active'
const FINAL_JOB_GROUP = 'final'
const PENDING_MEMBER_STATUS = 'pending'
const PRODUCT_ACTIVITIES = ['manufacturing', 'reaction']
const OUTPUT_OUTSIDE = 'outside'
const OUTPUT_UNKNOWN = 'unknown'
const MOVE_PRODUCTS_ALERT_KEY = 'move_products'

function isFree(item: BomItem): boolean {
  const alreadyHasLinkedJob = item.isJob && item.jobMatches.length > 0
  return !item.isFulfilled && item.freeQuantity > 0 && !alreadyHasLinkedJob
}

function isReadyToDeliver(match: JobMatch, now: number): boolean {
  if (match.status === READY_STATUS) return true
  return match.status === RUNNING_STATUS && match.endDate !== null && Date.parse(match.endDate) <= now
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

function isAdmin(project: GroupProject): boolean {
  return project.myRole === 'owner' || project.myRole === 'admin'
}

export function buildCompanionTasks(input: CompanionTasksInput): CompanionTasks {
  const { bomItems, project, members, myCharacterNames, now } = input

  const free = bomItems.filter(isFree)

  const mine = bomItems.flatMap((item) => {
    const reservation = item.reservations.find(candidate => candidate.isMine)
    return !item.isFulfilled && reservation !== undefined ? [{ item, quantity: reservation.quantity }] : []
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
    .reduce((count, item) => count + item.jobMatches.filter(match => match.status === RUNNING_STATUS).length, 0)

  const materials = bomItems.filter(item => !item.isJob)

  const alerts: CompanionAlert[] = jobs
    .filter(({ match }) => isReadyToDeliver(match, now))
    .map(({ match }) => ({ key: `job_ready:${match.id}`, kind: 'job_ready' }))
  const moveProducts = moveProductsOf(jobs)
  if (moveProducts.jobCount > 0) alerts.push({ key: MOVE_PRODUCTS_ALERT_KEY, kind: 'move_products' })
  if (joinRequests !== null && joinRequests > 0) alerts.push({ key: 'join_request', kind: 'join_request' })
  if (materials.length > 0 && materials.every(material => material.isFulfilled)) {
    alerts.push({ key: 'bom_complete', kind: 'bom_complete' })
  }

  return {
    free,
    mine,
    toReview,
    joinRequests,
    jobs,
    bomPercent: project.fulfillmentPercent,
    finalJobsRunning,
    alerts,
    moveProducts,
  }
}

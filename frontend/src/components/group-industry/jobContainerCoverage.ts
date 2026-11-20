import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, ContainerVerification, ContainerVerificationStatus } from '@/stores/group-industry/types'

const CONTAINER_LINE_STATUSES: readonly ContainerVerificationStatus[] = ['verified', 'partial', 'missing']
const COMPONENT_JOB_GROUP = 'component'

export function containerLineOf(job: BomItem): ContainerVerification | undefined {
  if (job.jobGroup !== COMPONENT_JOB_GROUP) return undefined
  return useGroupProjectStore().containerVerification.find(line => line.bomItemId === job.id && CONTAINER_LINE_STATUSES.includes(line.status))
}

function isReadFromTheContainer(job: BomItem): boolean {
  return job.jobGroup === COMPONENT_JOB_GROUP
    && job.containerStockState === 'known'
    && job.containerQuantity != null
    && job.jobMatches.length === 0
}

export function isPartlyCoveredByTheContainer(job: BomItem): boolean {
  if (!isReadFromTheContainer(job)) return false
  const covered = job.containerQuantity ?? 0
  return covered > 0 && covered < job.requiredQuantity
}

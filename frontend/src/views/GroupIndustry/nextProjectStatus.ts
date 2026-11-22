import type { GroupProjectStatus } from '@/stores/group-industry/types'

const NEXT_PROJECT_STATUSES: Record<GroupProjectStatus, GroupProjectStatus[]> = {
  draft: ['published', 'in_progress'],
  published: ['in_progress'],
  in_progress: ['selling'],
  selling: ['completed'],
  completed: [],
}

const PERSONAL_USE_NEXT_PROJECT_STATUSES: Record<GroupProjectStatus, GroupProjectStatus[]> = {
  ...NEXT_PROJECT_STATUSES,
  in_progress: ['completed'],
}

export function nextProjectStatuses(status: GroupProjectStatus, personalUse = false): GroupProjectStatus[] {
  return (personalUse ? PERSONAL_USE_NEXT_PROJECT_STATUSES : NEXT_PROJECT_STATUSES)[status]
}

export function headerStatuses(
  status: GroupProjectStatus,
  isPrivate: boolean,
  nextActionTarget: GroupProjectStatus | null,
  personalUse = false,
): GroupProjectStatus[] {
  return nextProjectStatuses(status, personalUse).filter(target => {
    const isPublishingPrivateProject = target === 'published' && isPrivate
    return !isPublishingPrivateProject && target !== nextActionTarget
  })
}

import type { GroupProjectStatus } from '@/stores/group-industry/types'

const NEXT_PROJECT_STATUSES: Record<GroupProjectStatus, GroupProjectStatus[]> = {
  draft: ['published', 'in_progress'],
  published: ['in_progress'],
  in_progress: ['selling'],
  selling: ['completed'],
  completed: [],
}

export function nextProjectStatuses(status: GroupProjectStatus): GroupProjectStatus[] {
  return NEXT_PROJECT_STATUSES[status]
}

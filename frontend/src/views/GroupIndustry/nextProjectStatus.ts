import type { GroupProjectStatus } from '@/stores/group-industry/types'

const NEXT_PROJECT_STATUS: Record<GroupProjectStatus, GroupProjectStatus | null> = {
  draft: null,
  published: 'in_progress',
  in_progress: 'selling',
  selling: 'completed',
  completed: null,
}

export function nextProjectStatus(status: GroupProjectStatus): GroupProjectStatus | null {
  return NEXT_PROJECT_STATUS[status]
}

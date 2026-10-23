export type GroupProjectRefresh = 'bom' | 'contributions' | 'members' | 'projectDetail' | 'sales' | 'distribution'

const REFRESHES_BY_ACTION: Record<string, GroupProjectRefresh[]> = {
  contribution_submitted: ['bom', 'contributions'],
  contribution_approved: ['bom', 'contributions'],
  contribution_rejected: ['bom', 'contributions'],
  member_joined: ['members', 'projectDetail'],
  member_left: ['members', 'projectDetail'],
  sale_recorded: ['sales', 'distribution'],
}

const DEFAULT_REFRESHES: GroupProjectRefresh[] = ['projectDetail', 'bom']

export function groupProjectRefreshes(action: string): GroupProjectRefresh[] {
  return REFRESHES_BY_ACTION[action] ?? DEFAULT_REFRESHES
}

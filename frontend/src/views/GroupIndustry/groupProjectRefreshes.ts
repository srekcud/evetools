export type GroupProjectRefresh = 'bom' | 'contributions' | 'members' | 'projectDetail' | 'sales' | 'saleSuggestions' | 'distribution'

const REFRESHES_BY_ACTION: Record<string, GroupProjectRefresh[]> = {
  contribution_submitted: ['bom', 'contributions', 'projectDetail'],
  contribution_approved: ['bom', 'contributions', 'projectDetail'],
  contribution_rejected: ['bom', 'contributions', 'projectDetail'],
  member_joined: ['members', 'projectDetail'],
  member_left: ['members', 'projectDetail'],
  sale_recorded: ['sales', 'saleSuggestions', 'distribution'],
  sale_updated: ['sales', 'saleSuggestions', 'distribution'],
  sale_deleted: ['sales', 'saleSuggestions', 'distribution'],
}

const DEFAULT_REFRESHES: GroupProjectRefresh[] = ['projectDetail', 'bom']

export function groupProjectRefreshes(action: string): GroupProjectRefresh[] {
  return REFRESHES_BY_ACTION[action] ?? DEFAULT_REFRESHES
}

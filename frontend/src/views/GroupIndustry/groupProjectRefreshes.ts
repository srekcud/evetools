export type GroupProjectRefresh = 'bom' | 'contributions' | 'members' | 'projectDetail' | 'sales' | 'saleSuggestions' | 'distribution' | 'cockpit'

const REFRESHES_BY_ACTION: Record<string, GroupProjectRefresh[]> = {
  contribution_submitted: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_approved: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_rejected: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_synced: ['bom', 'contributions', 'distribution', 'projectDetail', 'cockpit'],
  member_joined: ['members', 'projectDetail'],
  member_left: ['members', 'projectDetail'],
  sale_recorded: ['sales', 'saleSuggestions', 'distribution', 'cockpit'],
  sale_updated: ['sales', 'saleSuggestions', 'distribution', 'cockpit'],
  sale_deleted: ['sales', 'saleSuggestions', 'distribution', 'cockpit'],
}

const DEFAULT_REFRESHES: GroupProjectRefresh[] = ['projectDetail', 'bom', 'cockpit']

export function groupProjectRefreshes(action: string): GroupProjectRefresh[] {
  return REFRESHES_BY_ACTION[action] ?? DEFAULT_REFRESHES
}

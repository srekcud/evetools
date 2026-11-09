export type GroupProjectRefresh = 'bom' | 'contributions' | 'members' | 'projectDetail' | 'sales' | 'saleSuggestions' | 'distribution' | 'cockpit' | 'settlement' | 'activity'

const REFRESHES_BY_ACTION: Record<string, GroupProjectRefresh[]> = {
  contribution_submitted: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_approved: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_rejected: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_synced: ['bom', 'contributions', 'distribution', 'projectDetail', 'cockpit'],
  line_reserved: ['bom'],
  member_joined: ['members', 'projectDetail'],
  member_accepted: ['members', 'projectDetail'],
  member_left: ['members', 'projectDetail'],
  sale_recorded: ['sales', 'saleSuggestions', 'distribution', 'cockpit'],
  sale_updated: ['sales', 'saleSuggestions', 'distribution', 'cockpit'],
  sale_deleted: ['sales', 'saleSuggestions', 'distribution', 'cockpit'],
  fees_updated: ['projectDetail', 'distribution', 'cockpit'],
  transfer_received: ['settlement'],
}

const DEFAULT_REFRESHES: GroupProjectRefresh[] = ['projectDetail', 'bom', 'cockpit']

const ACTIONS_RECORDED_IN_THE_ACTIVITY_FEED = new Set([
  'contribution_submitted',
  'contribution_approved',
  'contribution_rejected',
  'member_joined',
  'member_accepted',
  'member_left',
  'sale_recorded',
  'sale_updated',
  'sale_deleted',
  'status_changed',
  'fees_updated',
  'jobs_detected',
  'jobs_finished',
])

export function groupProjectRefreshes(action: string): GroupProjectRefresh[] {
  return REFRESHES_BY_ACTION[action] ?? DEFAULT_REFRESHES
}

export function refreshesActivityOn(action: string): boolean {
  return ACTIONS_RECORDED_IN_THE_ACTIVITY_FEED.has(action)
}

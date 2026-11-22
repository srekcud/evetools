import type { ProjectDetailTab } from './projectDetailTabs'

export type GroupProjectRefresh = 'bom' | 'contributions' | 'members' | 'projectDetail' | 'sales' | 'saleSuggestions' | 'distribution' | 'cockpit' | 'settlement' | 'activity'

const REFRESHES_BY_ACTION: Record<string, GroupProjectRefresh[]> = {
  contribution_submitted: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_approved: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_rejected: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_updated: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_withdrawn: ['bom', 'contributions', 'projectDetail', 'cockpit'],
  contribution_synced: ['bom', 'contributions', 'distribution', 'projectDetail', 'cockpit'],
  contribution_valued: ['bom', 'contributions', 'distribution', 'projectDetail', 'cockpit'],
  unclaimed_stock_allocated: ['bom', 'contributions', 'projectDetail', 'cockpit'],
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

const TABS_SHOWING_REFRESH: Partial<Record<GroupProjectRefresh, ProjectDetailTab[]>> = {
  contributions: ['contributions'],
  sales: ['sales', 'distribution'],
  saleSuggestions: ['sales'],
  distribution: ['distribution'],
  settlement: ['distribution'],
  activity: ['journal'],
}

export function refreshesVisibleOn(actions: string[], openSection: ProjectDetailTab | null): GroupProjectRefresh[] {
  const wanted = new Set<GroupProjectRefresh>()
  for (const action of actions) {
    for (const refresh of groupProjectRefreshes(action)) wanted.add(refresh)
    if (refreshesActivityOn(action)) wanted.add('activity')
  }
  return [...wanted].filter((refresh) => {
    const tabsShowingIt = TABS_SHOWING_REFRESH[refresh]
    return tabsShowingIt === undefined || (openSection !== null && tabsShowingIt.includes(openSection))
  })
}

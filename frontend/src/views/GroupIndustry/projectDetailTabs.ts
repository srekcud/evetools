import type { ProjectNextActionTab } from '@/stores/group-industry/types'

export type ProjectDetailTab = 'bom' | 'contributions' | 'distribution' | 'sales' | 'members' | 'chain' | 'planning'

export type ProjectComingSoonTab = 'invention' | 'journal'

export const DETAIL_TABS: ProjectDetailTab[] = ['bom', 'contributions', 'distribution', 'sales', 'members', 'chain', 'planning']

export const COMING_SOON_TABS: ProjectComingSoonTab[] = ['invention', 'journal']

const TAB_OF_NEXT_ACTION: Record<ProjectNextActionTab, ProjectDetailTab> = {
  materials: 'bom',
  contributions: 'contributions',
  sales: 'sales',
  finances: 'distribution',
  members: 'members',
}

export function detailTabOfNextAction(tab: ProjectNextActionTab): ProjectDetailTab {
  return TAB_OF_NEXT_ACTION[tab]
}

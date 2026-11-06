import type { ProjectNextActionTab } from '@/stores/group-industry/types'

export type ProjectDetailTab = 'bom' | 'contributions' | 'distribution' | 'sales' | 'members' | 'chain'

export type ProjectComingSoonTab = 'invention' | 'planning' | 'journal'

export const DETAIL_TABS: ProjectDetailTab[] = ['bom', 'contributions', 'distribution', 'sales', 'members', 'chain']

export const COMING_SOON_TABS: ProjectComingSoonTab[] = ['invention', 'planning', 'journal']

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

import type { ProjectNextActionTab } from '@/stores/group-industry/types'

export type ProjectDetailTab = 'bom' | 'contributions' | 'distribution' | 'sales' | 'members' | 'chain' | 'journal' | 'invention'

export type ProjectComingSoonTab = 'invention'

export const DETAIL_TABS: ProjectDetailTab[] = ['members', 'bom', 'contributions', 'invention', 'chain', 'sales', 'distribution', 'journal']

export const COMING_SOON_TABS: ProjectComingSoonTab[] = []

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

import type { ProjectNextActionTab, ProjectPhase, ProjectPhaseKey } from '@/stores/group-industry/types'

export type ProjectDetailTab = 'bom' | 'contributions' | 'distribution' | 'sales' | 'members' | 'chain' | 'planning' | 'journal' | 'invention'

export type ProjectComingSoonTab = 'invention'

export const DETAIL_TABS: ProjectDetailTab[] = ['members', 'bom', 'contributions', 'invention', 'chain', 'planning', 'sales', 'distribution', 'journal']

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

const TAB_OF_PHASE: Record<ProjectPhaseKey, ProjectDetailTab | null> = {
  prepare: null,
  supply: 'bom',
  produce: 'chain',
  sell: 'sales',
  close: 'distribution',
}

export function detailTabOfPhase(key: ProjectPhaseKey): ProjectDetailTab | null {
  return TAB_OF_PHASE[key]
}

export function openingTabOf(phases: ProjectPhase[]): ProjectDetailTab {
  const current = phases.find(phase => phase.state === 'current')
  if (current !== undefined) return detailTabOfPhase(current.key) ?? 'bom'
  return phases.length > 0 && phases.every(phase => phase.state === 'done') ? 'distribution' : 'bom'
}

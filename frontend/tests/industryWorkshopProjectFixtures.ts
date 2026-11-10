import type { GroupMemberRole, ProjectPhase, ProjectPhaseBlockedReasonCode, ProjectPhaseKey, ProjectPhaseState } from '@/stores/group-industry/types'
import type { WorkshopProject, WorkshopProjectMember } from '@/stores/industry/workshopProjects'

export function workshopProject(overrides: Partial<WorkshopProject> & Pick<WorkshopProject, 'projectId'>): WorkshopProject {
  return {
    members: [],
    corporationName: null,
    phases: [],
    profit: { amount: null, status: 'unknown' },
    engagedCapital: null,
    ...overrides,
  }
}

export function workshopMember(characterName: string, characterId: number | null, role: GroupMemberRole): WorkshopProjectMember {
  return { characterId, characterName, role }
}

export function phase(
  key: ProjectPhaseKey,
  state: ProjectPhaseState,
  done: number,
  total: number,
  blockedReason: { code: ProjectPhaseBlockedReasonCode; count: number } | null = null,
): ProjectPhase {
  return { key, state, counter: { done, total }, blockedReason }
}

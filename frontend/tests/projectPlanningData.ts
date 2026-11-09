import type { PlanningCharacter, PlanningJob, ProjectPlanning } from '@/stores/group-industry/types'

export const PLANNING_PROJECT_ID = 'project-ishtar-batch'
export const PLANNING_PATH = `/industry/projects/${PLANNING_PROJECT_ID}/planning`

export const RAM_LINE_ID = 'line-ram-starship-tech'
export const ISHTAR_LINE_ID = 'line-ishtar'
export const HECATE_LINE_ID = 'line-hecate'
export const HULL_LINE_ID = 'line-hull-rework'
export const NOCXIUM_LINE_ID = 'line-nocxium-reaction'

export const RAM_NAME = 'R.A.M.- Starship Tech'

export const ARIA_SOLBERG = 'Aria Solberg'
export const BRIN_SOLBERG = 'Brin Solberg'
export const CORIN_VALE = 'Corin Vale'

export function planningJob(overrides: Partial<PlanningJob> & Pick<PlanningJob, 'lineId' | 'typeName'>): PlanningJob {
  return {
    typeId: 0,
    activity: 'manufacturing',
    runs: 1,
    planned: null,
    real: null,
    wait: null,
    ...overrides,
  }
}

export function planningCharacter(overrides: Partial<PlanningCharacter> & Pick<PlanningCharacter, 'characterName'>): PlanningCharacter {
  return {
    skillsUnknown: false,
    slots: { manufacturing: 4, reaction: 2 },
    skills: { industry: 4, advancedIndustry: 3, reactions: 2 },
    endsAt: null,
    jobs: [],
    ...overrides,
  }
}

export function projectPlanning(overrides: Partial<ProjectPlanning> = {}): ProjectPlanning {
  return {
    id: PLANNING_PROJECT_ID,
    anchor: '2026-11-08T06:00:00+00:00',
    structureTimeBonusIncluded: false,
    plannedEnd: null,
    expectedEnd: null,
    delaySeconds: null,
    delayCause: null,
    lanesShown: true,
    characters: [],
    unscheduled: [],
    ...overrides,
  }
}

export const RAM_JOB = planningJob({
  lineId: RAM_LINE_ID,
  typeId: 11_478,
  typeName: RAM_NAME,
  runs: 3,
  planned: { start: '2026-11-08T06:00:00+00:00', end: '2026-11-08T08:00:00+00:00' },
  real: { start: '2026-11-08T07:00:00+00:00', end: '2026-11-08T12:00:00+00:00', status: 'done' },
})

export const ISHTAR_JOB = planningJob({
  lineId: ISHTAR_LINE_ID,
  typeId: 12_005,
  typeName: 'Ishtar',
  runs: 2,
  planned: { start: '2026-11-08T08:00:00+00:00', end: '2026-11-08T10:00:00+00:00' },
  real: { start: '2026-11-08T12:00:00+00:00', end: '2026-11-08T14:00:00+00:00', status: 'active' },
  wait: { kind: 'waiting_job', names: [RAM_NAME], lateSeconds: 14_400 },
})

export const HECATE_JOB = planningJob({
  lineId: HECATE_LINE_ID,
  typeId: 35_683,
  typeName: 'Hecate',
  runs: 5,
  planned: { start: '2026-11-08T06:00:00+00:00', end: '2026-11-08T09:30:00+00:00' },
})

export const NOCXIUM_REACTION_JOB = planningJob({
  lineId: NOCXIUM_LINE_ID,
  typeId: 38,
  typeName: 'Nocxium Reaction',
  activity: 'reaction',
  runs: 7,
  planned: { start: '2026-11-08T06:00:00+00:00', end: '2026-11-08T11:15:00+00:00' },
})

export const ARIA_WITH_THE_RAM_AND_THE_ISHTAR = planningCharacter({
  characterName: ARIA_SOLBERG,
  endsAt: '2026-11-08T14:00:00+00:00',
  jobs: [RAM_JOB, ISHTAR_JOB],
})

export const BRIN_WITH_THE_HECATE = planningCharacter({
  characterName: BRIN_SOLBERG,
  slots: { manufacturing: 2, reaction: 6 },
  skills: { industry: 5, advancedIndustry: 1, reactions: 4 },
  endsAt: '2026-11-08T11:15:00+00:00',
  jobs: [HECATE_JOB, NOCXIUM_REACTION_JOB],
})

export const CORIN_WITHOUT_SKILLS = planningCharacter({
  characterName: CORIN_VALE,
  skillsUnknown: true,
  slots: null,
  skills: null,
  endsAt: null,
  jobs: [],
})

export const LATE_PLANNING = projectPlanning({
  plannedEnd: '2026-11-08T10:00:00+00:00',
  expectedEnd: '2026-11-08T14:00:00+00:00',
  delaySeconds: 14_400,
  delayCause: { kind: 'waiting_job', names: [RAM_NAME], lateSeconds: 14_400 },
  characters: [ARIA_WITH_THE_RAM_AND_THE_ISHTAR, BRIN_WITH_THE_HECATE],
})

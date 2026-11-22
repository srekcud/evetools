import type { BomItem, JobMatch, PlanningJob, ProjectPlanning } from '@/stores/group-industry/types'
import { HECATE_LINE_ID, ISHTAR_LINE_ID, NOCXIUM_LINE_ID, RAM_LINE_ID, planningJob, projectPlanning } from '../../projectPlanningData'

export const PLANNING_NOW_ISO = '2026-11-08T10:00:00+00:00'
export const PLANNING_NOW = Date.parse(PLANNING_NOW_ISO)

const HOUR_MS = 3_600_000

export function hoursFromNow(hours: number): string {
  return new Date(PLANNING_NOW + hours * HOUR_MS).toISOString()
}

export function hoursFromNowMs(hours: number): number {
  return PLANNING_NOW + hours * HOUR_MS
}

export function span(startHours: number, endHours: number): { start: string; end: string } {
  return { start: hoursFromNow(startHours), end: hoursFromNow(endHours) }
}

export function planningOfJobs(jobs: PlanningJob[], overrides: Partial<ProjectPlanning> = {}): ProjectPlanning {
  return projectPlanning({ lanesShown: false, jobs, ...overrides })
}

export function jobMatchOf(fields: Pick<JobMatch, 'matchedAt' | 'facilityId' | 'facilityName'>): JobMatch {
  return {
    id: `match-${fields.matchedAt}`,
    bomLineId: 'line',
    esiJobId: 1,
    cost: null,
    status: 'active',
    endDate: null,
    runs: 1,
    characterName: null,
    manual: false,
    ...fields,
  }
}

export function bomLineOf(id: string, jobMatches: JobMatch[]): BomItem {
  return { id, jobMatches } as BomItem
}

export const ISHTAR_PLANNED_JOB = planningJob({
  lineId: ISHTAR_LINE_ID,
  typeId: 12_005,
  typeName: 'Ishtar',
  runs: 2,
  planned: span(2, 5),
})

export const HECATE_PLANNED_JOB = planningJob({
  lineId: HECATE_LINE_ID,
  typeId: 35_683,
  typeName: 'Hecate',
  runs: 5,
  planned: span(1, 4),
})

export const RAM_PLANNED_JOB = planningJob({
  lineId: RAM_LINE_ID,
  typeId: 11_478,
  typeName: 'R.A.M.- Starship Tech',
  runs: 3,
  planned: span(1, 3),
})

export const NOCXIUM_PLANNED_JOB = planningJob({
  lineId: NOCXIUM_LINE_ID,
  typeId: 38,
  typeName: 'Nocxium Reaction',
  activity: 'reaction',
  runs: 7,
  planned: span(6, 9),
})

import type { AvailableJob, BomItem } from '@/stores/group-industry/types'

const LINKABLE_ACTIVITIES = ['manufacturing', 'reaction']

const LINK_REFUSALS: { pattern: RegExp; key: string; runsGroup?: number }[] = [
  { pattern: /^Job activity does not match BOM line activity$/, key: 'activityMismatch' },
  { pattern: /^Job blueprint does not match BOM line blueprint$/, key: 'blueprintMismatch' },
  { pattern: /^ESI job \d+ has no output$/, key: 'noOutput' },
  { pattern: /^ESI job \d+ is already linked to a BOM line$/, key: 'alreadyLinked' },
  { pattern: /^ESI job \d+ already has a share on this BOM line$/, key: 'alreadyShared' },
  { pattern: /^ESI job \d+ has only (\d+) runs left to share$/, key: 'notEnoughRuns', runsGroup: 1 },
  { pattern: /^ESI job \d+ not found$/, key: 'jobNotFound' },
]

export type LinkRefusal = { key: string; runs?: number }

export function isLinkableActivity(line: BomItem): boolean {
  return line.isJob && line.activityType !== null && LINKABLE_ACTIVITIES.includes(line.activityType)
}

export function jobsLinkableToLine(jobs: AvailableJob[], lineId: string | null): AvailableJob[] {
  if (lineId === null) return []
  return jobs.filter(job => job.linkableBomLineIds.includes(lineId))
}

export function isTakenByAnotherProject(job: AvailableJob): boolean {
  return job.linkedToAnotherProject === true && job.remainingRuns === 0 && job.linkedToBomLineId === null
}

export function linkRefusalOf(serverMessage: string): LinkRefusal | null {
  for (const refusal of LINK_REFUSALS) {
    const found = refusal.pattern.exec(serverMessage)
    if (found !== null) {
      return refusal.runsGroup === undefined ? { key: refusal.key } : { key: refusal.key, runs: Number(found[refusal.runsGroup]) }
    }
  }
  return null
}

export type JobDisplayState = 'running' | 'paused' | 'ready' | 'delivered' | 'cancelled' | 'reverted' | 'unknown'

export type JobDisplay = { state: JobDisplayState; remainingMs: number | null }

export type JobDisplayInput = { status: string; endDate: string | null }

const NO_REMAINING_TIME = 0
const FINISHED_STATES: JobDisplayState[] = ['delivered', 'cancelled', 'reverted']

function endTimeOf(endDate: string | null): number | null {
  if (endDate === null) return null
  const endTime = Date.parse(endDate)
  return Number.isNaN(endTime) ? null : endTime
}

export function jobDisplayOf(job: JobDisplayInput, now: number): JobDisplay {
  const endTime = endTimeOf(job.endDate)
  if (job.status === 'ready') return { state: 'ready', remainingMs: NO_REMAINING_TIME }
  if (job.status === 'active') {
    if (endTime !== null && endTime <= now) return { state: 'ready', remainingMs: NO_REMAINING_TIME }
    return { state: 'running', remainingMs: endTime === null ? null : endTime - now }
  }
  if (job.status === 'paused') {
    return { state: 'paused', remainingMs: endTime === null ? null : Math.max(NO_REMAINING_TIME, endTime - now) }
  }
  const finished = FINISHED_STATES.find(state => state === job.status)
  return { state: finished ?? 'unknown', remainingMs: null }
}

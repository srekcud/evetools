export type JobDisplayState = 'running' | 'paused' | 'ready' | 'delivered' | 'cancelled' | 'reverted' | 'unknown'

export type JobDisplay = { state: JobDisplayState; remainingMs: number | null }

export type JobDisplayInput = { status: string; startDate?: string | null; endDate: string | null }

const FULL_SHARE = 1
const NO_SHARE = 0
const RUNNING_STATES: JobDisplayState[] = ['running', 'paused']
const FINISHED_SHARE_STATES: JobDisplayState[] = ['ready', 'delivered']

function timeOf(date: string | null | undefined): number | null {
  if (date === null || date === undefined) return null
  const time = Date.parse(date)
  return Number.isNaN(time) ? null : time
}

export function jobElapsedShareOf(job: JobDisplayInput, now: number): number | null {
  const state = jobDisplayOf(job, now).state
  if (FINISHED_SHARE_STATES.includes(state)) return FULL_SHARE
  if (!RUNNING_STATES.includes(state)) return null
  const start = timeOf(job.startDate)
  const end = timeOf(job.endDate)
  if (start === null || end === null || end <= start) return null
  return Math.min(FULL_SHARE, Math.max(NO_SHARE, (now - start) / (end - start)))
}

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

import type { BomItem, JobMatch } from '@/stores/group-industry/types'
import { jobDisplayOf, jobElapsedShareOf, type JobDisplayState } from './jobDisplayState'

export type InventionDotState = 'empty' | 'busy' | 'win' | 'lose' | 'unknown'
export type InventionStageState = 'wait' | 'run' | 'done'
export type InventionCaption = 'idle' | 'running' | 'result' | 'unknownResult'

export type InventionManufacturing = {
  typeId: number
  typeName: string
  quantity: number
  state: InventionStageState
}

export type InventionStep = {
  productTypeId: number
  productName: string
  attempts: number | null
  expectedCopies: number | null
  chancePercent: number | null
  dots: InventionDotState[]
  caption: InventionCaption
  successes: number | null
  failures: number | null
  obtainedCopies: number | null
  remainingMs: number | null
  inputsState: InventionStageState
  inventionState: InventionStageState
  manufacturing: InventionManufacturing | null
}

const IGNORED_STATES: JobDisplayState[] = ['cancelled', 'reverted']
const RUNNING_STATES: JobDisplayState[] = ['running', 'paused']
const PERCENT_BASE = 100
const TENTHS = 10

type JobView = { runs: number; display: ReturnType<typeof jobDisplayOf>; share: number | null; successfulRuns: number | null }

function isInventionLine(item: BomItem): boolean {
  return item.isJob && item.activityType === 'invention'
}

function viewOf(match: JobMatch, now: number): JobView {
  return { runs: match.runs, display: jobDisplayOf(match, now), share: jobElapsedShareOf(match, now), successfulRuns: match.successfulRuns ?? null }
}

function dotsOfJob(job: JobView): InventionDotState[] {
  if (job.display.state === 'delivered') {
    if (job.successfulRuns === null) return Array<InventionDotState>(job.runs).fill('unknown')
    const wins = Math.min(job.successfulRuns, job.runs)
    return [...Array<InventionDotState>(wins).fill('win'), ...Array<InventionDotState>(job.runs - wins).fill('lose')]
  }
  if (job.display.state === 'ready') return Array<InventionDotState>(job.runs).fill('busy')
  if (RUNNING_STATES.includes(job.display.state)) {
    if (job.share === null) return Array<InventionDotState>(job.runs).fill('unknown')
    const elapsed = Math.floor(job.share * job.runs)
    return [...Array<InventionDotState>(elapsed).fill('busy'), ...Array<InventionDotState>(job.runs - elapsed).fill('empty')]
  }
  return Array<InventionDotState>(job.runs).fill('unknown')
}

function chancePercentOf(expectedCopies: number | null, attempts: number | null): number | null {
  if (expectedCopies === null || attempts === null || attempts === 0) return null
  return Math.round(expectedCopies / attempts * PERCENT_BASE * TENTHS) / TENTHS
}

function captionOf(jobs: JobView[]): InventionCaption {
  if (jobs.some(job => job.display.state !== 'delivered')) return 'running'
  if (jobs.length > 0 && jobs.every(job => job.successfulRuns !== null)) return 'result'
  return jobs.length > 0 ? 'unknownResult' : 'idle'
}

function totalSuccessesOf(jobs: JobView[]): number | null {
  let total = 0
  for (const job of jobs) {
    if (job.successfulRuns === null) return null
    total += job.successfulRuns
  }
  return total
}

function manufacturingOf(bomItems: BomItem[], productTypeId: number, now: number): InventionManufacturing | null {
  const line = bomItems.find(item => item.isJob && item.activityType === 'manufacturing' && item.typeId === productTypeId)
  if (line === undefined) return null
  const states = line.jobMatches.map(match => jobDisplayOf(match, now).state).filter(state => !IGNORED_STATES.includes(state))
  const covered = line.runs !== null && line.matchedRuns >= line.runs
  const allDelivered = states.length > 0 && states.every(state => state === 'delivered')
  const state: InventionStageState = allDelivered && covered ? 'done' : states.some(candidate => candidate !== 'delivered') ? 'run' : 'wait'
  return { typeId: line.typeId, typeName: line.typeName, quantity: line.requiredQuantity, state }
}

function stepOf(line: BomItem, bomItems: BomItem[], now: number): InventionStep {
  const attempts = line.runs
  const expectedCopies = line.expectedBlueprintCopies ?? null
  const jobs = line.jobMatches.map(match => viewOf(match, now)).filter(job => !IGNORED_STATES.includes(job.display.state))
  const jobDots = jobs.flatMap(dotsOfJob)
  const emptyDots = Array<InventionDotState>(Math.max(0, (attempts ?? 0) - jobDots.length)).fill('empty')
  const caption = captionOf(jobs)
  const matchedRuns = jobs.reduce((total, job) => total + job.runs, 0)
  const covered = attempts !== null && matchedRuns >= attempts
  const finished = jobs.length > 0 && jobs.every(job => job.display.state === 'delivered')
  const successes = caption === 'result' ? totalSuccessesOf(jobs) : null
  const remainingTimes = jobs.flatMap(job => (job.display.state === 'running' && job.display.remainingMs !== null ? [job.display.remainingMs] : []))
  const complete = finished && covered
  return {
    productTypeId: line.typeId,
    productName: line.typeName,
    attempts,
    expectedCopies,
    chancePercent: chancePercentOf(expectedCopies, attempts),
    dots: [...jobDots, ...emptyDots],
    caption,
    successes,
    failures: successes === null ? null : matchedRuns - successes,
    obtainedCopies: complete ? successes : null,
    remainingMs: remainingTimes.length === 0 ? null : Math.max(...remainingTimes),
    inputsState: jobs.length > 0 ? 'done' : 'wait',
    inventionState: complete ? 'done' : jobs.length > 0 ? 'run' : 'wait',
    manufacturing: manufacturingOf(bomItems, line.typeId, now),
  }
}

export function buildInventionSteps(bomItems: BomItem[], now: number): InventionStep[] {
  return bomItems.filter(isInventionLine).map(line => stepOf(line, bomItems, now))
}

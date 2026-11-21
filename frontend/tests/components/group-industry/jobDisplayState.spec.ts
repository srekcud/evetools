import { jobDisplayOf } from '@/components/group-industry/jobDisplayState'

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_SECOND = 1_000
const TEN_MINUTES = 600_000
const FIVE_MINUTES = 300_000
const ONE_HOUR_TWO_MINUTES_FIVE_SECONDS = 3_725_000

function endingAt(offsetMs: number): string {
  return new Date(NOW + offsetMs).toISOString()
}

describe('job display state', () => {
  it('derives the display state and the remaining time of a job from its ESI status and end date', () => {
    expect({
      activeEndingInTheFuture: jobDisplayOf({ status: 'active', endDate: endingAt(ONE_HOUR_TWO_MINUTES_FIVE_SECONDS) }, NOW),
      activeEndedOneSecondAgo: jobDisplayOf({ status: 'active', endDate: endingAt(-ONE_SECOND) }, NOW),
      activeEndingExactlyNow: jobDisplayOf({ status: 'active', endDate: endingAt(0) }, NOW),
      activeWithoutEndDate: jobDisplayOf({ status: 'active', endDate: null }, NOW),
      readyEndingInTheFuture: jobDisplayOf({ status: 'ready', endDate: endingAt(TEN_MINUTES) }, NOW),
      readyWithoutEndDate: jobDisplayOf({ status: 'ready', endDate: null }, NOW),
      pausedEndingInTheFuture: jobDisplayOf({ status: 'paused', endDate: endingAt(TEN_MINUTES) }, NOW),
      pausedEndedFiveMinutesAgo: jobDisplayOf({ status: 'paused', endDate: endingAt(-FIVE_MINUTES) }, NOW),
      pausedWithoutEndDate: jobDisplayOf({ status: 'paused', endDate: null }, NOW),
      delivered: jobDisplayOf({ status: 'delivered', endDate: endingAt(-FIVE_MINUTES) }, NOW),
      cancelled: jobDisplayOf({ status: 'cancelled', endDate: endingAt(-FIVE_MINUTES) }, NOW),
      reverted: jobDisplayOf({ status: 'reverted', endDate: endingAt(-FIVE_MINUTES) }, NOW),
      unrecognised: jobDisplayOf({ status: 'foo', endDate: null }, NOW),
    }).toEqual({
      activeEndingInTheFuture: { state: 'running', remainingMs: 3_725_000 },
      activeEndedOneSecondAgo: { state: 'ready', remainingMs: 0 },
      activeEndingExactlyNow: { state: 'ready', remainingMs: 0 },
      activeWithoutEndDate: { state: 'running', remainingMs: null },
      readyEndingInTheFuture: { state: 'ready', remainingMs: 0 },
      readyWithoutEndDate: { state: 'ready', remainingMs: 0 },
      pausedEndingInTheFuture: { state: 'paused', remainingMs: 600_000 },
      pausedEndedFiveMinutesAgo: { state: 'paused', remainingMs: 0 },
      pausedWithoutEndDate: { state: 'paused', remainingMs: null },
      delivered: { state: 'delivered', remainingMs: null },
      cancelled: { state: 'cancelled', remainingMs: null },
      reverted: { state: 'reverted', remainingMs: null },
      unrecognised: { state: 'unknown', remainingMs: null },
    })
  })

  it('treats an unreadable end date as an unknown end, never as a ready job', () => {
    expect(jobDisplayOf({ status: 'active', endDate: 'not-a-date' }, NOW)).toEqual({ state: 'running', remainingMs: null })
  })
})

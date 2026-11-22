import { jobDisplayOf, jobElapsedShareOf } from '@/components/group-industry/jobDisplayState'

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_SECOND = 1_000
const TEN_MINUTES = 600_000
const FIVE_MINUTES = 300_000
const ONE_HOUR_TWO_MINUTES_FIVE_SECONDS = 3_725_000
const TWO_HOURS = 7_200_000
const FOUR_HOURS = 14_400_000

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

describe('job elapsed share', () => {
  const startedTwoHoursAgo = new Date(NOW - TWO_HOURS).toISOString()
  const endingInTwoHours = new Date(NOW + TWO_HOURS).toISOString()

  it('is the elapsed fraction between the start date and the end date of a running job', () => {
    expect(jobElapsedShareOf({ status: 'active', startDate: startedTwoHoursAgo, endDate: endingInTwoHours }, NOW)).toBe(0.5)
  })

  it('is 0 for a running job whose start date is still ahead', () => {
    const startingInOneHour = new Date(NOW + 3_600_000).toISOString()

    expect(jobElapsedShareOf({ status: 'active', startDate: startingInOneHour, endDate: new Date(NOW + FOUR_HOURS).toISOString() }, NOW)).toBe(0)
  })

  it('is 1 for an active job whose end date has passed, since it is ready', () => {
    const endedOneHourAgo = new Date(NOW - 3_600_000).toISOString()

    expect(jobElapsedShareOf({ status: 'active', startDate: new Date(NOW - FOUR_HOURS).toISOString(), endDate: endedOneHourAgo }, NOW)).toBe(1)
  })

  it('is unknown for a running job without a start date', () => {
    expect(jobElapsedShareOf({ status: 'active', endDate: endingInTwoHours }, NOW)).toBeNull()
    expect(jobElapsedShareOf({ status: 'active', startDate: null, endDate: endingInTwoHours }, NOW)).toBeNull()
  })

  it('is unknown for a running job whose start date is unreadable', () => {
    expect(jobElapsedShareOf({ status: 'active', startDate: 'not-a-date', endDate: endingInTwoHours }, NOW)).toBeNull()
  })

  it('is unknown for a running job without an end date', () => {
    expect(jobElapsedShareOf({ status: 'active', startDate: startedTwoHoursAgo, endDate: null }, NOW)).toBeNull()
  })

  it('is unknown for a running job whose end date equals its start date', () => {
    expect(jobElapsedShareOf({ status: 'active', startDate: endingInTwoHours, endDate: endingInTwoHours }, NOW)).toBeNull()
  })

  it('is 1 for a delivered job whatever its start date', () => {
    expect(jobElapsedShareOf({ status: 'delivered', endDate: null }, NOW)).toBe(1)
    expect(jobElapsedShareOf({ status: 'delivered', startDate: startedTwoHoursAgo, endDate: endingInTwoHours }, NOW)).toBe(1)
  })

  it('is 1 for a ready job without any date', () => {
    expect(jobElapsedShareOf({ status: 'ready', startDate: null, endDate: null }, NOW)).toBe(1)
  })

  it('uses the running formula for a paused job', () => {
    expect(jobElapsedShareOf({ status: 'paused', startDate: startedTwoHoursAgo, endDate: endingInTwoHours }, NOW)).toBe(0.5)
  })

  it('stays at 1 for a paused job whose end date has passed', () => {
    const endedOneHourAgo = new Date(NOW - 3_600_000).toISOString()

    expect(jobElapsedShareOf({ status: 'paused', startDate: new Date(NOW - FOUR_HOURS).toISOString(), endDate: endedOneHourAgo }, NOW)).toBe(1)
  })

  it('is unknown when the end date is before the start date', () => {
    const startingInThreeHours = new Date(NOW + 3 * 3_600_000).toISOString()
    const endingInOneHour = new Date(NOW + 3_600_000).toISOString()

    expect(jobElapsedShareOf({ status: 'active', startDate: startingInThreeHours, endDate: endingInOneHour }, NOW)).toBeNull()
  })

  it.each(['cancelled', 'reverted', 'foo'])('is unknown for a %s job', (status) => {
    expect(jobElapsedShareOf({ status, startDate: startedTwoHoursAgo, endDate: endingInTwoHours }, NOW)).toBeNull()
  })
})

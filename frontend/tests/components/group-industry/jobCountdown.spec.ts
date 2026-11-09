import { formatCountdown } from '@/components/group-industry/jobCountdown'

const ENDS_AT = '2026-11-09T12:00:00+00:00'
const ENDS_AT_MS = Date.parse(ENDS_AT)

describe('formatCountdown', () => {
  it('shows hours, minutes and seconds left before the end of the jobs', () => {
    expect(formatCountdown(ENDS_AT, ENDS_AT_MS - 3_725_000)).toBe('01:02:05')
  })

  it('pads each unit to two digits and keeps counting past ten hours', () => {
    expect(formatCountdown(ENDS_AT, ENDS_AT_MS - 36_000_000 - 7_000)).toBe('10:00:07')
  })

  it('returns null, distinct from any time, once the end is reached or passed', () => {
    expect({
      atTheEnd: formatCountdown(ENDS_AT, ENDS_AT_MS),
      afterTheEnd: formatCountdown(ENDS_AT, ENDS_AT_MS + 90_000),
    }).toEqual({ atTheEnd: null, afterTheEnd: null })
  })
})

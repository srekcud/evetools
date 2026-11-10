import { jobLocationCheck, jobLocationUnknownReasons } from '@/components/group-industry/jobLocationCheck'
import type { JobLocation, JobLocations } from '@/stores/group-industry/types'

function side(status: JobLocation['status'], unknownReason: string | null = null): JobLocation {
  return { locationId: 1_049_000_000_001, kind: 'container', name: 'ISHTAR-319', divisionName: null, status, unknownReason }
}

function locations(blueprint: JobLocation, output: JobLocation): JobLocations {
  return { blueprint, output }
}

describe('jobLocationCheck', () => {
  it.each([
    ['both sides inside', side('inside'), side('inside'), 'ok'],
    ['blueprint outside and output inside', side('outside'), side('inside'), 'ok'],
    ['output outside and blueprint inside', side('inside'), side('outside'), 'output-outside'],
    ['both sides outside', side('outside'), side('outside'), 'output-outside'],
    ['output outside and blueprint unknown', side('unknown', 'no_director'), side('outside'), 'output-outside'],
    ['output unknown and blueprint inside', side('inside'), side('unknown', 'location_missing'), 'unknown'],
    ['output unknown and blueprint outside', side('outside'), side('unknown', 'location_missing'), 'unknown'],
    ['blueprint unknown and output inside', side('unknown', 'no_director'), side('inside'), 'unknown'],
  ])('%s', (_label, blueprint, output, expected) => {
    expect(jobLocationCheck(locations(blueprint, output))).toBe(expected)
  })
})

describe('jobLocationUnknownReasons', () => {
  it('lists each unknown reason once and ignores sides that are known', () => {
    const reasons = jobLocationUnknownReasons(locations(side('unknown', 'no_director'), side('unknown', 'no_director')))

    expect(reasons).toEqual(['no_director'])
    expect(jobLocationUnknownReasons(locations(side('outside'), side('inside')))).toEqual([])
  })
})

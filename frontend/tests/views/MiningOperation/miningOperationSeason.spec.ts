import { formatSeasonDuration } from '@/views/MiningOperation/miningOperationSeason'

describe('formatSeasonDuration', () => {
  it('writes minutes as hours and minutes and keeps the bare minutes under an hour', () => {
    expect([0, 45, 60, 270, 1505].map(formatSeasonDuration)).toEqual(['0 min', '45 min', '1 h 00 min', '4 h 30 min', '25 h 05 min'])
  })
})

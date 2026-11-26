import { shortfallOf } from '@/utils/planetaryPickup'

describe('shortfallOf', () => {
  it('is what is left to find once the home stock is used: 6020 left, 2000 at home, 4020 short', () => {
    expect(shortfallOf(6020, 2000)).toBe(4020)
  })

  it('is 0 when the home stock covers what is left, exactly or with a surplus', () => {
    expect(shortfallOf(8520, 8520)).toBe(0)
    expect(shortfallOf(8520, 9000)).toBe(0)
  })

  it('is the whole remainder with an empty home stock', () => {
    expect(shortfallOf(11520, 0)).toBe(11520)
  })

  it('is 0 when nothing is left to find, even if the home stock is unknown', () => {
    expect(shortfallOf(0, null)).toBe(0)
  })

  it('is unknown when something is left to find and the home stock is unknown', () => {
    expect(shortfallOf(6020, null)).toBeNull()
  })
})

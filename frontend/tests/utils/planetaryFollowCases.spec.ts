import { compareFactories, compareSales } from '@/utils/planetaryFollow'

const RING_PLAN = { P1: 18, P2: 12, P3: 3, P4: 2 }

describe('compareFactories without any built factory', () => {
  it('flags every tier of the ring plan as missing with its whole plan: 18, 12, 3 and 2', () => {
    expect(compareFactories(RING_PLAN, { P1: 0, P2: 0, P3: 0, P4: 0 })).toEqual([
      { tier: 'P1', planned: 18, real: 0, gap: -18, state: 'missing' },
      { tier: 'P2', planned: 12, real: 0, gap: -12, state: 'missing' },
      { tier: 'P3', planned: 3, real: 0, gap: -3, state: 'missing' },
      { tier: 'P4', planned: 2, real: 0, gap: -2, state: 'missing' },
    ])
  })

  it('keeps the planned factories and flags every tier as unknown, with no gap, while the colonies are not loaded', () => {
    expect(compareFactories({ P1: 0, P2: 10, P3: 0, P4: 0 }, null)).toEqual([
      { tier: 'P1', planned: 0, real: null, gap: null, state: 'unknown' },
      { tier: 'P2', planned: 10, real: null, gap: null, state: 'unknown' },
      { tier: 'P3', planned: 0, real: null, gap: null, state: 'unknown' },
      { tier: 'P4', planned: 0, real: null, gap: null, state: 'unknown' },
    ])
  })
})

describe('compareSales over a period of 0 days', () => {
  it('Currently divides by zero and returns an infinite real, gap and ratio for 900,000 realised over 0 days (Issue1406)', () => {
    const comparison = compareSales(120_000, { realisedIsk: 900_000, days: 0 })

    expect(comparison.planned).toBe(120_000)
    expect(comparison.real).toBe(Infinity)
    expect(comparison.gap).toBe(Infinity)
    expect(comparison.ratio).toBe(Infinity)
  })

  it('Currently returns NaN for the real, the gap and the ratio when nothing was realised over 0 days (Issue1406)', () => {
    const comparison = compareSales(120_000, { realisedIsk: 0, days: 0 })

    expect(comparison.planned).toBe(120_000)
    expect(Number.isNaN(comparison.real)).toBe(true)
    expect(Number.isNaN(comparison.gap)).toBe(true)
    expect(Number.isNaN(comparison.ratio)).toBe(true)
  })

  it('keeps the unrounded ratio of 40,000 a day against 120,000 planned: gap -80,000 and ratio one third', () => {
    const comparison = compareSales(120_000, { realisedIsk: 1_200_000, days: 30 })

    expect(comparison.real).toBe(40_000)
    expect(comparison.gap).toBe(-80_000)
    expect(comparison.ratio).toBeCloseTo(0.3333, 4)
  })
})

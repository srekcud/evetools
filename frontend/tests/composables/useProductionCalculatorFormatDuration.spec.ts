import { formatDuration } from '@/composables/useProductionCalculator'

describe('formatDuration', () => {
  it('formats a duration in days, hours and minutes and shows a dash when there is none', () => {
    expect(formatDuration(null)).toBe('-')
    expect(formatDuration(0)).toBe('-')
    expect(formatDuration(86_400 + 3_600)).toBe('1j 1h')
    expect(formatDuration(2 * 86_400)).toBe('2j')
    expect(formatDuration(3_600 + 120)).toBe('1h 2m')
    expect(formatDuration(300)).toBe('5m')
  })
})

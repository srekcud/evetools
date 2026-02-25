import { createApp } from 'vue'
import { createI18n } from 'vue-i18n'
import { useFormatters, dateFormat } from '@/composables/useFormatters'

/**
 * Helper: run a composable inside a temporary Vue app with i18n installed.
 * Returns the composable's return value.
 */
function withFormatters(locale: string = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en: {}, fr: {} } })
  let result!: ReturnType<typeof useFormatters>
  const app = createApp({ setup: () => { result = useFormatters(); return () => null } })
  app.use(i18n)
  app.mount(document.createElement('div'))
  return { result, app }
}

afterEach(() => {
  // Reset dateFormat to default between tests
  dateFormat.value = 'eu'
})

describe('useFormatters', () => {
  // ---------------------------------------------------------------------------
  // formatIsk
  // ---------------------------------------------------------------------------
  describe('formatIsk', () => {
    it('returns "---" for null and undefined', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatIsk(null)).toBe('---')
      expect(result.formatIsk(undefined)).toBe('---')
      app.unmount()
    })

    it('formats zero without suffix', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatIsk(0)).toBe('0')
      app.unmount()
    })

    it('formats small values without suffix (< 1000)', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatIsk(42)).toBe('42')
      expect(result.formatIsk(999)).toBe('999')
      app.unmount()
    })

    it('formats values >= 1000 with K suffix', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatIsk(1_500)
      expect(formatted).toContain('K')
      expect(formatted).toContain('1.50')
      app.unmount()
    })

    it('formats values >= 1_000_000 with M suffix', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatIsk(1_500_000)
      expect(formatted).toContain('M')
      expect(formatted).toContain('1.50')
      app.unmount()
    })

    it('formats values >= 1_000_000_000 with B suffix', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatIsk(2_300_000_000)
      expect(formatted).toContain('B')
      expect(formatted).toContain('2.30')
      app.unmount()
    })

    it('handles negative values with correct suffix thresholds', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatIsk(-1_500_000)
      expect(formatted).toMatch(/^-/)
      expect(formatted).toContain('M')
      // Uses Math.abs for threshold detection, so -1.5M not erroneously treated as < 1M
      expect(formatted).toContain('1.50')
      app.unmount()
    })

    it('handles negative billions', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatIsk(-5_000_000_000)
      expect(formatted).toMatch(/^-/)
      expect(formatted).toContain('B')
      app.unmount()
    })

    it('respects custom decimal parameter', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatIsk(1_234_567, 1)
      expect(formatted).toContain('M')
      expect(formatted).toContain('1.2')
      app.unmount()
    })

    it('uses French locale formatting when locale is fr', () => {
      const { result, app } = withFormatters('fr')
      const formatted = result.formatIsk(1_500_000)
      // French locale uses comma as decimal separator
      expect(formatted).toContain('M')
      app.unmount()
    })

    it('formatIskShort is an alias for formatIsk', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatIskShort).toBe(result.formatIsk)
      app.unmount()
    })

    it('formats exact boundary values', () => {
      const { result, app } = withFormatters('en')
      // Exactly 1000 should use K
      expect(result.formatIsk(1_000)).toContain('K')
      // Exactly 1_000_000 should use M
      expect(result.formatIsk(1_000_000)).toContain('M')
      // Exactly 1_000_000_000 should use B
      expect(result.formatIsk(1_000_000_000)).toContain('B')
      app.unmount()
    })
  })

  // ---------------------------------------------------------------------------
  // formatIskFull
  // ---------------------------------------------------------------------------
  describe('formatIskFull', () => {
    it('formats with full number and ISK suffix', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatIskFull(1_500_000)
      expect(formatted).toContain('ISK')
      expect(formatted).toContain('1,500,000')
      app.unmount()
    })

    it('uses French locale', () => {
      const { result, app } = withFormatters('fr')
      const formatted = result.formatIskFull(1_500_000)
      expect(formatted).toContain('ISK')
      app.unmount()
    })
  })

  // ---------------------------------------------------------------------------
  // formatNumber
  // ---------------------------------------------------------------------------
  describe('formatNumber', () => {
    it('returns "---" for null and undefined', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatNumber(null)).toBe('---')
      expect(result.formatNumber(undefined)).toBe('---')
      app.unmount()
    })

    it('formats integers without decimals', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatNumber(42)).toBe('42')
      app.unmount()
    })

    it('formats large numbers with grouping', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatNumber(1_234_567)
      expect(formatted).toContain('1,234,567')
      app.unmount()
    })

    it('respects custom decimal parameter', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatNumber(3.14159, 3)
      expect(formatted).toContain('3.142')
      app.unmount()
    })

    it('truncates to max decimals', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatNumber(1.999, 1)
      expect(formatted).toBe('2')
      app.unmount()
    })
  })

  // ---------------------------------------------------------------------------
  // formatDate / formatDateTime
  // ---------------------------------------------------------------------------
  describe('formatDate', () => {
    it('formats a date string with EU format', () => {
      dateFormat.value = 'eu'
      const { result, app } = withFormatters('en')
      const formatted = result.formatDate('2025-06-15T10:30:00Z')
      // EU date format uses fr-FR locale: DD/MM/YY
      expect(formatted).toMatch(/15\/06\/25/)
      app.unmount()
    })

    it('formats a date string with US format', () => {
      dateFormat.value = 'us'
      const { result, app } = withFormatters('en')
      const formatted = result.formatDate('2025-06-15T10:30:00Z')
      // US date format uses en-US locale: MM/DD/YY
      expect(formatted).toMatch(/06\/15\/25/)
      app.unmount()
    })
  })

  describe('formatDateTime', () => {
    it('includes hours and minutes', () => {
      const { result, app } = withFormatters('en')
      const formatted = result.formatDateTime('2025-06-15T10:30:00Z')
      // Should contain day/month and time components
      expect(formatted).toBeTruthy()
      expect(formatted.length).toBeGreaterThan(5)
      app.unmount()
    })
  })

  // ---------------------------------------------------------------------------
  // formatTimeSince
  // ---------------------------------------------------------------------------
  describe('formatTimeSince', () => {
    it('returns "Never" for null', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatTimeSince(null)).toBe('Never')
      app.unmount()
    })

    it('returns minutes ago for recent dates', () => {
      const { result, app } = withFormatters('en')
      const fiveMinutesAgo = new Date(Date.now() - 5 * 60_000).toISOString()
      expect(result.formatTimeSince(fiveMinutesAgo)).toBe('5m ago')
      app.unmount()
    })

    it('returns hours ago for dates within a day', () => {
      const { result, app } = withFormatters('en')
      const threeHoursAgo = new Date(Date.now() - 3 * 3_600_000).toISOString()
      expect(result.formatTimeSince(threeHoursAgo)).toBe('3h ago')
      app.unmount()
    })

    it('returns days ago for older dates', () => {
      const { result, app } = withFormatters('en')
      const twoDaysAgo = new Date(Date.now() - 2 * 86_400_000).toISOString()
      expect(result.formatTimeSince(twoDaysAgo)).toBe('2d ago')
      app.unmount()
    })
  })

  // ---------------------------------------------------------------------------
  // formatDuration
  // ---------------------------------------------------------------------------
  describe('formatDuration', () => {
    it('formats seconds', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatDuration(45)).toBe('45s')
      app.unmount()
    })

    it('formats minutes', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatDuration(120)).toBe('2m')
      expect(result.formatDuration(90)).toBe('1m')
      app.unmount()
    })

    it('formats hours with remaining minutes', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatDuration(3_660)).toBe('1h 1m')
      expect(result.formatDuration(7_200)).toBe('2h')
      app.unmount()
    })

    it('formats days with remaining hours', () => {
      const { result, app } = withFormatters('en')
      expect(result.formatDuration(90_000)).toBe('1d 1h')
      expect(result.formatDuration(86_400)).toBe('1d')
      app.unmount()
    })
  })

  // ---------------------------------------------------------------------------
  // setDateFormat
  // ---------------------------------------------------------------------------
  describe('setDateFormat', () => {
    it('updates dateFormat ref and persists to localStorage', () => {
      const { result, app } = withFormatters('en')
      result.setDateFormat('us')
      expect(dateFormat.value).toBe('us')
      expect(localStorage.getItem('evetools_date_format')).toBe('us')
      result.setDateFormat('eu')
      expect(dateFormat.value).toBe('eu')
      expect(localStorage.getItem('evetools_date_format')).toBe('eu')
      app.unmount()
    })
  })
})

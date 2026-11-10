import { defineComponent, ref, type Ref } from 'vue'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import { formatExpiryThreshold, usePlanetaryTimers } from '@/composables/usePlanetaryTimers'

enableAutoUnmount(afterEach)

const NOW = new Date('2026-11-10T12:00:00Z')
const MS_PER_MINUTE = 60_000

function timersWithThreshold(thresholdMinutes: Ref<number>): ReturnType<typeof usePlanetaryTimers> {
  let timers: ReturnType<typeof usePlanetaryTimers> | null = null
  mount(
    defineComponent({
      setup() {
        timers = usePlanetaryTimers(thresholdMinutes)
        return () => null
      },
    }),
    { global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] } },
  )
  if (timers === null) throw new Error('usePlanetaryTimers was not set up')
  return timers
}

function expiryInMinutes(minutes: number): string {
  return new Date(NOW.getTime() + minutes * MS_PER_MINUTE).toISOString()
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('usePlanetaryTimers expiry threshold', () => {
  it('flags an extractor expiring within the threshold of the user', () => {
    const { getTimerInfo } = timersWithThreshold(ref(360))

    expect(getTimerInfo(expiryInMinutes(300)).status).toBe('expiring')
  })

  it('keeps an extractor beyond the threshold of the user active even when it expires within a day', () => {
    const { getTimerInfo } = timersWithThreshold(ref(360))

    expect(getTimerInfo(expiryInMinutes(420)).status).toBe('active')
  })

  it('flags at 23 hours and not at 25 hours with the default threshold of one day', () => {
    const { getTimerInfo } = timersWithThreshold(ref(1440))

    expect([getTimerInfo(expiryInMinutes(23 * 60)).status, getTimerInfo(expiryInMinutes(25 * 60)).status]).toEqual(['expiring', 'active'])
  })

  it('follows the threshold when the user changes it', () => {
    const thresholdMinutes = ref(360)
    const { getTimerInfo } = timersWithThreshold(thresholdMinutes)
    const expiry = expiryInMinutes(300)

    const statusBefore = getTimerInfo(expiry).status
    thresholdMinutes.value = 60

    expect([statusBefore, getTimerInfo(expiry).status]).toEqual(['expiring', 'active'])
  })
})

describe('formatExpiryThreshold', () => {
  it('writes minutes below one hour and hours with zero padded minutes from one hour', () => {
    expect([15, 59, 60, 61, 90, 360, 1440, 10080].map(formatExpiryThreshold)).toEqual([
      '15 min',
      '59 min',
      '1h',
      '1h01',
      '1h30',
      '6h',
      '24h',
      '168h',
    ])
  })
})

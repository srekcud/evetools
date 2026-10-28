import { createApp, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import DataFreshness from '@/components/common/DataFreshness.vue'
import { dateFormat, useFormatters } from '@/composables/useFormatters'
import type { DataFreshnessLevel, DataFreshnessSource, DataFreshnessState } from '@/utils/dataFreshness'

const NOW = '2026-10-26T12:00:00Z'
const JITA_PRICES_INTERVAL = 3_600
const COST_INDICES_INTERVAL = 7_200
const ADJUSTED_PRICES_INTERVAL = 86_400
const ONE_MINUTE = 60_000

function createTestI18n(locale: 'en' | 'fr') {
  return createI18n({ legacy: false, locale, messages: { en, fr } })
}

function mountFreshness(source: DataFreshnessSource, freshness: DataFreshnessState | null, locale: 'en' | 'fr' = 'en') {
  const i18n = createTestI18n(locale)
  const wrapper = mount(DataFreshness, { props: { source, freshness }, global: { plugins: [i18n] } })
  return { wrapper, i18n }
}

function freshnessBadge(wrapper: ReturnType<typeof mount>) {
  return wrapper.get('[data-testid="data-freshness"]')
}

function formatDateTimeLikeTheApp(dateStr: string): string {
  let formatted = ''
  const app = createApp({ setup: () => { formatted = useFormatters().formatDateTime(dateStr); return () => null } })
  app.use(createTestI18n('en'))
  app.mount(document.createElement('div'))
  app.unmount()
  return formatted
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date(NOW))
})

afterEach(() => {
  vi.useRealTimers()
  dateFormat.value = 'eu'
})

describe('DataFreshness', () => {
  it.each([
    ['E1', 'market-jita', '2026-10-26T11:59:30Z', JITA_PRICES_INTERVAL, false, 'Jita prices · just now', "Prix Jita · a l'instant", 'fresh'],
    ['E2', 'market-jita', '2026-10-26T11:18:00Z', JITA_PRICES_INTERVAL, false, 'Jita prices · 42min ago', 'Prix Jita · il y a 42min', 'fresh'],
    ['E3', 'market-jita', '2026-10-26T10:30:00Z', JITA_PRICES_INTERVAL, false, 'Jita prices · 1h ago', 'Prix Jita · il y a 1h', 'fresh'],
    ['E4', 'market-jita', '2026-10-26T10:29:00Z', JITA_PRICES_INTERVAL, false, 'Jita prices · 1h ago', 'Prix Jita · il y a 1h', 'aging'],
    ['E5', 'market-jita', '2026-10-26T09:00:00Z', JITA_PRICES_INTERVAL, false, 'Jita prices · 3h ago', 'Prix Jita · il y a 3h', 'aging'],
    ['E6', 'market-jita', '2026-10-26T08:59:00Z', JITA_PRICES_INTERVAL, false, 'Jita prices · 3h ago', 'Prix Jita · il y a 3h', 'stale'],
    ['E7', 'cost-indices', '2026-10-26T09:00:00Z', COST_INDICES_INTERVAL, false, 'Cost indices · 3h ago', 'Cost index · il y a 3h', 'fresh'],
    ['E8', 'cost-indices', '2026-10-26T07:00:00Z', COST_INDICES_INTERVAL, false, 'Cost indices · 5h ago', 'Cost index · il y a 5h', 'aging'],
    ['E9', 'cost-indices', '2026-10-26T05:30:00Z', COST_INDICES_INTERVAL, false, 'Cost indices · 6h ago', 'Cost index · il y a 6h', 'stale'],
    ['E10', 'adjusted-prices', '2026-10-25T00:00:00Z', ADJUSTED_PRICES_INTERVAL, false, 'Adjusted prices · 1d ago', 'Prix ajustés · il y a 1j', 'fresh'],
    ['E11', 'adjusted-prices', '2026-10-24T00:00:00Z', ADJUSTED_PRICES_INTERVAL, false, 'Adjusted prices · 2d ago', 'Prix ajustés · il y a 2j', 'aging'],
    ['E12', 'adjusted-prices', '2026-10-22T12:00:00Z', ADJUSTED_PRICES_INTERVAL, false, 'Adjusted prices · 4d ago', 'Prix ajustés · il y a 4j', 'stale'],
    ['E13', 'market-jita', '2026-10-26T11:18:00Z', JITA_PRICES_INTERVAL, true, 'Jita prices · 42min ago · last sync failed', 'Prix Jita · il y a 42min · dernière sync en échec', 'aging'],
    ['E14', 'market-jita', '2026-10-26T08:00:00Z', JITA_PRICES_INTERVAL, true, 'Jita prices · 4h ago · last sync failed', 'Prix Jita · il y a 4h · dernière sync en échec', 'stale'],
    ['E17', 'market-jita', '2026-10-26T12:02:00Z', JITA_PRICES_INTERVAL, false, 'Jita prices · just now', "Prix Jita · a l'instant", 'fresh'],
  ] as const)('%s: shows the %s age of the last successful sync at %s', (_example, source, lastSuccessAt, expectedInterval, lastRunFailed, englishLabel, frenchLabel, level: DataFreshnessLevel) => {
    const freshness = { lastSuccessAt, expectedInterval, lastRunFailed }

    const english = freshnessBadge(mountFreshness(source, freshness, 'en').wrapper)
    const french = freshnessBadge(mountFreshness(source, freshness, 'fr').wrapper)

    expect(english.text()).toBe(englishLabel)
    expect(french.text()).toBe(frenchLabel)
    expect(english.attributes('data-level')).toBe(level)
    expect(french.attributes('data-level')).toBe(level)
  })

  it.each([
    ['en', 'Jita prices · unknown date'],
    ['fr', 'Prix Jita · date inconnue'],
  ] as const)('E15: shows an unknown date, never an age, without a last successful sync (%s)', (locale, label) => {
    const { wrapper } = mountFreshness('market-jita', {
      lastSuccessAt: null,
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed: false,
    }, locale)

    expect(freshnessBadge(wrapper).text()).toBe(label)
    expect(freshnessBadge(wrapper).attributes('data-level')).toBe('unknown')
    expect(freshnessBadge(wrapper).attributes('title')).toBeUndefined()
  })

  it.each([
    ['market-jita', 'en', 'Jita prices · unknown date'],
    ['cost-indices', 'en', 'Cost indices · unknown date'],
    ['adjusted-prices', 'fr', 'Prix ajustés · date inconnue'],
  ] as const)('E16: shows an unknown date for %s when the sync state is unreadable (%s)', (source, locale, label) => {
    const { wrapper } = mountFreshness(source, null, locale)

    expect(freshnessBadge(wrapper).text()).toBe(label)
    expect(freshnessBadge(wrapper).attributes('data-level')).toBe('unknown')
    expect(freshnessBadge(wrapper).attributes('title')).toBeUndefined()
  })

  it.each([
    ['eu'],
    ['us'],
  ] as const)('shows the absolute date of the last successful sync in the tooltip, %s date format', (format) => {
    dateFormat.value = format
    const lastSuccessAt = '2026-10-26T07:00:00Z'

    const { wrapper } = mountFreshness('cost-indices', {
      lastSuccessAt,
      expectedInterval: COST_INDICES_INTERVAL,
      lastRunFailed: false,
    })

    expect(freshnessBadge(wrapper).attributes('title')).toBe(formatDateTimeLikeTheApp(lastSuccessAt))
  })

  it.each([
    ['en', 'Jita prices · 29min ago', 'Jita prices · 30min ago'],
    ['fr', 'Prix Jita · il y a 29min', 'Prix Jita · il y a 30min'],
  ] as const)('E19: ages the label every minute without reloading the data (%s)', async (locale, before, after) => {
    const { wrapper } = mountFreshness('market-jita', {
      lastSuccessAt: '2026-10-26T11:31:00Z',
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed: false,
    }, locale)
    expect(freshnessBadge(wrapper).text()).toBe(before)

    vi.advanceTimersByTime(ONE_MINUTE)
    await nextTick()

    expect(freshnessBadge(wrapper).text()).toBe(after)
  })

  it('moves from fresh to aging as minutes pass without reloading the data', async () => {
    const { wrapper } = mountFreshness('market-jita', {
      lastSuccessAt: '2026-10-26T10:31:00Z',
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed: false,
    })
    expect(freshnessBadge(wrapper).attributes('data-level')).toBe('fresh')

    vi.advanceTimersByTime(2 * ONE_MINUTE)
    await nextTick()

    expect(freshnessBadge(wrapper).attributes('data-level')).toBe('aging')
    expect(freshnessBadge(wrapper).text()).toBe('Jita prices · 1h ago')
  })

  it('stops ageing the label once unmounted', () => {
    const { wrapper } = mountFreshness('market-jita', {
      lastSuccessAt: '2026-10-26T11:31:00Z',
      expectedInterval: JITA_PRICES_INTERVAL,
      lastRunFailed: false,
    })
    expect(vi.getTimerCount()).toBe(1)

    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)
  })

  it('retranslates the label when the locale changes', async () => {
    const { wrapper, i18n } = mountFreshness('adjusted-prices', {
      lastSuccessAt: '2026-10-24T00:00:00Z',
      expectedInterval: ADJUSTED_PRICES_INTERVAL,
      lastRunFailed: true,
    })
    expect(freshnessBadge(wrapper).text()).toBe('Adjusted prices · 2d ago · last sync failed')

    i18n.global.locale.value = 'fr'
    await nextTick()

    expect(freshnessBadge(wrapper).text()).toBe('Prix ajustés · il y a 2j · dernière sync en échec')
  })

  it('shows the age once the sync state arrives after an unknown state', async () => {
    const { wrapper } = mountFreshness('cost-indices', null)
    expect(freshnessBadge(wrapper).attributes('data-level')).toBe('unknown')

    await wrapper.setProps({
      freshness: { lastSuccessAt: '2026-10-26T07:00:00Z', expectedInterval: COST_INDICES_INTERVAL, lastRunFailed: false },
    })

    expect(freshnessBadge(wrapper).text()).toBe('Cost indices · 5h ago')
    expect(freshnessBadge(wrapper).attributes('data-level')).toBe('aging')
  })
})

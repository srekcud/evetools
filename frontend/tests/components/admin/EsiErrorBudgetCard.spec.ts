import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import EsiErrorBudgetCard from '@/components/admin/EsiErrorBudgetCard.vue'
import type { AdminEsiErrorBudget } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

const HOURS_IN_WINDOW = 24

function budgetOf(overrides: Partial<AdminEsiErrorBudget> = {}): AdminEsiErrorBudget {
  return {
    remain: 12,
    resetSeconds: 45,
    observedAt: '2026-11-11T10:30:00+00:00',
    windowEndsAt: '2026-11-11T10:30:45+00:00',
    level: 'low',
    limited420: 3,
    limited429: 1,
    byFamily: [{ family: '/characters/{id}/assets/', limited420: 3, limited429: 1 }],
    hourly: Array.from({ length: HOURS_IN_WINDOW }, (_, hour) => ({
      hour: `2026-11-11T${String(hour).padStart(2, '0')}:00:00+00:00`,
      limited420: hour === 5 ? 3 : 0,
      limited429: hour === 5 ? 1 : 0,
    })),
    ...overrides,
  }
}

function mountCard(budget: AdminEsiErrorBudget, locale: TestLocale = 'en') {
  return mount(EsiErrorBudgetCard, {
    props: { budget },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('EsiErrorBudgetCard', () => {
  it('shows the remaining budget and the 420 and 429 counts of the last 24 hours', () => {
    const wrapper = mountCard(budgetOf())

    expect(wrapper.get('[data-testid="esi-budget-remain"]').text()).toBe('12')
    expect(wrapper.get('[data-testid="esi-budget-limited-420"]').text()).toBe('3')
    expect(wrapper.get('[data-testid="esi-budget-limited-429"]').text()).toBe('1')
  })

  it('lists the endpoint families with their counts', () => {
    const wrapper = mountCard(budgetOf())

    const family = wrapper.get('[data-testid="esi-budget-family"]')
    expect(family.text()).toContain('/characters/{id}/assets/')
    expect(family.text()).toContain('3')
    expect(family.text()).toContain('1')
  })

  it('draws one bar per hour of the window', () => {
    const wrapper = mountCard(budgetOf())

    expect(wrapper.findAll('[data-testid="esi-budget-hour"]')).toHaveLength(HOURS_IN_WINDOW)
  })

  it.each(TEST_LOCALES)('writes the %s name of the level', (locale) => {
    const wrapper = mountCard(budgetOf({ level: 'critical', remain: 2 }), locale)

    expect(wrapper.get('[data-testid="esi-budget-level"]').text()).toBe(messageOf(locale, 'admin.esiBudget.level.critical'))
  })

  it('shows an unknown budget without inventing a value', () => {
    const wrapper = mountCard(budgetOf({
      remain: null,
      resetSeconds: null,
      observedAt: null,
      windowEndsAt: null,
      level: 'unknown',
      limited420: 0,
      limited429: 0,
      byFamily: [],
    }))

    expect(wrapper.get('[data-testid="esi-budget-remain"]').text()).toBe('--')
    expect(wrapper.get('[data-testid="esi-budget-level"]').text()).toBe(messageOf('en', 'admin.esiBudget.level.unknown'))
    expect(wrapper.find('[data-testid="esi-budget-family"]').exists()).toBe(false)
  })

  it('names every level in both locales', () => {
    for (const level of ['unknown', 'ok', 'low', 'critical']) {
      for (const locale of TEST_LOCALES) {
        expect(messageOf(locale, `admin.esiBudget.level.${level}`)).not.toBe('')
      }
    }
  })
})

describe('EsiErrorBudgetCard, local clock', () => {
  const RELATIVE_TIME_TICK_MS = 30000

  afterEach(() => {
    vi.useRealTimers()
  })

  it('moves the last observation from just now to 1min ago after 30 seconds of local clock', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-11T10:30:40+00:00'))
    const wrapper = mountCard(budgetOf({ windowEndsAt: '2026-11-11T10:35:00+00:00' }))

    expect(wrapper.get('[data-testid="esi-budget-observed"]').text()).toBe('just now')

    vi.advanceTimersByTime(RELATIVE_TIME_TICK_MS)

    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="esi-budget-observed"]').text()).toBe('1min ago')
    wrapper.unmount()
  })

  it('turns the level to unknown once the local time passes the end of the window', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-11T10:30:30+00:00'))
    const wrapper = mountCard(budgetOf({ level: 'low', windowEndsAt: '2026-11-11T10:30:45+00:00' }))

    expect(wrapper.get('[data-testid="esi-budget-level"]').text()).toBe(messageOf('en', 'admin.esiBudget.level.low'))

    vi.advanceTimersByTime(RELATIVE_TIME_TICK_MS)
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="esi-budget-level"]').text()).toBe(messageOf('en', 'admin.esiBudget.level.unknown'))
    wrapper.unmount()
  })

  it('runs one interval while mounted and none after unmount', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-11T10:30:30+00:00'))
    const wrapper = mountCard(budgetOf())

    expect(vi.getTimerCount()).toBe(1)

    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)
  })
})

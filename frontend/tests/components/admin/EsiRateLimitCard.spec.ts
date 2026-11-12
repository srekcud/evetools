import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import EsiRateLimitCard from '@/components/admin/EsiRateLimitCard.vue'
import type { AdminEsiRateLimitGroup } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

function groupOf(overrides: Partial<AdminEsiRateLimitGroup> = {}): AdminEsiRateLimitGroup {
  return {
    group: 'assets',
    remaining: 5,
    limit: 150,
    windowSeconds: 900,
    observedAt: '2026-11-12T10:00:00+00:00',
    level: 'critical',
    ...overrides,
  }
}

function mountCard(groups: AdminEsiRateLimitGroup[], locale: TestLocale = 'en') {
  return mount(EsiRateLimitCard, {
    props: { groups },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('EsiRateLimitCard', () => {
  it('shows one row per group with its remaining tokens and its limit', () => {
    const wrapper = mountCard([
      groupOf(),
      groupOf({ group: 'market', remaining: 400, limit: 600, windowSeconds: 3600, level: 'ok' }),
    ])

    const rows = wrapper.findAll('[data-testid="esi-rate-limit-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].get('[data-testid="esi-rate-limit-group"]').text()).toBe('assets')
    expect(rows[0].get('[data-testid="esi-rate-limit-remaining"]').text()).toBe('5')
    expect(rows[0].get('[data-testid="esi-rate-limit-limit"]').text()).toBe('150')
    expect(rows[1].get('[data-testid="esi-rate-limit-group"]').text()).toBe('market')
    expect(rows[1].get('[data-testid="esi-rate-limit-remaining"]').text()).toBe('400')
    expect(rows[1].get('[data-testid="esi-rate-limit-limit"]').text()).toBe('600')
  })

  it('keeps the groups in the received order, lowest ratio first', () => {
    const wrapper = mountCard([
      groupOf({ group: 'assets', remaining: 5, limit: 150 }),
      groupOf({ group: 'skills', remaining: 9, limit: 50, level: 'low' }),
      groupOf({ group: 'market', remaining: 400, limit: 600, level: 'ok' }),
    ])

    const names = wrapper.findAll('[data-testid="esi-rate-limit-group"]').map((cell) => cell.text())
    expect(names).toEqual(['assets', 'skills', 'market'])
  })

  it.each(TEST_LOCALES)('writes the %s name of the critical and low levels', (locale) => {
    const wrapper = mountCard([groupOf(), groupOf({ group: 'skills', remaining: 9, limit: 50, level: 'low' })], locale)

    const levels = wrapper.findAll('[data-testid="esi-rate-limit-level"]').map((cell) => cell.text())
    expect(levels).toEqual([
      messageOf(locale, 'admin.esiBudget.level.critical'),
      messageOf(locale, 'admin.esiBudget.level.low'),
    ])
  })

  it('shows the last observed remaining of an unknown level without hiding it', () => {
    const wrapper = mountCard([groupOf({ group: 'skills', remaining: 9, limit: 50, level: 'unknown' })])

    const row = wrapper.get('[data-testid="esi-rate-limit-row"]')
    expect(row.get('[data-testid="esi-rate-limit-level"]').text()).toBe(messageOf('en', 'admin.esiBudget.level.unknown'))
    expect(row.get('[data-testid="esi-rate-limit-remaining"]').text()).toBe('9')
  })

  it('shows an empty state and no row when no group has been observed', () => {
    const wrapper = mountCard([])

    expect(wrapper.find('[data-testid="esi-rate-limit-row"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="esi-rate-limit-empty"]').exists()).toBe(true)
  })
})

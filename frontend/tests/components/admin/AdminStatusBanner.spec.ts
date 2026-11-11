import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import AdminStatusBanner from '@/components/admin/AdminStatusBanner.vue'
import type { AdminStatus, AdminStatusRule } from '@/stores/admin'

enableAutoUnmount(afterEach)

const SECTION_ELEMENT_ID = 'admin-section-scheduler-health'

function rule(overrides: Partial<AdminStatusRule> = {}): AdminStatusRule {
  return {
    key: 'scheduler_stale',
    severity: 'degraded',
    value: 2,
    threshold: null,
    section: 'scheduler-health',
    ...overrides,
  }
}

function mountBanner(status: AdminStatus | null, locale: 'en' | 'fr' = 'en'): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(AdminStatusBanner, { props: { status }, global: { plugins: [i18n] } })
}

describe('AdminStatusBanner', () => {
  it('shows the ok level without any rule line', () => {
    const wrapper = mountBanner({ level: 'ok', rules: [] })

    expect(wrapper.get('[data-testid="admin-status-banner"]').attributes('data-level')).toBe('ok')
    expect(wrapper.findAll('[data-testid="admin-status-rule"]')).toHaveLength(0)
  })

  it('shows one line per fired rule with its count', () => {
    const wrapper = mountBanner({
      level: 'down',
      rules: [rule(), rule({ key: 'async_queue_unreachable', severity: 'down', value: 0, section: 'maintenance' })],
    })

    const lines = wrapper.findAll('[data-testid="admin-status-rule"]')
    expect(wrapper.get('[data-testid="admin-status-banner"]').attributes('data-level')).toBe('down')
    expect(lines.map(line => line.attributes('data-rule-key'))).toEqual(['scheduler_stale', 'async_queue_unreachable'])
    expect(lines[0].text()).toContain('2')
    expect(lines[1].attributes('data-severity')).toBe('down')
  })

  it('scrolls to the section of a rule when its line is clicked', async () => {
    const section = document.createElement('div')
    section.id = SECTION_ELEMENT_ID
    section.scrollIntoView = vi.fn()
    document.body.appendChild(section)
    const wrapper = mountBanner({ level: 'degraded', rules: [rule()] })

    await wrapper.get('[data-testid="admin-status-rule"]').trigger('click')

    expect(section.scrollIntoView).toHaveBeenCalledTimes(1)
    section.remove()
  })

  it('shows an unknown level when the status could not be read', () => {
    const wrapper = mountBanner(null)

    expect(wrapper.get('[data-testid="admin-status-banner"]').attributes('data-level')).toBe('unknown')
  })

  it('has a French text for every rule key', () => {
    const keys = ['scheduler_stale', 'scheduler_error', 'failed_messages', 'async_queue_backlog', 'async_queue_unreachable', 'failed_queue_unreachable', 'tokens_invalid']
    const wrapper = mountBanner({ level: 'degraded', rules: keys.map(key => rule({ key })) }, 'fr')

    wrapper.findAll('[data-testid="admin-status-rule"]').forEach(line => {
      expect(line.text()).not.toContain('admin.status')
    })
  })
})

import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ModuleActiveUsersCard from '@/components/admin/ModuleActiveUsersCard.vue'
import type { AdminModuleActiveUsers } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, expectOnlyLocaleTexts, messageOf, type TestLocale } from '../../localeTexts'

const MODULES: AdminModuleActiveUsers[] = [
  { module: 'industry', activeLast7Days: 12, activeLast30Days: 30 },
  { module: 'groupIndustry', activeLast7Days: 0, activeLast30Days: 5 },
  { module: 'mining', activeLast7Days: 3, activeLast30Days: 10 },
  { module: 'planetary', activeLast7Days: 6, activeLast30Days: 12 },
  { module: 'market', activeLast7Days: 8, activeLast30Days: 20 },
  { module: 'pve', activeLast7Days: 2, activeLast30Days: 4 },
  { module: 'contracts', activeLast7Days: null, activeLast30Days: null },
]

const ACTIVE_USERS_LAST_30_DAYS = 40

function mountCard(modules: AdminModuleActiveUsers[] = MODULES, locale: TestLocale = 'en') {
  return mount(ModuleActiveUsersCard, {
    props: { modules, activeUsersLast30Days: ACTIVE_USERS_LAST_30_DAYS },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function rowOf(wrapper: ReturnType<typeof mountCard>, module: string) {
  return wrapper.get(`[data-testid="module-active-users-row-${module}"]`)
}

describe('ModuleActiveUsersCard', () => {
  it('lists one row per module in the order received', () => {
    const wrapper = mountCard()

    const rows = wrapper.findAll('[data-testid^="module-active-users-row-"]')
    expect(rows.map((row) => row.attributes('data-testid'))).toEqual(
      MODULES.map((entry) => `module-active-users-row-${entry.module}`),
    )
  })

  it('shows the seven and thirty day counts of a module', () => {
    const wrapper = mountCard()

    expect(rowOf(wrapper, 'industry').get('[data-testid="module-active-users-7d"]').text()).toBe('12')
    expect(rowOf(wrapper, 'industry').get('[data-testid="module-active-users-30d"]').text()).toBe('30')
  })

  it('shows a zero count as 0', () => {
    const wrapper = mountCard()

    expect(rowOf(wrapper, 'groupIndustry').get('[data-testid="module-active-users-7d"]').text()).toBe('0')
  })

  it('sizes the bar of a module against all the users active over thirty days', () => {
    const wrapper = mountCard()

    const bar = rowOf(wrapper, 'industry').get('[data-testid="module-active-users-bar"]')
    expect(bar.attributes('style')).toContain('width: 75%')
  })

  it('shows an unknown module as a dash with the unavailable mention and no bar', () => {
    const wrapper = mountCard()

    const row = rowOf(wrapper, 'contracts')
    expect(row.get('[data-testid="module-active-users-7d"]').text()).toBe('—')
    expect(row.get('[data-testid="module-active-users-30d"]').text()).toBe('—')
    expect(row.text()).toContain(messageOf('en', 'admin.moduleActiveUsers.unavailable'))
    expect(row.find('[data-testid="module-active-users-bar"]').exists()).toBe(false)
  })

  it.each(TEST_LOCALES)('shows the module names and the headings in %s only', (locale) => {
    const wrapper = mountCard(MODULES, locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, [
      'admin.moduleActiveUsers.title',
      'admin.moduleActiveUsers.last7Days',
      'admin.moduleActiveUsers.last30Days',
      'admin.moduleActiveUsers.modules.industry',
      'admin.moduleActiveUsers.modules.groupIndustry',
      'admin.moduleActiveUsers.modules.contracts',
    ])
  })
})

import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import SdeBuildCard from '@/components/admin/SdeBuildCard.vue'
import type { AdminSdeBuild } from '@/stores/admin'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'

function buildOf(overrides: Partial<AdminSdeBuild> = {}): AdminSdeBuild {
  return {
    status: 'outdated',
    importedBuildNumber: 3213160,
    importedReleaseDate: '2026-06-02T10:00:00+00:00',
    importedAt: '2026-06-03T08:15:00+00:00',
    latestBuildNumber: 3693310,
    latestReleaseDate: '2026-11-12T11:07:00+00:00',
    latestCheckedAt: '2026-11-13T13:30:00+00:00',
    ...overrides,
  }
}

function mountCard(build: AdminSdeBuild, locale: TestLocale = 'en') {
  return mount(SdeBuildCard, {
    props: { build },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

describe('SdeBuildCard', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-13T15:30:00+00:00'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the imported build and the latest CCP build with their release years', () => {
    const wrapper = mountCard(buildOf())

    expect(wrapper.get('[data-testid="sde-build-imported-number"]').text()).toBe('3213160')
    expect(wrapper.get('[data-testid="sde-build-imported-date"]').text()).toContain('2026')
    expect(wrapper.get('[data-testid="sde-build-latest-number"]').text()).toBe('3693310')
    expect(wrapper.get('[data-testid="sde-build-latest-date"]').text()).toContain('2026')
  })

  it('shows the last check as a time elapsed since the check', () => {
    const wrapper = mountCard(buildOf({ latestCheckedAt: '2026-11-13T13:30:00+00:00' }))

    expect(wrapper.get('[data-testid="sde-build-checked-at"]').text()).toBe('2h ago')
  })

  it.each([
    ['up_to_date', 'text-emerald-400'],
    ['outdated', 'text-amber-400'],
    ['not_recorded', 'text-amber-400'],
    ['unknown', 'text-slate-400'],
  ] as const)('shows the %s status with its label in both locales and its color', (status, colorClass) => {
    for (const locale of TEST_LOCALES) {
      const wrapper = mountCard(buildOf({ status }), locale)

      expect(wrapper.get('[data-testid="sde-build-status"]').text()).toBe(messageOf(locale, `admin.sdeBuild.status.${status}`))
    }
    expect(mountCard(buildOf({ status })).get('[data-testid="sde-build-status"]').classes()).toContain(colorClass)
  })

  it.each(['outdated', 'not_recorded'] as const)('tells the admin which command to run when the status is %s', (status) => {
    const wrapper = mountCard(buildOf({ status }))

    expect(wrapper.get('[data-testid="sde-build-command"]').text()).toContain('make sde-import')
  })

  it.each(['up_to_date', 'unknown'] as const)('shows no command when the status is %s', (status) => {
    const wrapper = mountCard(buildOf({ status }))

    expect(wrapper.find('[data-testid="sde-build-command"]').exists()).toBe(false)
  })

  it('writes -- for every missing value and never 0 before any check and any import', () => {
    const wrapper = mountCard(buildOf({
      status: 'unknown',
      importedBuildNumber: null,
      importedReleaseDate: null,
      importedAt: null,
      latestBuildNumber: null,
      latestReleaseDate: null,
      latestCheckedAt: null,
    }))

    for (const testId of ['imported-number', 'imported-date', 'latest-number', 'latest-date', 'checked-at']) {
      expect(wrapper.get(`[data-testid="sde-build-${testId}"]`).text(), testId).toBe('--')
    }
  })

  it('writes -- for the imported build only when none was recorded but a latest build is known', () => {
    const wrapper = mountCard(buildOf({ status: 'not_recorded', importedBuildNumber: null, importedReleaseDate: null, importedAt: null }))

    expect(wrapper.get('[data-testid="sde-build-imported-number"]').text()).toBe('--')
    expect(wrapper.get('[data-testid="sde-build-imported-date"]').text()).toBe('--')
    expect(wrapper.get('[data-testid="sde-build-latest-number"]').text()).toBe('3693310')
  })
})

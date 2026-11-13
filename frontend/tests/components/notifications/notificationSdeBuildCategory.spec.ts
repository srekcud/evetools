import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import NotificationItem from '@/components/notifications/NotificationItem.vue'
import { categoryColors, categoryIcons } from '@/components/notifications/notificationConstants'
import type { AppNotification } from '@/stores/notifications'

enableAutoUnmount(afterEach)

const SDE_BUILD_NOTIFICATION: AppNotification = {
  id: 'notification-sde-build-1',
  category: 'sde_build',
  level: 'warning',
  title: 'SDE build outdated',
  message: 'CCP published SDE build 3693310, the imported build is 3213160',
  data: { importedBuildNumber: 3213160, latestBuildNumber: 3693310, latestReleaseDate: '2026-11-12T11:07:00+00:00' },
  route: '/admin',
  isRead: false,
  createdAt: new Date().toISOString(),
}

function renderedIcon(notification: AppNotification): { path: string | undefined; classes: string[] } {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const svg = mount(NotificationItem, { props: { notification }, global: { plugins: [i18n] } }).get('svg')
  return { path: svg.find('path').attributes('d'), classes: svg.classes() }
}

describe('sde_build notification category', () => {
  it('is labelled in English and in French', () => {
    const labels = (messages: { notificationHub: { categories: object } }) => messages.notificationHub.categories as Record<string, string | undefined>

    expect([typeof labels(en).sde_build, typeof labels(fr).sde_build]).toEqual(['string', 'string'])
    expect(labels(en).sde_build).not.toBe('')
    expect(labels(fr).sde_build).not.toBe('')
  })

  it('has its own icon and its own color, distinct from the esi fallback', () => {
    const sdeBuild = renderedIcon(SDE_BUILD_NOTIFICATION)
    const esi = renderedIcon({ ...SDE_BUILD_NOTIFICATION, category: 'esi' })

    expect(Object.keys(categoryIcons)).toContain('sde_build')
    expect(Object.keys(categoryColors)).toContain('sde_build')
    expect(sdeBuild.path).not.toBe(esi.path)
    expect(sdeBuild.classes).not.toContain('text-slate-400')
    expect(sdeBuild.classes).not.toEqual(esi.classes)
  })
})

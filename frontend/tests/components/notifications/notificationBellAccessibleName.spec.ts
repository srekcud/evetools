import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import NotificationBell from '@/components/notifications/NotificationBell.vue'
import { useNotificationsStore } from '@/stores/notifications'

enableAutoUnmount(afterEach)

function mountBell(locale: 'en' | 'fr' = 'en'): VueWrapper {
  setActivePinia(createPinia())
  vi.spyOn(useNotificationsStore(), 'fetchUnreadCount').mockResolvedValue()
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(NotificationBell, {
    global: {
      plugins: [i18n],
      stubs: { NotificationDropdown: true, NotificationSettings: true },
    },
  })
}

describe('NotificationBell button', () => {
  it('has an accessible name', () => {
    const bell = mountBell().find('#notification-bell-btn')

    expect(bell.attributes('aria-label')).toBe(en.notificationHub.title)
  })

  it('is labelled in French', () => {
    const bell = mountBell('fr').find('#notification-bell-btn')

    expect(bell.attributes('aria-label')).toBe(fr.notificationHub.title)
  })

  it('tells assistive technologies whether the dropdown is open', async () => {
    const bell = mountBell().find('#notification-bell-btn')

    expect(bell.attributes('aria-expanded')).toBe('false')
    await bell.trigger('click')
    expect(bell.attributes('aria-expanded')).toBe('true')
  })
})

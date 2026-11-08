import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import NotificationDropdown from '@/components/notifications/NotificationDropdown.vue'
import NotificationItem from '@/components/notifications/NotificationItem.vue'
import { useNotificationsStore, type AppNotification } from '@/stores/notifications'

enableAutoUnmount(afterEach)

const MOON_CHUNK_NOTIFICATION: AppNotification = {
  id: 'notification-moon-chunk-1',
  category: 'moon_chunk',
  level: 'success',
  title: 'Moon chunk arrived',
  message: 'NOL-M9 VII - Moon 3 at NOL-M9 - Rock Hollow arrived at 20:00',
  data: { extractionId: '0195f3a0-2740-7000-8000-000000000101', structureId: 1035000000101, kind: 'arrived' },
  route: '/ledger?tab=mining&view=moonChunks',
  isRead: false,
  createdAt: new Date().toISOString(),
}

function mountDropdown(): { dropdown: VueWrapper; fetchNotifications: ReturnType<typeof vi.fn> } {
  setActivePinia(createPinia())
  const fetchNotifications = vi.spyOn(useNotificationsStore(), 'fetchNotifications').mockResolvedValue()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const router = createRouter({ history: createMemoryHistory(), routes: [] })
  const dropdown = mount(NotificationDropdown, { global: { plugins: [i18n, router] } })
  return { dropdown, fetchNotifications }
}

function iconPathOf(notification: AppNotification): string | undefined {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(NotificationItem, { props: { notification }, global: { plugins: [i18n] } }).find('svg path').attributes('d')
}

describe('moon_chunk notification category', () => {
  it('is labelled in English and in French, has its own filter tab that fetches only that category and its own icon', async () => {
    const categories = (messages: { notificationHub: { categories: object } }) => messages.notificationHub.categories as Record<string, string | undefined>
    expect([categories(en).moon_chunk, categories(fr).moon_chunk]).toEqual(['Moon Chunks', 'Chunks lunaires'])

    const { dropdown, fetchNotifications } = mountDropdown()
    const tab = dropdown.findAll('button').find(button => button.text() === 'Moon Chunks')
    await tab?.trigger('click')

    expect(fetchNotifications).toHaveBeenLastCalledWith(1, 'moon_chunk')
    expect(iconPathOf(MOON_CHUNK_NOTIFICATION)).not.toBe(iconPathOf({ ...MOON_CHUNK_NOTIFICATION, category: 'esi' }))
  })
})

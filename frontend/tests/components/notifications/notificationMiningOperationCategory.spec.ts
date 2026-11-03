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

const MINING_OPERATION_NOTIFICATION: AppNotification = {
  id: 'notification-mining-1',
  category: 'mining_operation',
  level: 'success',
  title: 'Mining ledger ready',
  message: 'Every ledger of the operation was read',
  data: { operationId: 'operation-1' },
  route: '/mining-operations/operation-1',
  isRead: false,
  createdAt: new Date().toISOString(),
}

function categoryLabelsOf(messages: { notificationHub: { categories: object } }): Record<string, string | undefined> {
  return messages.notificationHub.categories as Record<string, string | undefined>
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

describe('mining_operation notification category', () => {
  it('is labelled in English and in French', () => {
    expect([typeof categoryLabelsOf(en).mining_operation, typeof categoryLabelsOf(fr).mining_operation]).toEqual(['string', 'string'])
  })

  it('has its own filter tab in the dropdown that fetches only that category', async () => {
    const { dropdown, fetchNotifications } = mountDropdown()
    const label = categoryLabelsOf(en).mining_operation

    const tab = dropdown.findAll('button').find(button => button.text() === label)
    await tab?.trigger('click')

    expect(fetchNotifications).toHaveBeenLastCalledWith(1, 'mining_operation')
  })

  it('shows its own icon instead of the fallback of the other categories', () => {
    const fallbackIcon = iconPathOf({ ...MINING_OPERATION_NOTIFICATION, category: 'esi' })

    expect(iconPathOf(MINING_OPERATION_NOTIFICATION)).not.toBe(fallbackIcon)
  })
})

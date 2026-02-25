import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'

export type NotificationCategory = 'planetary' | 'industry' | 'escalation' | 'esi' | 'price'
export type NotificationLevel = 'info' | 'success' | 'warning' | 'error' | 'critical'

export interface AppNotification {
  id: string
  category: NotificationCategory
  level: NotificationLevel
  title: string
  message: string
  data: Record<string, unknown> | null
  route: string | null
  isRead: boolean
  createdAt: string
}

export interface NotificationPreference {
  category: NotificationCategory
  enabled: boolean
  thresholdMinutes: number | null
  pushEnabled: boolean
}

const PAGE_SIZE = 30

export const useNotificationsStore = defineStore('notifications', () => {
  // State
  const notifications = ref<AppNotification[]>([])
  const unreadCount = ref(0)
  const preferences = ref<NotificationPreference[]>([])
  const isLoading = ref(false)
  const isLoadingMore = ref(false)
  const isSavingPreferences = ref(false)
  const error = ref<string | null>(null)
  const currentPage = ref(1)
  const hasMore = ref(false)
  const totalItems = ref(0)

  // Computed
  const unreadNotifications = computed(() =>
    notifications.value.filter(n => !n.isRead)
  )

  // Actions
  async function fetchNotifications(
    page = 1,
    category?: NotificationCategory,
    isRead?: boolean
  ): Promise<void> {
    if (page === 1) {
      isLoading.value = true
    } else {
      isLoadingMore.value = true
    }
    error.value = null

    try {
      const params: string[] = [`page=${page}`]
      if (category) params.push(`category=${category}`)
      if (isRead !== undefined) params.push(`isRead=${isRead}`)

      const url = `/me/notifications?${params.join('&')}`
      const data = await apiRequest<AppNotification[]>(url)
      const items = data ?? []

      if (page === 1) {
        notifications.value = items
      } else {
        notifications.value = [...notifications.value, ...items]
      }

      currentPage.value = page
      hasMore.value = items.length >= PAGE_SIZE
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch notifications'
    } finally {
      isLoading.value = false
      isLoadingMore.value = false
    }
  }

  async function fetchUnreadCount(): Promise<void> {
    try {
      const data = await apiRequest<{ count: number }>('/me/notifications/unread-count')
      unreadCount.value = data.count
    } catch (e) {
      console.error('Failed to fetch unread count:', e)
    }
  }

  async function markAsRead(id: string): Promise<void> {
    try {
      await apiRequest(`/me/notifications/${id}/read`, {
        method: 'PATCH',
        body: JSON.stringify({}),
      })

      const notif = notifications.value.find(n => n.id === id)
      if (notif && !notif.isRead) {
        notif.isRead = true
        unreadCount.value = Math.max(0, unreadCount.value - 1)
      }
    } catch (e) {
      console.error('Failed to mark notification as read:', e)
    }
  }

  async function markAllAsRead(): Promise<void> {
    try {
      await apiRequest('/me/notifications/read-all', {
        method: 'POST',
        body: JSON.stringify({}),
      })

      notifications.value.forEach(n => {
        n.isRead = true
      })
      unreadCount.value = 0
    } catch (e) {
      console.error('Failed to mark all as read:', e)
    }
  }

  async function fetchPreferences(): Promise<void> {
    try {
      const data = await apiRequest<{ preferences: NotificationPreference[] }>(
        '/me/notification-preferences'
      )
      preferences.value = data.preferences ?? []
    } catch (e) {
      console.error('Failed to fetch preferences:', e)
    }
  }

  async function savePreferences(prefs: NotificationPreference[]): Promise<void> {
    isSavingPreferences.value = true
    try {
      await apiRequest('/me/notification-preferences', {
        method: 'PUT',
        body: JSON.stringify({ preferences: prefs }),
      })
      preferences.value = prefs
    } finally {
      isSavingPreferences.value = false
    }
  }

  async function registerPush(subscription: PushSubscriptionJSON): Promise<void> {
    try {
      await apiRequest('/me/push-subscription', {
        method: 'POST',
        body: JSON.stringify(subscription),
      })
    } catch (e) {
      console.error('Failed to register push subscription:', e)
      throw e
    }
  }

  async function unregisterPush(): Promise<void> {
    try {
      await apiRequest('/me/push-subscription', {
        method: 'DELETE',
      })
    } catch (e) {
      console.error('Failed to unregister push subscription:', e)
    }
  }

  function addNotification(notification: AppNotification): void {
    // Avoid duplicates
    const exists = notifications.value.some(n => n.id === notification.id)
    if (!exists) {
      notifications.value.unshift(notification)
      if (!notification.isRead) {
        unreadCount.value++
      }
    }
  }

  function clearError(): void {
    error.value = null
  }

  return {
    // State
    notifications,
    unreadCount,
    preferences,
    isLoading,
    isLoadingMore,
    isSavingPreferences,
    error,
    currentPage,
    hasMore,
    totalItems,

    // Computed
    unreadNotifications,

    // Actions
    fetchNotifications,
    fetchUnreadCount,
    markAsRead,
    markAllAsRead,
    fetchPreferences,
    savePreferences,
    registerPush,
    unregisterPush,
    addNotification,
    clearError,
  }
})

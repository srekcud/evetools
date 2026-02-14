import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSyncStore } from '@/stores/sync'

export interface Notification {
  id: string
  type: 'sync' | 'alert' | 'job-completed' | 'project'
  title: string
  message: string
  level: 'info' | 'success' | 'warning' | 'error'
  timestamp: Date
  data?: Record<string, unknown>
}

const MAX_NOTIFICATIONS = 50
const notifications = ref<Notification[]>([])
let nextId = 0

function addNotification(notification: Omit<Notification, 'id' | 'timestamp'>): void {
  const entry: Notification = {
    ...notification,
    id: String(nextId++),
    timestamp: new Date(),
  }
  notifications.value = [entry, ...notifications.value].slice(0, MAX_NOTIFICATIONS)
}

function clearAll(): void {
  notifications.value = []
}

function removeNotification(id: string): void {
  notifications.value = notifications.value.filter(n => n.id !== id)
}

const SYNC_TITLE_KEYS: Record<string, string> = {
  'character-assets': 'notifications.syncTitles.characterAssets',
  'corporation-assets': 'notifications.syncTitles.corporationAssets',
  'ansiblex': 'notifications.syncTitles.ansiblex',
  'industry-jobs': 'notifications.syncTitles.industryJobs',
  'pve': 'notifications.syncTitles.pve',
  'mining': 'notifications.syncTitles.mining',
  'wallet-transactions': 'notifications.syncTitles.walletTransactions',
  'market-structure': 'notifications.syncTitles.marketStructure',
  'planetary': 'notifications.syncTitles.planetary',
}

export function useNotificationFeed() {
  const syncStore = useSyncStore()
  const { t } = useI18n()

  function getSyncTitle(syncType: string): string {
    const key = SYNC_TITLE_KEYS[syncType]
    return key ? t(key) : syncType
  }

  // Watch all sync status changes for activity feed
  watch(
    () => syncStore.syncStatus,
    (newStatus, oldStatus) => {
      for (const [syncType, progress] of Object.entries(newStatus)) {
        const prev = oldStatus?.[syncType]
        if (!prev || prev.status === progress.status) continue

        if (progress.status === 'completed') {
          addNotification({
            type: 'sync',
            title: getSyncTitle(syncType),
            message: progress.message || t('notifications.syncCompleted'),
            level: 'success',
            data: progress.data ?? undefined,
          })
        } else if (progress.status === 'error') {
          addNotification({
            type: 'sync',
            title: getSyncTitle(syncType),
            message: progress.message || t('notifications.syncError'),
            level: 'error',
          })
        }

        // Industry job completed notifications (custom status from backend)
        if (syncType === 'industry-job-completed') {
          const statusStr = progress.status as string
          if (statusStr === 'notification') {
            addNotification({
              type: 'job-completed',
              title: t('notifications.jobCompleted'),
              message: progress.message || t('notifications.jobCompletedMessage'),
              level: 'info',
              data: progress.data ?? undefined,
            })
          }
        }

        // Industry project progress
        if (syncType === 'industry-project') {
          const statusStr = progress.status as string
          if (statusStr === 'notification') {
            addNotification({
              type: 'project',
              title: t('notifications.projectProgress'),
              message: progress.message || t('notifications.projectProgressMessage'),
              level: 'info',
              data: progress.data ?? undefined,
            })
          }
        }
      }
    },
    { deep: true }
  )

  const unreadCount = computed(() => notifications.value.length)

  return {
    notifications: computed(() => notifications.value),
    unreadCount,
    addNotification,
    clearAll,
    removeNotification,
  }
}

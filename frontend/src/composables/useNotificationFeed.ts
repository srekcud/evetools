import { ref, computed, watch } from 'vue'
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

function getSyncTitle(syncType: string): string {
  const titles: Record<string, string> = {
    'character-assets': 'Assets personnage',
    'corporation-assets': 'Assets corporation',
    'ansiblex': 'Ansiblex',
    'industry-jobs': 'Jobs industrie',
    'pve': 'Donnees PVE',
    'mining': 'Ledger minage',
    'wallet-transactions': 'Transactions wallet',
    'market-structure': 'Prix marche',
    'planetary': 'Colonies PI',
  }
  return titles[syncType] || syncType
}

export function useNotificationFeed() {
  const syncStore = useSyncStore()

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
            message: progress.message || 'Synchronisation terminee',
            level: 'success',
            data: progress.data ?? undefined,
          })
        } else if (progress.status === 'error') {
          addNotification({
            type: 'sync',
            title: getSyncTitle(syncType),
            message: progress.message || 'Erreur de synchronisation',
            level: 'error',
          })
        }

        // Industry job completed notifications (custom status from backend)
        if (syncType === 'industry-job-completed') {
          const statusStr = progress.status as string
          if (statusStr === 'notification') {
            addNotification({
              type: 'job-completed',
              title: 'Job industrie termine',
              message: progress.message || 'Un job est termine',
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
              title: 'Projet industrie',
              message: progress.message || 'Progression projet',
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

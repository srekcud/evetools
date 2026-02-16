import { ref, computed } from 'vue'
import { useNotificationsStore } from '@/stores/notifications'

const permission = ref<NotificationPermission>(
  typeof Notification !== 'undefined' ? Notification.permission : 'default'
)

export function usePushNotifications() {
  const isSupported = computed(() => {
    return typeof window !== 'undefined' &&
      'serviceWorker' in navigator &&
      'PushManager' in window
  })

  async function requestPermission(): Promise<boolean> {
    if (!isSupported.value) return false

    try {
      const result = await Notification.requestPermission()
      permission.value = result
      return result === 'granted'
    } catch {
      return false
    }
  }

  async function subscribe(): Promise<void> {
    if (!isSupported.value) return

    const vapidPublicKey = import.meta.env.VITE_VAPID_PUBLIC_KEY
    if (!vapidPublicKey) {
      console.warn('Push: VITE_VAPID_PUBLIC_KEY not configured')
      return
    }

    try {
      const registration = await navigator.serviceWorker.register('/sw-notifications.js')
      await navigator.serviceWorker.ready

      const existingSubscription = await registration.pushManager.getSubscription()
      if (existingSubscription) {
        // Already subscribed, send to backend in case it was lost
        const store = useNotificationsStore()
        await store.registerPush(existingSubscription.toJSON())
        return
      }

      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) as BufferSource,
      })

      const store = useNotificationsStore()
      await store.registerPush(subscription.toJSON())
    } catch (e) {
      console.error('Push: Failed to subscribe:', e)
      throw e
    }
  }

  async function unsubscribe(): Promise<void> {
    if (!isSupported.value) return

    try {
      const registration = await navigator.serviceWorker.getRegistration('/sw-notifications.js')
      if (registration) {
        const subscription = await registration.pushManager.getSubscription()
        if (subscription) {
          await subscription.unsubscribe()
        }
      }

      const store = useNotificationsStore()
      await store.unregisterPush()
    } catch (e) {
      console.error('Push: Failed to unsubscribe:', e)
    }
  }

  return {
    isSupported,
    permission,
    requestPermission,
    subscribe,
    unsubscribe,
  }
}

/**
 * Convert a base64-encoded VAPID public key to a Uint8Array
 * for use with PushManager.subscribe()
 */
function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding)
    .replace(/-/g, '+')
    .replace(/_/g, '/')

  const rawData = window.atob(base64)
  const outputArray = new Uint8Array(rawData.length)

  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i)
  }

  return outputArray
}

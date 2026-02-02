import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { safeJsonParse } from '@/services/api'

export type SyncStatus = 'idle' | 'started' | 'in_progress' | 'completed' | 'error'

export interface SyncProgress {
  syncType: string
  status: SyncStatus
  progress: number | null
  message: string | null
  data: Record<string, unknown> | null
  timestamp: string
}

export interface MercureToken {
  token: string
  topics: string[]
  hubUrl: string
}

export function useMercure() {
  const authStore = useAuthStore()
  const eventSource = ref<EventSource | null>(null)
  const isConnected = ref(false)
  const connectionError = ref<string | null>(null)
  const syncStatus = ref<Record<string, SyncProgress>>({})
  const reconnectAttempts = ref(0)
  const maxReconnectAttempts = 5
  const reconnectDelay = 3000

  let reconnectTimeout: ReturnType<typeof setTimeout> | null = null

  async function fetchToken(): Promise<MercureToken | null> {
    try {
      const response = await fetch('/api/mercure/token', {
        headers: {
          Authorization: `Bearer ${authStore.token}`,
        },
      })

      if (!response.ok) {
        throw new Error('Failed to fetch Mercure token')
      }

      return await safeJsonParse(response)
    } catch (error) {
      console.error('Failed to fetch Mercure token:', error)
      connectionError.value = 'Failed to get subscription token'
      return null
    }
  }

  async function connect(): Promise<boolean> {
    // Already connected
    if (eventSource.value && isConnected.value) {
      return true
    }

    // Disconnect any existing connection first
    disconnect()

    const tokenData = await fetchToken()
    if (!tokenData) {
      return false
    }

    try {
      const url = new URL(tokenData.hubUrl, window.location.origin)

      // Add topics to subscribe to
      tokenData.topics.forEach((topic) => {
        url.searchParams.append('topic', topic)
      })

      // Create EventSource with authorization
      // For Mercure, we pass the JWT token as a cookie or via URL
      // Since we're using anonymous subscribers, we'll pass via authorization param
      url.searchParams.set('authorization', tokenData.token)

      eventSource.value = new EventSource(url.toString())

      eventSource.value.onopen = () => {
        isConnected.value = true
        connectionError.value = null
        reconnectAttempts.value = 0
        console.debug('Mercure: Connected')
      }

      eventSource.value.onmessage = (event: MessageEvent) => {
        try {
          const data: SyncProgress = JSON.parse(event.data)
          syncStatus.value = {
            ...syncStatus.value,
            [data.syncType]: data,
          }
          console.debug('Mercure: Received update', data)
        } catch (error) {
          console.error('Mercure: Failed to parse message', error)
        }
      }

      eventSource.value.onerror = (_event: Event) => {
        console.error('Mercure: Connection error')
        isConnected.value = false

        // Only attempt reconnect if not intentionally closed
        if (eventSource.value?.readyState === EventSource.CLOSED) {
          attemptReconnect()
        }
      }

      return true
    } catch (error) {
      console.error('Mercure: Failed to connect', error)
      connectionError.value = 'Failed to establish real-time connection'
      return false
    }
  }

  function disconnect() {
    if (reconnectTimeout) {
      clearTimeout(reconnectTimeout)
      reconnectTimeout = null
    }

    if (eventSource.value) {
      eventSource.value.close()
      eventSource.value = null
    }

    isConnected.value = false
  }

  function attemptReconnect() {
    if (reconnectAttempts.value >= maxReconnectAttempts) {
      connectionError.value = 'Real-time connection lost. Refresh the page to reconnect.'
      console.error('Mercure: Max reconnect attempts reached')
      return
    }

    reconnectAttempts.value++
    const delay = reconnectDelay * Math.pow(2, reconnectAttempts.value - 1) // Exponential backoff

    console.debug(`Mercure: Attempting reconnect ${reconnectAttempts.value}/${maxReconnectAttempts} in ${delay}ms`)

    reconnectTimeout = setTimeout(() => {
      connect()
    }, delay)
  }

  function getSyncProgress(syncType: string): SyncProgress | null {
    return syncStatus.value[syncType] ?? null
  }

  function isLoading(syncType: string): boolean {
    const status = syncStatus.value[syncType]
    return status?.status === 'started' || status?.status === 'in_progress'
  }

  function clearSyncStatus(syncType: string) {
    const { [syncType]: _, ...rest } = syncStatus.value
    syncStatus.value = rest
  }

  function clearAllSyncStatus() {
    syncStatus.value = {}
  }

  // Computed helpers
  const isAnyLoading = computed(() => {
    return Object.values(syncStatus.value).some(
      (s: SyncProgress) => s.status === 'started' || s.status === 'in_progress'
    )
  })

  const characterAssetsProgress = computed(() => getSyncProgress('character-assets'))
  const corporationAssetsProgress = computed(() => getSyncProgress('corporation-assets'))
  const ansiblexProgress = computed(() => getSyncProgress('ansiblex'))

  // Auto-connect on mount, cleanup on unmount
  onMounted(() => {
    if (authStore.isAuthenticated) {
      connect()
    }
  })

  onUnmounted(() => {
    disconnect()
  })

  return {
    // State
    isConnected,
    connectionError,
    syncStatus,
    reconnectAttempts,

    // Actions
    connect,
    disconnect,
    getSyncProgress,
    isLoading,
    clearSyncStatus,
    clearAllSyncStatus,

    // Computed
    isAnyLoading,
    characterAssetsProgress,
    corporationAssetsProgress,
    ansiblexProgress,
  }
}

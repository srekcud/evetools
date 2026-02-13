import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useAuthStore } from './auth'
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

export const useSyncStore = defineStore('sync', () => {
  const authStore = useAuthStore()

  // State
  const eventSource = ref<EventSource | null>(null)
  const isConnected = ref(false)
  const connectionError = ref<string | null>(null)
  const syncStatus = ref<Record<string, SyncProgress>>({})
  const reconnectAttempts = ref(0)

  // Config
  const maxReconnectAttempts = 5
  const reconnectDelay = 3000
  let reconnectTimeout: ReturnType<typeof setTimeout> | null = null

  // Computed
  const isAnyLoading = computed(() => {
    return Object.values(syncStatus.value).some(
      (s: SyncProgress) => s.status === 'started' || s.status === 'in_progress'
    )
  })

  const characterAssetsProgress = computed(() => syncStatus.value['character-assets'] ?? null)
  const corporationAssetsProgress = computed(() => syncStatus.value['corporation-assets'] ?? null)
  const ansiblexProgress = computed(() => syncStatus.value['ansiblex'] ?? null)
  const industryJobsProgress = computed(() => syncStatus.value['industry-jobs'] ?? null)
  const pveProgress = computed(() => syncStatus.value['pve'] ?? null)
  const marketStructureProgress = computed(() => syncStatus.value['market-structure'] ?? null)
  const industryProjectProgress = computed(() => syncStatus.value['industry-project'] ?? null)
  const industryJobCompletedProgress = computed(() => syncStatus.value['industry-job-completed'] ?? null)
  const miningProgress = computed(() => getSyncProgress('mining'))
  const walletTransactionsProgress = computed(() => getSyncProgress('wallet-transactions'))
  const planetaryProgress = computed(() => getSyncProgress('planetary'))
  const adminSyncProgress = computed(() => getSyncProgress('admin-sync'))

  // Actions
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

    // Not authenticated
    if (!authStore.isAuthenticated) {
      return false
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

      // Pass JWT token via URL parameter for Mercure authentication
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
    const newStatus = { ...syncStatus.value }
    delete newStatus[syncType]
    syncStatus.value = newStatus
  }

  function clearAllSyncStatus() {
    syncStatus.value = {}
  }

  // Auto-connect when authenticated, disconnect on logout
  watch(
    () => authStore.isAuthenticated,
    (isAuth: boolean) => {
      if (isAuth) {
        connect()
      } else {
        disconnect()
        clearAllSyncStatus()
      }
    },
    { immediate: true }
  )

  return {
    // State
    isConnected,
    connectionError,
    syncStatus,
    reconnectAttempts,

    // Computed
    isAnyLoading,
    characterAssetsProgress,
    corporationAssetsProgress,
    ansiblexProgress,
    industryJobsProgress,
    pveProgress,
    marketStructureProgress,
    industryProjectProgress,
    industryJobCompletedProgress,
    miningProgress,
    walletTransactionsProgress,
    planetaryProgress,
    adminSyncProgress,

    // Actions
    connect,
    disconnect,
    getSyncProgress,
    isLoading,
    clearSyncStatus,
    clearAllSyncStatus,
  }
})

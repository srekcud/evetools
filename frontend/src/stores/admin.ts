import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export interface AdminUserStats {
  total: number
  valid: number
  invalid: number
  activeLastWeek: number
  activeLastMonth: number
}

export interface AdminCharacterStats {
  total: number
  withValidTokens: number
  needingSync: number
  activeSyncScope: number
}

export interface AdminTokenStats {
  total: number
  expired: number
  expiring24h: number
  healthy: number
}

export interface AdminIndustryJobsStats {
  activeJobs: number
  completedRecently: number
  lastSync: string | null
}

export interface AdminSyncStats {
  lastAssetSync: string | null
  lastIndustrySync: string | null
  structuresCached: number
  ansiblexCount: number
  walletTransactionCount: number
  lastWalletSync: string | null
  lastMiningSync: string | null
}

export interface AdminAssetStats {
  totalItems: number
  personalAssets: number
  corporationAssets: number
}

export interface AdminIndustryStats {
  activeProjects: number
  completedProjects: number
}

export interface PveCorporationStats {
  corporationId: number
  corporationName: string
  total: number
}

export interface AdminPveStats {
  totalIncome30d: number
  byCorporation: PveCorporationStats[]
}

export interface SchedulerHealthEntry {
  type: string
  label: string
  status: 'running' | 'ok' | 'error' | 'unknown'
  health: 'healthy' | 'late' | 'stale' | 'running' | 'unknown'
  startedAt: string | null
  completedAt: string | null
  message: string | null
  expectedInterval: number
}

export interface AdminStats {
  users: AdminUserStats
  characters: AdminCharacterStats
  tokens: AdminTokenStats
  assets: AdminAssetStats
  industry: AdminIndustryStats
  industryJobs: AdminIndustryJobsStats
  syncs: AdminSyncStats
  pve: AdminPveStats
  schedulerHealth: SchedulerHealthEntry[]
}

export interface AdminQueues {
  queues: {
    async: number | null
    failed: number | null
  }
}

export interface AdminChartData {
  registrations: {
    labels: string[]
    data: number[]
  }
  activity: {
    labels: string[]
    logins: number[]
  }
  assetDistribution: {
    labels: string[]
    data: number[]
  }
}

export interface AdminAccess {
  hasAccess: boolean
  characterName: string | null
}

export const useAdminStore = defineStore('admin', () => {
  const stats = ref<AdminStats | null>(null)
  const queues = ref<AdminQueues | null>(null)
  const charts = ref<AdminChartData | null>(null)
  const access = ref<AdminAccess | null>(null)

  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function checkAccess(): Promise<boolean> {
    try {
      const response = await apiRequest<AdminAccess>('/admin/access')
      access.value = response
      return response.hasAccess
    } catch (e) {
      access.value = { hasAccess: false, characterName: null }
      return false
    }
  }

  async function fetchStats(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await apiRequest<AdminStats>('/admin/stats')
      stats.value = response
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch stats'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchQueues(): Promise<void> {
    try {
      const response = await apiRequest<AdminQueues>('/admin/queues')
      queues.value = response
    } catch (e) {
      console.error('Failed to fetch queues:', e)
    }
  }

  async function fetchCharts(): Promise<void> {
    try {
      const response = await apiRequest<AdminChartData>('/admin/charts')
      charts.value = response
    } catch (e) {
      console.error('Failed to fetch charts:', e)
    }
  }

  async function fetchAll(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      await Promise.all([
        fetchStats(),
        fetchQueues(),
        fetchCharts(),
      ])
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch admin data'
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  async function triggerAction(action: string): Promise<{ success: boolean; message: string }> {
    try {
      const response = await apiRequest<{ success: boolean; message: string }>(`/admin/actions/${action}`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
      return response
    } catch (e) {
      return {
        success: false,
        message: e instanceof Error ? e.message : 'Action failed',
      }
    }
  }

  async function syncAssets(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-assets')
  }

  async function syncMarket(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-market')
  }

  async function syncPve(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-pve')
  }

  async function syncIndustry(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-industry')
  }

  async function syncWallet(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-wallet')
  }

  async function syncMining(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-mining')
  }

  async function syncAnsiblex(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-ansiblex')
  }

  async function syncPlanetary(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-planetary')
  }

  async function retryFailed(): Promise<{ success: boolean; message: string }> {
    return triggerAction('retry-failed')
  }

  async function purgeFailed(): Promise<{ success: boolean; message: string }> {
    return triggerAction('purge-failed')
  }

  async function clearCache(): Promise<{ success: boolean; message: string }> {
    return triggerAction('clear-cache')
  }

  return {
    stats,
    queues,
    charts,
    access,
    isLoading,
    error,
    checkAccess,
    fetchStats,
    fetchQueues,
    fetchCharts,
    fetchAll,
    clearError,
    syncAssets,
    syncMarket,
    syncPve,
    syncIndustry,
    syncWallet,
    syncMining,
    syncAnsiblex,
    syncPlanetary,
    retryFailed,
    purgeFailed,
    clearCache,
  }
})

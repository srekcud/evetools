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

export interface SchedulerHealthRun {
  startedAt: string
  completedAt?: string | null
  status: 'running' | 'ok' | 'error'
  message?: string | null
  stuck: boolean
}

export interface SchedulerHealthEntry {
  type: string
  status: 'running' | 'ok' | 'error' | 'unknown'
  health: 'healthy' | 'late' | 'stale' | 'running' | 'unknown' | 'on-demand'
  startedAt: string | null
  completedAt: string | null
  message: string | null
  // null for on-demand syncs: not scheduled, so no health is computed
  expectedInterval?: number | null
  onDemand: boolean
  triggerable: boolean
  runs: SchedulerHealthRun[]
  successRate24h?: number | null
}

export interface AdminEsiErrorBudgetFamily {
  family: string
  limited420: number
  limited429: number
}

export interface AdminEsiErrorBudgetHour {
  hour: string
  limited420: number
  limited429: number
}

export interface AdminEsiErrorBudget {
  remain: number | null
  resetSeconds: number | null
  observedAt: string | null
  windowEndsAt: string | null
  level: 'unknown' | 'ok' | 'low' | 'critical'
  limited420: number
  limited429: number
  byFamily: AdminEsiErrorBudgetFamily[]
  hourly: AdminEsiErrorBudgetHour[]
}

export interface AdminSdeBuild {
  status: 'unknown' | 'not_recorded' | 'up_to_date' | 'outdated'
  importedBuildNumber: number | null
  importedReleaseDate: string | null
  importedAt: string | null
  latestBuildNumber: number | null
  latestReleaseDate: string | null
  latestCheckedAt: string | null
}

export interface AdminEsiRateLimitGroup {
  group: string
  remaining: number
  limit: number
  windowSeconds: number
  observedAt: string
  level: 'unknown' | 'ok' | 'low' | 'critical'
}

export interface AdminMercureCookieUser {
  userId: string
  mainCharacterName: string | null
  cookieBytes: number
  topicCount: number
}

export interface AdminMercureCookie {
  limitBytes: number
  warningThresholdBytes: number
  nearLimitCount: number
  users: AdminMercureCookieUser[]
}

export type AdminCharacterRepairState = 'invalid' | 'missing_scopes'

export interface AdminCharacterToRepair {
  eveCharacterId: number
  characterName: string
  userId?: string | null
  userLastLoginAt?: string | null
  state: AdminCharacterRepairState
  missingScopes: string[]
  sinceAt: string
}

export interface AdminCharactersToRepair {
  count: number
  characters: AdminCharacterToRepair[]
}

export interface AdminCharacter {
  eveCharacterId: number
  characterName?: string | null
  hasUser: boolean
}

export interface AdminCharacters {
  count: number
  characters: AdminCharacter[]
}

export interface AdminNotificationStats {
  total: number
  unread: number
  pushSubscriptions: number
  preferences: number
}

export interface AdminMarketStats {
  historyTypes: number
  historyEntries: number
  alertsActive: number
  alertsTriggered: number
  favorites: number
}

export interface AdminModuleActiveUsers {
  module: string
  activeLast7Days: number | null
  activeLast30Days: number | null
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
  notifications: AdminNotificationStats
  market: AdminMarketStats
  schedulerHealth: SchedulerHealthEntry[]
  moduleActiveUsers: AdminModuleActiveUsers[]
  esiErrorBudget: AdminEsiErrorBudget
  sdeBuild: AdminSdeBuild
  esiRateLimits: AdminEsiRateLimitGroup[]
  mercureCookie: AdminMercureCookie
  charactersToRepair: AdminCharactersToRepair
  adminCharacters: AdminCharacters
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
}

export type AdminUserTokenState = 'valid' | 'expiring' | 'invalid' | 'no_token'

export interface AdminUserSearchHit {
  userId: string
  mainCharacterName?: string | null
  mainEveCharacterId?: number | null
  characterCount: number
  lastLoginAt?: string | null
}

export interface AdminUserSheetCharacter {
  eveCharacterId: number
  name: string
  isMain: boolean
  tokenState: AdminUserTokenState
  accessTokenExpiresAt?: string | null
  missingScopes: string[]
  lastSyncAt?: string | null
}

export interface AdminUserSheetSync {
  type: string
  lastDataAt?: string | null
  triggerable: boolean
}

export interface AdminUserSheet {
  userId: string
  createdAt: string
  lastLoginAt?: string | null
  characters: AdminUserSheetCharacter[]
  syncs: AdminUserSheetSync[]
}

export interface AdminStatusRule {
  key: string
  severity: 'degraded' | 'down'
  value: number
  threshold: number | null
  section: string
}

export interface AdminStatus {
  level: 'ok' | 'degraded' | 'down'
  rules: AdminStatusRule[]
}

export interface AdminAccess {
  hasAccess: boolean
  characterName: string | null
}

export const useAdminStore = defineStore('admin', () => {
  const stats = ref<AdminStats | null>(null)
  const queues = ref<AdminQueues | null>(null)
  const charts = ref<AdminChartData | null>(null)
  const status = ref<AdminStatus | null>(null)
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

  async function fetchStatus(): Promise<void> {
    try {
      status.value = await apiRequest<AdminStatus>('/admin/status')
    } catch (e) {
      status.value = null
      console.error('Failed to fetch status:', e)
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
        fetchStatus(),
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

  async function triggerSync(type: string): Promise<{ success: boolean; message: string }> {
    try {
      return await apiRequest<{ success: boolean; message: string }>('/admin/actions/sync', {
        method: 'POST',
        body: JSON.stringify({ type }),
      })
    } catch (e) {
      return {
        success: false,
        message: e instanceof Error ? e.message : 'Action failed',
      }
    }
  }

  async function searchUsers(term: string): Promise<AdminUserSearchHit[]> {
    return apiRequest<AdminUserSearchHit[]>(`/admin/users?search=${encodeURIComponent(term)}`)
  }

  async function fetchUserSheet(userId: string): Promise<AdminUserSheet> {
    return apiRequest<AdminUserSheet>(`/admin/users/${userId}`)
  }

  async function triggerUserSync(userId: string, type: string): Promise<{ success: boolean; message: string }> {
    try {
      return await apiRequest<{ success: boolean; message: string }>(`/admin/users/${userId}/sync`, {
        method: 'POST',
        body: JSON.stringify({ type }),
      })
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

  async function checkMarketAlerts(): Promise<{ success: boolean; message: string }> {
    return triggerAction('check-market-alerts')
  }

  async function syncAlertPrices(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-alert-prices')
  }

  async function syncPublicContracts(): Promise<{ success: boolean; message: string }> {
    return triggerAction('sync-public-contracts')
  }

  async function purgeNotifications(): Promise<{ success: boolean; message: string }> {
    return triggerAction('purge-notifications')
  }

  async function purgeMarketHistory(): Promise<{ success: boolean; message: string }> {
    return triggerAction('purge-market-history')
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
    status,
    access,
    isLoading,
    error,
    checkAccess,
    fetchStats,
    fetchQueues,
    fetchCharts,
    fetchStatus,
    fetchAll,
    clearError,
    triggerSync,
    searchUsers,
    fetchUserSheet,
    triggerUserSync,
    syncAssets,
    syncMarket,
    syncPve,
    syncIndustry,
    syncWallet,
    syncMining,
    syncAnsiblex,
    syncPlanetary,
    checkMarketAlerts,
    syncAlertPrices,
    syncPublicContracts,
    purgeNotifications,
    purgeMarketHistory,
    retryFailed,
    purgeFailed,
    clearCache,
  }
})

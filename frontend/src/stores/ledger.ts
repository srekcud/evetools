import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'

// Types
export interface LedgerDashboard {
  period: {
    from: string
    to: string
    days: number
  }
  totals: {
    total: number
    pve: number
    mining: number
    expenses: number
    profit: number
  }
  pveBreakdown: {
    bounties: number
    ess: number
    missions: number
    lootSales: number
    corpProjects: number
  }
  miningBreakdown: {
    sold: number
    corpProject: number
    industry: number
    unknown: number
  }
  expensesByType: Record<string, number>
  iskPerDay: number
  pvePercent: number
  miningPercent: number
  lastSync: {
    pve: string | null
    mining: string | null
  }
  settings: {
    corpProjectAccounting: 'pve' | 'mining'
  }
}

export interface LedgerDailyStats {
  period: {
    from: string
    to: string
    days: number
  }
  daily: {
    date: string
    total: number
    pve: number
    mining: number
    expenses: number
    profit: number
  }[]
}

export interface MiningEntry {
  id: string
  characterId: number
  characterName: string
  date: string
  typeId: number
  typeName: string
  solarSystemId: number
  solarSystemName: string
  quantity: number
  unitPrice: number | null
  totalValue: number | null
  // Compressed ore data
  compressedTypeId: number | null
  compressedTypeName: string | null
  compressedUnitPrice: number | null
  compressedEquivalentPrice: number | null // Price per raw unit based on compressed price
  // Structure prices
  structureUnitPrice: number | null
  structureCompressedUnitPrice: number | null
  // Reprocess value
  reprocessValue: number | null
  // Structure reprocess value
  structureReprocessValue: number | null
  usage: 'unknown' | 'sold' | 'corp_project' | 'industry'
  linkedProjectId: string | null
  linkedCorpProjectId: number | null
  syncedAt: string
}

export interface MiningStats {
  period: {
    from: string
    to: string
    days: number
  }
  totals: {
    totalValue: number
    totalQuantity: number
  }
  byUsage: Record<string, { usage: string; totalValue: number; totalQuantity: number }>
  iskPerDay: number
}

export interface MiningDailyStats {
  period: {
    from: string
    to: string
    days: number
  }
  daily: {
    date: string
    totalValue: number
    totalQuantity: number
  }[]
}

export interface MiningStatsByType {
  period: {
    from: string
    to: string
    days: number
  }
  byType: {
    typeId: number
    typeName: string
    totalValue: number
    totalQuantity: number
  }[]
}

export interface LedgerSettings {
  corpProjectAccounting: 'pve' | 'mining'
  autoSyncEnabled: boolean
  lastMiningSyncAt: string | null
  excludedTypeIds: number[]
  defaultSoldTypeIds: number[]
  updatedAt: string
}

export interface MiningSyncResult {
  status: string
  message: string
  imported: {
    entries: number
    updated: number
    pricesUpdated: number
  }
  lastSyncAt: string | null
  errors: string[]
}

export const useLedgerStore = defineStore('ledger', () => {
  // State
  const dashboard = ref<LedgerDashboard | null>(null)
  const dailyStats = ref<LedgerDailyStats | null>(null)
  const miningEntries = ref<MiningEntry[]>([])
  const miningStats = ref<MiningStats | null>(null)
  const miningDailyStats = ref<MiningDailyStats | null>(null)
  const miningStatsByType = ref<MiningStatsByType | null>(null)
  const settings = ref<LedgerSettings | null>(null)

  const isLoading = ref(false)
  const isSyncing = ref(false)
  const error = ref<string | null>(null)
  const selectedDays = ref(30)

  // Computed
  const hasData = computed(() => dashboard.value !== null)
  const hasMiningData = computed(() => miningEntries.value.length > 0 || miningStats.value !== null)

  // Actions
  async function fetchDashboard(days?: number) {
    isLoading.value = true
    error.value = null

    try {
      const d = days ?? selectedDays.value
      dashboard.value = await apiRequest<LedgerDashboard>(`/ledger/dashboard?days=${d}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch dashboard'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchDailyStats(days?: number) {
    error.value = null

    try {
      const d = days ?? selectedDays.value
      dailyStats.value = await apiRequest<LedgerDailyStats>(`/ledger/stats/daily?days=${d}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch daily stats'
    }
  }

  async function fetchMiningEntries(days?: number, typeId?: number, usage?: string, structureId?: number | null, reprocessYield?: number, exportTax?: number) {
    isLoading.value = true
    error.value = null

    try {
      const d = days ?? selectedDays.value
      let url = `/ledger/mining/entries?days=${d}`
      if (typeId) url += `&typeId=${typeId}`
      if (usage) url += `&usage=${usage}`
      if (structureId) url += `&structureId=${structureId}`
      if (reprocessYield) url += `&reprocessYield=${reprocessYield}`
      if (exportTax !== undefined) url += `&exportTax=${exportTax}`

      miningEntries.value = await apiRequest<MiningEntry[]>(url)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch mining entries'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchMiningStats(days?: number) {
    error.value = null

    try {
      const d = days ?? selectedDays.value
      miningStats.value = await apiRequest<MiningStats>(`/ledger/mining/stats?days=${d}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch mining stats'
    }
  }

  async function fetchMiningDailyStats(days?: number) {
    error.value = null

    try {
      const d = days ?? selectedDays.value
      miningDailyStats.value = await apiRequest<MiningDailyStats>(`/ledger/mining/stats/daily?days=${d}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch mining daily stats'
    }
  }

  async function fetchMiningStatsByType(days?: number) {
    error.value = null

    try {
      const d = days ?? selectedDays.value
      miningStatsByType.value = await apiRequest<MiningStatsByType>(`/ledger/mining/stats/by-type?days=${d}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch mining stats by type'
    }
  }

  async function fetchSettings() {
    error.value = null

    try {
      settings.value = await apiRequest<LedgerSettings>('/ledger/settings')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch settings'
    }
  }

  async function updateSettings(updates: Partial<LedgerSettings>) {
    error.value = null

    try {
      settings.value = await apiRequest<LedgerSettings>('/ledger/settings', {
        method: 'PATCH',
        body: JSON.stringify(updates),
      })
      // Refresh dashboard after settings change
      await fetchDashboard()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update settings'
      throw e
    }
  }

  async function updateMiningEntryUsage(entryId: string, usage: MiningEntry['usage'], linkedProjectId?: string | null, linkedCorpProjectId?: number | null) {
    error.value = null

    try {
      const body: Record<string, unknown> = { usage }
      if (linkedProjectId !== undefined) body.linkedProjectId = linkedProjectId
      if (linkedCorpProjectId !== undefined) body.linkedCorpProjectId = linkedCorpProjectId

      const updated = await apiRequest<MiningEntry>(`/ledger/mining/entries/${entryId}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      })

      // Update local state - use splice to trigger Vue reactivity
      const index = miningEntries.value.findIndex(e => e.id === entryId)
      if (index !== -1) {
        miningEntries.value.splice(index, 1, updated)
      }

      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update entry'
      throw e
    }
  }

  async function syncMining() {
    isSyncing.value = true
    error.value = null

    try {
      const result = await apiRequest<MiningSyncResult>('/ledger/mining/sync', {
        method: 'POST',
        body: JSON.stringify({}),
      })

      // Refresh data after sync
      await Promise.all([
        fetchDashboard(),
        fetchMiningStats(),
        fetchMiningEntries(),
      ])

      return result
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to sync mining data'
      throw e
    } finally {
      isSyncing.value = false
    }
  }

  function setSelectedDays(days: number) {
    selectedDays.value = days
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    dashboard,
    dailyStats,
    miningEntries,
    miningStats,
    miningDailyStats,
    miningStatsByType,
    settings,
    isLoading,
    isSyncing,
    error,
    selectedDays,

    // Computed
    hasData,
    hasMiningData,

    // Actions
    fetchDashboard,
    fetchDailyStats,
    fetchMiningEntries,
    fetchMiningStats,
    fetchMiningDailyStats,
    fetchMiningStatsByType,
    fetchSettings,
    updateSettings,
    updateMiningEntryUsage,
    syncMining,
    setSelectedDays,
    clearError,
  }
})

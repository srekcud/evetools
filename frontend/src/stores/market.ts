import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export interface MarketSearchItem {
  typeId: number
  typeName: string
  groupName: string
  categoryName: string
  jitaSell: number | null
  jitaBuy: number | null
  spread: number | null
  avgDailyVolume: number | null
  change30d: number | null
}

export interface MarketTypeDetail {
  typeId: number
  typeName: string
  groupName: string
  categoryName: string
  jitaSell: number | null
  jitaBuy: number | null
  spread: number | null
  sellOrders: MarketOrder[]
  buyOrders: MarketOrder[]
  structureSellOrders: MarketOrder[]
  structureBuyOrders: MarketOrder[]
  structureSell: number | null
  structureBuy: number | null
  structureName: string | null
  hasPreferredStructure: boolean
  avgDailyVolume: number | null
  change30d: number | null
  isFavorite: boolean
}

export interface MarketOrder {
  price: number
  volume: number
}

export interface HistoryEntry {
  date: string
  average: number
  highest: number
  lowest: number
  orderCount: number
  volume: number
  sellMin?: number | null
  buyMax?: number | null
  sellVolume?: number | null
  buyVolume?: number | null
}

export interface MarketFavorite {
  typeId: number
  typeName: string
  jitaSell: number | null
  jitaBuy: number | null
  change30d: number | null
  createdAt: string
}

export interface MarketAlert {
  id: string
  typeId: number
  typeName: string
  direction: 'above' | 'below'
  threshold: number
  priceSource: string
  status: 'active' | 'triggered' | 'expired'
  currentPrice: number | null
  triggeredAt: string | null
  createdAt: string
}

export interface CreateAlertInput {
  typeId: number
  direction: 'above' | 'below'
  threshold: number
  priceSource: string
}

export interface MarketGroup {
  id: number
  name: string
  parentId: number | null
  hasChildren: boolean
  hasTypes: boolean
}

export interface RecentSearchEntry {
  typeId: number
  typeName: string
}

const RECENT_SEARCHES_KEY = 'market-recent-searches'
const MAX_RECENT_SEARCHES = 10
const EXCLUDED_GROUPS_KEY = 'evetools_market_excluded_groups'

function loadRecentSearches(): RecentSearchEntry[] {
  try {
    const raw = localStorage.getItem(RECENT_SEARCHES_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    return parsed.filter(
      (item: unknown): item is RecentSearchEntry =>
        typeof item === 'object' &&
        item !== null &&
        typeof (item as RecentSearchEntry).typeId === 'number' &&
        typeof (item as RecentSearchEntry).typeName === 'string'
    ).slice(0, MAX_RECENT_SEARCHES)
  } catch {
    return []
  }
}

function saveRecentSearches(entries: RecentSearchEntry[]): void {
  try {
    localStorage.setItem(RECENT_SEARCHES_KEY, JSON.stringify(entries))
  } catch {
    // localStorage might be full or unavailable
  }
}

function loadExcludedGroups(): Set<number> {
  try {
    const raw = localStorage.getItem(EXCLUDED_GROUPS_KEY)
    if (!raw) return new Set()
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return new Set()
    return new Set(parsed.filter((v: unknown): v is number => typeof v === 'number'))
  } catch {
    return new Set()
  }
}

function saveExcludedGroups(groups: Set<number>): void {
  try {
    localStorage.setItem(EXCLUDED_GROUPS_KEY, JSON.stringify([...groups]))
  } catch {
    // localStorage might be full or unavailable
  }
}

export const useMarketStore = defineStore('market', () => {
  // State
  const searchResults = ref<MarketSearchItem[]>([])
  const typeDetail = ref<MarketTypeDetail | null>(null)
  const history = ref<HistoryEntry[]>([])
  const favorites = ref<MarketFavorite[]>([])
  const alerts = ref<MarketAlert[]>([])
  const searchQuery = ref('')
  const selectedTypeId = ref<number | null>(null)
  const historyDays = ref(30)
  const historySource = ref<'jita' | 'structure'>('jita')
  const isLoading = ref(false)
  const isSearching = ref(false)
  const error = ref<string | null>(null)

  // Recent searches
  const recentSearches = ref<RecentSearchEntry[]>(loadRecentSearches())

  // Category filter
  const rootGroups = ref<MarketGroup[]>([])
  const excludedGroupIds = ref<Set<number>>(loadExcludedGroups())
  const includedGroupId = ref<number | null>(null)

  // Actions
  async function searchItems(q: string): Promise<void> {
    if (!q || q.length < 2) {
      searchResults.value = []
      return
    }

    isSearching.value = true
    error.value = null

    try {
      const data = await apiRequest<{ results: MarketSearchItem[] }>(
        `/market/search?q=${encodeURIComponent(q)}`
      )
      searchResults.value = data.results ?? []
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Search failed'
      searchResults.value = []
    } finally {
      isSearching.value = false
    }
  }

  async function fetchTypeDetail(typeId: number): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      typeDetail.value = await apiRequest<MarketTypeDetail>(
        `/market/types/${typeId}`
      )
      selectedTypeId.value = typeId
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load item details'
      typeDetail.value = null
    } finally {
      isLoading.value = false
    }
  }

  async function fetchHistory(typeId: number, days: number, source: 'jita' | 'structure' = 'jita'): Promise<void> {
    error.value = null

    try {
      const data = await apiRequest<{ entries: HistoryEntry[], source: string, structureId?: number, structureName?: string }>(
        `/market/types/${typeId}/history?days=${days}&source=${source}`
      )
      history.value = data.entries ?? []
      historyDays.value = days
      historySource.value = source
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load price history'
      history.value = []
    }
  }

  async function fetchFavorites(): Promise<void> {
    error.value = null

    try {
      favorites.value = await apiRequest<MarketFavorite[]>('/market/favorites')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load favorites'
    }
  }

  async function addFavorite(typeId: number): Promise<void> {
    error.value = null

    try {
      await apiRequest('/market/favorites', {
        method: 'POST',
        body: JSON.stringify({ typeId }),
      })

      // Update detail view if showing this type
      if (typeDetail.value && typeDetail.value.typeId === typeId) {
        typeDetail.value = { ...typeDetail.value, isFavorite: true }
      }

      // Refresh favorites list
      await fetchFavorites()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to add favorite'
      throw e
    }
  }

  async function removeFavorite(typeId: number): Promise<void> {
    error.value = null

    try {
      await apiRequest(`/market/favorites/${typeId}`, { method: 'DELETE' })

      // Update detail view if showing this type
      if (typeDetail.value && typeDetail.value.typeId === typeId) {
        typeDetail.value = { ...typeDetail.value, isFavorite: false }
      }

      // Remove from local list
      favorites.value = favorites.value.filter(f => f.typeId !== typeId)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to remove favorite'
      throw e
    }
  }

  async function fetchAlerts(): Promise<void> {
    error.value = null

    try {
      alerts.value = await apiRequest<MarketAlert[]>('/market/alerts')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load alerts'
    }
  }

  async function createAlert(input: CreateAlertInput): Promise<void> {
    error.value = null

    try {
      const created = await apiRequest<MarketAlert>('/market/alerts', {
        method: 'POST',
        body: JSON.stringify(input),
      })
      alerts.value.unshift(created)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to create alert'
      throw e
    }
  }

  async function deleteAlert(id: string): Promise<void> {
    error.value = null

    try {
      await apiRequest(`/market/alerts/${id}`, { method: 'DELETE' })
      alerts.value = alerts.value.filter(a => a.id !== id)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete alert'
      throw e
    }
  }

  // Recent searches
  function addRecentSearch(typeId: number, typeName: string): void {
    const filtered = recentSearches.value.filter(entry => entry.typeId !== typeId)
    recentSearches.value = [{ typeId, typeName }, ...filtered].slice(0, MAX_RECENT_SEARCHES)
    saveRecentSearches(recentSearches.value)
  }

  function removeRecentSearch(typeId: number): void {
    recentSearches.value = recentSearches.value.filter(entry => entry.typeId !== typeId)
    saveRecentSearches(recentSearches.value)
  }

  function clearRecentSearches(): void {
    recentSearches.value = []
    saveRecentSearches([])
  }

  // Category groups
  async function fetchRootGroups(): Promise<void> {
    try {
      rootGroups.value = await apiRequest<MarketGroup[]>('/market/groups')
    } catch {
      // Non-blocking: category pills are optional UI
      rootGroups.value = []
    }
  }

  function toggleGroupExclusion(groupId: number): void {
    const newSet = new Set(excludedGroupIds.value)
    if (newSet.has(groupId)) {
      newSet.delete(groupId)
    } else {
      newSet.add(groupId)
    }
    excludedGroupIds.value = newSet
    saveExcludedGroups(newSet)
  }

  function setIncludedGroup(groupId: number | null): void {
    includedGroupId.value = groupId
  }

  function resetGroupFilters(): void {
    includedGroupId.value = null
    excludedGroupIds.value = new Set()
    saveExcludedGroups(new Set())
  }

  function clearError(): void {
    error.value = null
  }

  function clearDetail(): void {
    typeDetail.value = null
    selectedTypeId.value = null
    history.value = []
  }

  return {
    // State
    searchResults,
    typeDetail,
    history,
    favorites,
    alerts,
    searchQuery,
    selectedTypeId,
    historyDays,
    historySource,
    isLoading,
    isSearching,
    error,
    recentSearches,
    rootGroups,
    excludedGroupIds,
    includedGroupId,

    // Actions
    searchItems,
    fetchTypeDetail,
    fetchHistory,
    fetchFavorites,
    addFavorite,
    removeFavorite,
    fetchAlerts,
    createAlert,
    deleteAlert,
    clearError,
    clearDetail,
    addRecentSearch,
    removeRecentSearch,
    clearRecentSearches,
    fetchRootGroups,
    toggleGroupExclusion,
    setIncludedGroup,
    resetGroupFilters,
  }
})

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'

// Types
export interface ProfitSummary {
  totalProfit: number
  totalRevenue: number
  totalCost: number
  avgMargin: number
  itemCount: number
  bestItem: { typeName: string; profit: number } | null
  worstItem: { typeName: string; profit: number } | null
}

export interface ProfitItem {
  productTypeId: number
  typeName: string
  quantityProduced: number
  quantitySold: number
  materialCost: number
  jobInstallCost: number
  taxAmount: number
  totalCost: number
  revenue: number
  profit: number
  marginPercent: number
  lastSaleDate: string | null
}

export interface ProfitMatch {
  id: string
  jobRuns: number
  quantitySold: number
  materialCost: number
  jobInstallCost: number
  taxAmount: number
  revenue: number
  profit: number
  costSource: string
  matchedAt: string
}

export interface MarginTrendEntry {
  date: string
  profit: number
  revenue: number
  cost: number
  marginPercent: number
}

export interface ProfitItemDetail {
  productTypeId: number
  typeName: string
  costBreakdown: {
    materialCost: number
    jobInstallCost: number
    taxAmount: number
    totalCost: number
  }
  matches: ProfitMatch[]
  marginTrend: MarginTrendEntry[]
}

export interface UnmatchedJob {
  jobId: number
  productTypeId: number
  typeName: string
  runs: number
  completedDate: string
  cost: number
}

export interface UnmatchedSale {
  transactionId: number
  typeId: number
  typeName: string
  quantity: number
  unitPrice: number
  date: string
}

export interface UnmatchedData {
  unmatchedJobs: UnmatchedJob[]
  unmatchedSales: UnmatchedSale[]
}

export interface ProfitSettings {
  salesTaxRate: number
  defaultCostSource: string
}

export type SortField = 'typeName' | 'quantityProduced' | 'quantitySold' | 'materialCost' | 'jobInstallCost' | 'taxAmount' | 'totalCost' | 'revenue' | 'profit' | 'marginPercent' | 'lastSaleDate'
export type SortOrder = 'asc' | 'desc'
export type FilterType = 'all' | 'profit' | 'loss'

export const useProfitTrackerStore = defineStore('profitTracker', () => {
  // State
  const summary = ref<ProfitSummary | null>(null)
  const items = ref<ProfitItem[]>([])
  const itemDetail = ref<ProfitItemDetail | null>(null)
  const unmatched = ref<UnmatchedData | null>(null)
  const settings = ref<ProfitSettings | null>(null)

  const isLoading = ref(false)
  const isComputing = ref(false)
  const error = ref<string | null>(null)

  const selectedDays = ref(30)
  const sortBy = ref<SortField>('profit')
  const sortOrder = ref<SortOrder>('desc')
  const filter = ref<FilterType>('all')
  const selectedTypeId = ref<number | null>(null)

  // Computed
  const hasData = computed(() => summary.value !== null)
  const unmatchedCount = computed(() => {
    if (!unmatched.value) return 0
    return unmatched.value.unmatchedJobs.length + unmatched.value.unmatchedSales.length
  })

  const filteredItems = computed(() => {
    let result = [...items.value]

    if (filter.value === 'profit') {
      result = result.filter(i => i.profit > 0)
    } else if (filter.value === 'loss') {
      result = result.filter(i => i.profit < 0)
    }

    return result
  })

  // Aggregated profit per item for the overview trend chart
  const profitTrendData = computed(() => {
    return items.value
      .filter(i => i.quantitySold > 0)
      .sort((a, b) => b.profit - a.profit)
      .map(i => ({
        label: i.typeName,
        profit: i.profit,
      }))
  })

  // Actions
  async function fetchSummary(days?: number): Promise<void> {
    error.value = null
    try {
      const d = days ?? selectedDays.value
      summary.value = await apiRequest<ProfitSummary>(`/profit-tracker/summary?days=${d}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch summary'
    }
  }

  async function fetchItems(days?: number): Promise<void> {
    error.value = null
    try {
      const d = days ?? selectedDays.value
      items.value = await apiRequest<ProfitItem[]>(
        `/profit-tracker/items?days=${d}&sort=${sortBy.value}&order=${sortOrder.value}&filter=${filter.value}`
      )
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch items'
    }
  }

  async function fetchItemDetail(typeId: number, days?: number): Promise<void> {
    error.value = null
    try {
      const d = days ?? selectedDays.value
      itemDetail.value = await apiRequest<ProfitItemDetail>(`/profit-tracker/items/${typeId}?days=${d}`)
      selectedTypeId.value = typeId
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch item detail'
    }
  }

  async function fetchUnmatched(days?: number): Promise<void> {
    error.value = null
    try {
      const d = days ?? selectedDays.value
      unmatched.value = await apiRequest<UnmatchedData>(`/profit-tracker/unmatched?days=${d}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch unmatched data'
    }
  }

  async function triggerCompute(days?: number): Promise<void> {
    isComputing.value = true
    error.value = null
    try {
      const d = days ?? selectedDays.value
      await apiRequest(`/profit-tracker/compute?days=${d}`, {
        method: 'POST',
        body: JSON.stringify({}),
      })
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to trigger compute'
    } finally {
      isComputing.value = false
    }
  }

  async function fetchSettings(): Promise<void> {
    error.value = null
    try {
      settings.value = await apiRequest<ProfitSettings>('/profit-tracker/settings')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch settings'
    }
  }

  async function updateSettings(updates: Partial<ProfitSettings>): Promise<void> {
    error.value = null
    try {
      settings.value = await apiRequest<ProfitSettings>('/profit-tracker/settings', {
        method: 'PATCH',
        body: JSON.stringify(updates),
      })
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update settings'
      throw e
    }
  }

  async function fetchAll(days?: number): Promise<void> {
    isLoading.value = true
    try {
      await Promise.all([
        fetchSummary(days),
        fetchItems(days),
        fetchUnmatched(days),
        fetchSettings(),
      ])
    } finally {
      isLoading.value = false
    }
  }

  function setSort(field: SortField): void {
    if (sortBy.value === field) {
      sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
    } else {
      sortBy.value = field
      sortOrder.value = 'desc'
    }
  }

  function setFilter(newFilter: FilterType): void {
    filter.value = newFilter
  }

  function setSelectedDays(days: number): void {
    selectedDays.value = days
  }

  function clearItemDetail(): void {
    itemDetail.value = null
    selectedTypeId.value = null
  }

  function clearError(): void {
    error.value = null
  }

  return {
    // State
    summary,
    items,
    itemDetail,
    unmatched,
    settings,
    isLoading,
    isComputing,
    error,
    selectedDays,
    sortBy,
    sortOrder,
    filter,
    selectedTypeId,

    // Computed
    hasData,
    unmatchedCount,
    filteredItems,
    profitTrendData,

    // Actions
    fetchSummary,
    fetchItems,
    fetchItemDetail,
    fetchUnmatched,
    triggerCompute,
    fetchSettings,
    updateSettings,
    fetchAll,
    setSort,
    setFilter,
    setSelectedDays,
    clearItemDetail,
    clearError,
  }
})

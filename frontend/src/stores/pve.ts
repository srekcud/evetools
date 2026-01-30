import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export interface PveStats {
  period: {
    from: string
    to: string
    days: number
  }
  totals: {
    income: number
    bounties: number
    ess: number
    missions: number
    lootSales: number
    expenses: number
    profit: number
  }
  expensesByType: {
    fuel?: number
    ammo?: number
    crab_beacon?: number
    other?: number
  }
  iskPerDay: number
}

export interface DailyStats {
  date: string
  income: number
  bounties: number
  lootSales: number
  expenses: number
  profit: number
}

export const usePveStore = defineStore('pve', () => {
  // State
  const stats = ref<PveStats | null>(null)
  const dailyStats = ref<DailyStats[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const selectedDays = ref(30)

  // Actions
  async function fetchStats(days?: number) {
    isLoading.value = true
    error.value = null

    try {
      const d = days ?? selectedDays.value
      stats.value = await apiRequest<PveStats>(`/pve/stats?days=${d}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch stats'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchDailyStats(days?: number) {
    error.value = null

    try {
      const d = days ?? selectedDays.value
      const result = await apiRequest<{ daily: DailyStats[] }>(
        `/pve/stats/daily?days=${d}`
      )
      dailyStats.value = result.daily
    } catch (e) {
      error.value =
        e instanceof Error ? e.message : 'Failed to fetch daily stats'
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
    stats,
    dailyStats,
    isLoading,
    error,
    selectedDays,

    // Actions
    fetchStats,
    fetchDailyStats,
    setSelectedDays,
    clearError,
  }
})

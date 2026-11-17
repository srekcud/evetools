import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export const PVE_GRID_COLUMN_IDS = ['bounties', 'ess', 'missions', 'lootSales', 'escalations', 'expenses'] as const

export type PveGridColumnId = (typeof PVE_GRID_COLUMN_IDS)[number]

export interface PveGridColumn {
  value: number
  previous: number
  change: number | null
  daily: Record<string, number>
  contributors: Array<{ name: string; value: number }> | null
}

export interface PveGrid {
  period: { from: string; to: string; days: number }
  columns: Record<PveGridColumnId, PveGridColumn>
  net: { value: number; previous: number; change: number | null; daily: Record<string, number> }
}

export const usePveStore = defineStore('pve', () => {
  const grid = ref<PveGrid | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function fetchGrid(days: number) {
    isLoading.value = true
    error.value = null

    try {
      grid.value = await apiRequest<PveGrid>(`/pve/stats/grid?days=${days}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch PVE revenue'
    } finally {
      isLoading.value = false
    }
  }

  function clearError() {
    error.value = null
  }

  return { grid, isLoading, error, fetchGrid, clearError }
})

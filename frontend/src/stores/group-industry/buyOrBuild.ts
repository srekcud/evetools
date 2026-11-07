import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { BuyOrBuildRow, ProjectBuyOrBuild } from './types'

export const useGroupBuyOrBuildStore = defineStore('group-buy-or-build', () => {
  const rows = ref<BuyOrBuildRow[]>([])
  const importIskPerCubicMeterJump = ref<number | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchBuyOrBuild(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      rows.value = []
      importIskPerCubicMeterJump.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const comparison = await apiRequest<ProjectBuyOrBuild>(`/industry/projects/${projectId}/buy-or-build`)
      if (request === latestRequest) {
        rows.value = comparison.rows
        importIskPerCubicMeterJump.value = comparison.importIskPerCubicMeterJump
      }
    } catch (e) {
      if (request === latestRequest) {
        rows.value = []
        importIskPerCubicMeterJump.value = null
        error.value = e instanceof Error ? e.message : 'Failed to fetch buy or build comparison'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { rows, importIskPerCubicMeterJump, loading, error, fetchBuyOrBuild }
})

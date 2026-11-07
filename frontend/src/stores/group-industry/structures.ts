import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectStructures, StructureComparisonGroup } from './types'

export const useGroupStructuresStore = defineStore('group-structures', () => {
  const groups = ref<StructureComparisonGroup[]>([])
  const acceptsOutsideFavoriteSystem = ref(false)
  const minimumOutsideSaving = ref(0)
  const transportIskPerCubicMeterJump = ref<number | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  function clear(): void {
    groups.value = []
    acceptsOutsideFavoriteSystem.value = false
    minimumOutsideSaving.value = 0
    transportIskPerCubicMeterJump.value = null
  }

  async function fetchStructures(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      clear()
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const comparison = await apiRequest<ProjectStructures>(`/industry/projects/${projectId}/structures`)
      if (request === latestRequest) {
        groups.value = comparison.groups
        acceptsOutsideFavoriteSystem.value = comparison.acceptsOutsideFavoriteSystem
        minimumOutsideSaving.value = comparison.minimumOutsideSaving
        transportIskPerCubicMeterJump.value = comparison.transportIskPerCubicMeterJump
      }
    } catch (e) {
      if (request === latestRequest) {
        clear()
        error.value = e instanceof Error ? e.message : 'Failed to fetch structure comparison'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  return { groups, acceptsOutsideFavoriteSystem, minimumOutsideSaving, transportIskPerCubicMeterJump, loading, error, fetchStructures }
})

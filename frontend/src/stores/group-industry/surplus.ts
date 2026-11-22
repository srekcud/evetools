import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectSurplus, ProjectSurplusLine } from './types'

export const useGroupSurplusStore = defineStore('group-surplus', () => {
  const lines = ref<ProjectSurplusLine[]>([])
  const totalValue = ref<number | null>(null)
  const stored = ref(false)
  const storedAt = ref<string | null>(null)
  const storedBy = ref<string | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  function apply(surplus: ProjectSurplus): void {
    if (typeof surplus.stored !== 'boolean') {
      throw new Error('Unexpected surplus answer: stored is missing')
    }
    lines.value = surplus.lines
    totalValue.value = surplus.totalValue
    stored.value = surplus.stored
    storedAt.value = surplus.storedAt
    storedBy.value = surplus.storedBy
  }

  async function fetchSurplus(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      lines.value = []
      totalValue.value = null
      stored.value = false
      storedAt.value = null
      storedBy.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const surplus = await apiRequest<ProjectSurplus>(`/industry/projects/${projectId}/surplus`)
      if (request === latestRequest) {
        apply(surplus)
      }
    } catch (e) {
      if (request === latestRequest) {
        lines.value = []
        totalValue.value = null
        stored.value = false
        storedAt.value = null
        storedBy.value = null
        error.value = e instanceof Error ? e.message : 'Failed to read the material surplus'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  async function store(projectId: string): Promise<void> {
    error.value = null
    try {
      apply(await apiRequest<ProjectSurplus>(`/industry/projects/${projectId}/surplus-storage`, { method: 'POST' }))
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to store the surplus'
    }
  }

  return { lines, totalValue, stored, storedAt, storedBy, loading, error, fetchSurplus, store }
})

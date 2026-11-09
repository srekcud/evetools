import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectSettlement, ProjectTransfer } from './types'

function settlementPath(projectId: string): string {
  return `/industry/projects/${projectId}/settlement`
}

function receiptPath(projectId: string, transfer: ProjectTransfer): string {
  return `/industry/projects/${projectId}/transfers/${transfer.fromParticipantId}/${transfer.toParticipantId}/receipt`
}

export const useGroupSettlementStore = defineStore('group-settlement', () => {
  const settlement = ref<ProjectSettlement | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchForProject(projectId: string): Promise<void> {
    const request = ++latestRequest
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      settlement.value = null
    }
    loading.value = true
    error.value = null
    try {
      const fetched = await apiRequest<ProjectSettlement>(settlementPath(projectId))
      if (request !== latestRequest) return
      settlement.value = fetched
    } catch (e) {
      if (request !== latestRequest) return
      settlement.value = null
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }

  async function setReceived(projectId: string, transfer: ProjectTransfer, received: boolean): Promise<void> {
    error.value = null
    try {
      settlement.value = await apiRequest<ProjectSettlement>(receiptPath(projectId, transfer), {
        method: 'POST',
        body: JSON.stringify({ received }),
      })
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
    }
  }

  return { settlement, loading, error, fetchForProject, setReceived }
})

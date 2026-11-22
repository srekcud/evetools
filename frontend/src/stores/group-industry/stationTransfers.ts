import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { DeclareStationTransferInput, ProjectStationTransfer } from './types'

function stationTransfersPath(projectId: string): string {
  return `/industry/projects/${projectId}/station-transfers`
}

export const useGroupStationTransfersStore = defineStore('group-station-transfers', () => {
  const transfers = ref<ProjectStationTransfer[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchStationTransfers(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      transfers.value = []
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const read = await apiRequest<ProjectStationTransfer[]>(stationTransfersPath(projectId))
      if (request === latestRequest) {
        transfers.value = read
      }
    } catch (e) {
      if (request === latestRequest) {
        transfers.value = []
        error.value = e instanceof Error ? e.message : 'Failed to fetch station transfers'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  async function declareStationTransfer(projectId: string, input: DeclareStationTransferInput): Promise<void> {
    await apiRequest<ProjectStationTransfer>(stationTransfersPath(projectId), {
      method: 'POST',
      body: JSON.stringify(input),
    })
    await fetchStationTransfers(projectId)
  }

  async function markStationTransferArrived(projectId: string, transferId: string): Promise<void> {
    await apiRequest<ProjectStationTransfer>(`${stationTransfersPath(projectId)}/${transferId}/arrive`, {
      method: 'POST',
      body: '{}',
    })
    await fetchStationTransfers(projectId)
  }

  async function deleteStationTransfer(projectId: string, transferId: string): Promise<void> {
    await apiRequest<void>(`${stationTransfersPath(projectId)}/${transferId}`, { method: 'DELETE' })
    await fetchStationTransfers(projectId)
  }

  return { transfers, loading, error, fetchStationTransfers, declareStationTransfer, markStationTransferArrived, deleteStationTransfer }
})

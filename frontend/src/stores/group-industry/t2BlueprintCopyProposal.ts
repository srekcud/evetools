import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from './project'
import type { T2BlueprintCopyProposal, T2BlueprintCopySelection } from './types'

export const useGroupT2BlueprintCopyProposalStore = defineStore('group-t2-blueprint-copy-proposal', () => {
  const proposal = ref<T2BlueprintCopyProposal | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const declarationError = ref<string | null>(null)
  const declaring = ref(false)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchProposal(projectId: string): Promise<void> {
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      proposal.value = null
    }
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const read = await apiRequest<T2BlueprintCopyProposal | undefined>(`/industry/projects/${projectId}/t2-blueprint-copy-proposal`)
      if (request === latestRequest) {
        proposal.value = read ?? null
      }
    } catch (e) {
      if (request === latestRequest) {
        proposal.value = null
        error.value = e instanceof Error ? e.message : 'Failed to read the T2 blueprint copy proposal'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  async function declare(projectId: string, selections: T2BlueprintCopySelection[]): Promise<void> {
    declaring.value = true
    declarationError.value = null
    try {
      await apiRequest(`/industry/projects/${projectId}/t2-blueprint-copies`, {
        method: 'POST',
        body: JSON.stringify({ copies: selections }),
      })
      await Promise.all([fetchProposal(projectId), useGroupProjectStore().fetchBom(projectId)])
    } catch (e) {
      declarationError.value = e instanceof Error ? e.message : 'Failed to declare the T2 blueprint copies'
      await fetchProposal(projectId)
    } finally {
      declaring.value = false
    }
  }

  return { proposal, loading, error, declarationError, declaring, fetchProposal, declare }
})

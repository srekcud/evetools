import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { useGroupContributionStore } from './contribution'
import { useGroupProjectStore } from './project'
import type { IntermediateStockProposal, IntermediateStockSelection } from './types'

export const useGroupIntermediateStockProposalStore = defineStore('group-intermediate-stock-proposal', () => {
  const proposal = ref<IntermediateStockProposal | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const validationError = ref<string | null>(null)
  const validating = ref(false)
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
      const read = await apiRequest<IntermediateStockProposal>(`/industry/projects/${projectId}/intermediate-stock-proposal`)
      if (request === latestRequest) {
        proposal.value = read
      }
    } catch (e) {
      if (request === latestRequest) {
        proposal.value = null
        error.value = e instanceof Error ? e.message : 'Failed to read the stock proposal'
      }
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  async function validate(projectId: string, selections: IntermediateStockSelection[]): Promise<void> {
    const contributionStore = useGroupContributionStore()
    validating.value = true
    validationError.value = null
    let posted = 0
    try {
      for (const selection of selections) {
        let quantity = selection.quantity
        if (posted > 0) {
          await fetchProposal(projectId)
          if (proposal.value === null) {
            throw new Error(error.value ?? 'Failed to read the stock proposal')
          }
          const line = proposal.value.lines.find(candidate => candidate.bomItemId === selection.bomItemId)
          if (line === undefined) {
            continue
          }
          quantity = Math.min(quantity, line.proposedQuantity)
        }
        await contributionStore.submitContribution(projectId, { bomItemId: selection.bomItemId, type: 'material', quantity })
        posted += 1
      }
      await fetchProposal(projectId)
    } catch (e) {
      validationError.value = e instanceof Error ? e.message : 'Failed to declare the stock'
      await Promise.all([fetchProposal(projectId), useGroupProjectStore().fetchBom(projectId)])
    } finally {
      validating.value = false
    }
  }

  return { proposal, loading, error, validationError, validating, fetchProposal, validate }
})

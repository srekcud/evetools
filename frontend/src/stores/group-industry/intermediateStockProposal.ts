import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { IntermediateStockProposal, IntermediateStockSelection } from './types'

export const useGroupIntermediateStockProposalStore = defineStore('group-intermediate-stock-proposal', () => {
  const proposal = ref<IntermediateStockProposal | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const validationError = ref<string | null>(null)
  const validating = ref(false)

  async function fetchProposal(projectId: string): Promise<void> {
    void projectId
  }

  async function validate(projectId: string, selections: IntermediateStockSelection[]): Promise<void> {
    void projectId
    void selections
  }

  return { proposal, loading, error, validationError, validating, fetchProposal, validate }
})

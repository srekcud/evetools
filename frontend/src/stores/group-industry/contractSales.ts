import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { ContractSaleCandidate, ContractSaleSuggestions, GroupSale } from './types'

export const useGroupContractSalesStore = defineStore('group-contract-sales', () => {
  const suggestions = ref<ContractSaleSuggestions | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  let latestRequest = 0

  async function fetchForProject(projectId: string): Promise<void> {
    const request = ++latestRequest
    if (openedProjectId.value !== projectId) {
      openedProjectId.value = projectId
      suggestions.value = null
    }
    loading.value = true
    error.value = null
    try {
      const fetched = await apiRequest<ContractSaleSuggestions>(`/industry/projects/${projectId}/contract-suggestions`)
      if (request !== latestRequest) return
      suggestions.value = fetched
    } catch (e) {
      if (request !== latestRequest) return
      suggestions.value = null
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }

  async function linkContract(projectId: string, candidate: ContractSaleCandidate): Promise<GroupSale> {
    return apiRequest<GroupSale>(`/industry/projects/${projectId}/sales/from-contract`, {
      method: 'POST',
      body: JSON.stringify({ contractId: candidate.contractId, characterId: candidate.sellerCharacterId }),
    })
  }

  return { suggestions, loading, error, fetchForProject, linkContract }
})

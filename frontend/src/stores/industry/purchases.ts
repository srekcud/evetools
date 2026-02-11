import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { PurchaseSuggestion, StepPurchase } from './types'
import { formatErrorMessage } from './compat'

export const usePurchasesStore = defineStore('industry-purchases', () => {
  const suggestions = ref<PurchaseSuggestion[]>([])
  const error = ref<string | null>(null)

  async function fetchSuggestions(projectId: string) {
    try {
      const data = await apiRequest<{ suggestions: PurchaseSuggestion[], totalCount: number }>(
        `/industry/projects/${projectId}/purchase-suggestions`,
      )
      suggestions.value = data.suggestions
      return data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch suggestions'
      return null
    }
  }

  async function createPurchase(projectId: string, stepId: string, data: {
    transactionId?: string
    typeId?: number
    quantity?: number
    unitPrice?: number
  }) {
    try {
      const purchase = await apiRequest<StepPurchase>(
        `/industry/projects/${projectId}/steps/${stepId}/purchases`,
        {
          method: 'POST',
          body: JSON.stringify(data),
        },
      )
      return purchase
    } catch (e) {
      error.value = formatErrorMessage(e, 'Échec de création de l\'achat')
      throw e
    }
  }

  async function deletePurchase(projectId: string, stepId: string, purchaseId: string) {
    try {
      await apiRequest(
        `/industry/projects/${projectId}/steps/${stepId}/purchases/${purchaseId}`,
        { method: 'DELETE' },
      )
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete purchase'
      throw e
    }
  }

  return {
    suggestions,
    error,
    fetchSuggestions,
    createPurchase,
    deletePurchase,
  }
})

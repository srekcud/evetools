import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { GroupContribution, LineRentalSuggestion, SubmitContributionInput } from './types'

export const useGroupContributionStore = defineStore('group-contribution', () => {
  const contributions = ref<GroupContribution[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchContributions(projectId: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      contributions.value = await apiRequest<GroupContribution[]>(
        `/industry/projects/${projectId}/contributions`,
      )
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch contributions'
    } finally {
      loading.value = false
    }
  }

  async function submitContribution(
    projectId: string,
    input: SubmitContributionInput,
  ): Promise<GroupContribution | null> {
    error.value = null
    try {
      const contribution = await apiRequest<GroupContribution>(
        `/industry/projects/${projectId}/contributions`,
        {
          method: 'POST',
          body: JSON.stringify(input),
        },
      )
      contributions.value.unshift(contribution)
      return contribution
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to submit contribution'
      throw e
    }
  }

  async function fetchLineRentalSuggestion(
    projectId: string,
    bomItemId: string,
  ): Promise<LineRentalSuggestion> {
    return apiRequest<LineRentalSuggestion>(
      `/industry/projects/${projectId}/line-rental-suggestions/${bomItemId}`,
    )
  }

  async function approveContribution(projectId: string, id: string): Promise<void> {
    error.value = null
    try {
      const updated = await apiRequest<GroupContribution>(
        `/industry/projects/${projectId}/contributions/${id}`,
        {
          method: 'PATCH',
          body: JSON.stringify({ status: 'approved' }),
        },
      )
      const idx = contributions.value.findIndex(c => c.id === id)
      if (idx !== -1) {
        contributions.value[idx] = updated
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to approve contribution'
    }
  }

  async function rejectContribution(projectId: string, id: string): Promise<void> {
    error.value = null
    try {
      const updated = await apiRequest<GroupContribution>(
        `/industry/projects/${projectId}/contributions/${id}`,
        {
          method: 'PATCH',
          body: JSON.stringify({ status: 'rejected' }),
        },
      )
      const idx = contributions.value.findIndex(c => c.id === id)
      if (idx !== -1) {
        contributions.value[idx] = updated
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to reject contribution'
    }
  }

  async function reopenContribution(projectId: string, id: string): Promise<void> {
    error.value = null
    try {
      const updated = await apiRequest<GroupContribution>(
        `/industry/projects/${projectId}/contributions/${id}`,
        {
          method: 'PATCH',
          body: JSON.stringify({ status: 'pending' }),
        },
      )
      const idx = contributions.value.findIndex(c => c.id === id)
      if (idx !== -1) {
        contributions.value[idx] = updated
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to reopen contribution'
    }
  }

  function clearError(): void {
    error.value = null
  }

  return {
    contributions,
    loading,
    error,
    fetchContributions,
    submitContribution,
    fetchLineRentalSuggestion,
    approveContribution,
    rejectContribution,
    reopenContribution,
    clearError,
  }
})

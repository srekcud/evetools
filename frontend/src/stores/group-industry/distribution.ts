import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type {
  Distribution,
  GroupSale,
  GroupMember,
  LineRentalRates,
  RecordSaleInput,
} from './types'

export const useGroupDistributionStore = defineStore('group-distribution', () => {
  const distribution = ref<Distribution | null>(null)
  const sales = ref<GroupSale[]>([])
  const members = ref<GroupMember[]>([])
  const lineRentalRates = ref<LineRentalRates | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchDistribution(projectId: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      distribution.value = await apiRequest<Distribution>(
        `/group-industry/projects/${projectId}/distribution`,
      )
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch distribution'
    } finally {
      loading.value = false
    }
  }

  async function fetchSales(projectId: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      sales.value = await apiRequest<GroupSale[]>(
        `/group-industry/projects/${projectId}/sales`,
      )
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch sales'
    } finally {
      loading.value = false
    }
  }

  async function recordSale(projectId: string, input: RecordSaleInput): Promise<GroupSale | null> {
    error.value = null
    try {
      const sale = await apiRequest<GroupSale>(
        `/group-industry/projects/${projectId}/sales`,
        {
          method: 'POST',
          body: JSON.stringify(input),
        },
      )
      sales.value.unshift(sale)
      return sale
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to record sale'
      throw e
    }
  }

  async function fetchMembers(projectId: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      members.value = await apiRequest<GroupMember[]>(
        `/group-industry/projects/${projectId}/members`,
      )
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch members'
    } finally {
      loading.value = false
    }
  }

  async function updateMember(
    projectId: string,
    memberId: string,
    input: { role?: string; status?: string },
  ): Promise<void> {
    error.value = null
    try {
      const updated = await apiRequest<GroupMember>(
        `/group-industry/projects/${projectId}/members/${memberId}`,
        {
          method: 'PATCH',
          body: JSON.stringify(input),
        },
      )
      const idx = members.value.findIndex(m => m.id === memberId)
      if (idx !== -1) {
        members.value[idx] = updated
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update member'
    }
  }

  async function kickMember(projectId: string, memberId: string): Promise<void> {
    error.value = null
    try {
      await apiRequest(
        `/group-industry/projects/${projectId}/members/${memberId}`,
        { method: 'DELETE' },
      )
      members.value = members.value.filter(m => m.id !== memberId)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to kick member'
    }
  }

  async function leaveProject(projectId: string): Promise<boolean> {
    error.value = null
    try {
      await apiRequest(
        `/group-industry/projects/${projectId}/leave`,
        { method: 'POST' },
      )
      return true
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to leave project'
      return false
    }
  }

  async function fetchLineRentalRates(): Promise<void> {
    try {
      lineRentalRates.value = await apiRequest<LineRentalRates>(
        '/group-industry/line-rental-rates',
      )
    } catch {
      // best-effort, don't block the UI
    }
  }

  async function updateLineRentalRates(rates: Record<string, number>): Promise<void> {
    error.value = null
    try {
      lineRentalRates.value = await apiRequest<LineRentalRates>(
        '/group-industry/line-rental-rates',
        {
          method: 'PATCH',
          body: JSON.stringify({ rates }),
        },
      )
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update line rental rates'
      throw e
    }
  }

  function clearError(): void {
    error.value = null
  }

  return {
    distribution,
    sales,
    members,
    lineRentalRates,
    loading,
    error,
    fetchDistribution,
    fetchSales,
    recordSale,
    fetchMembers,
    updateMember,
    kickMember,
    leaveProject,
    fetchLineRentalRates,
    updateLineRentalRates,
    clearError,
  }
})

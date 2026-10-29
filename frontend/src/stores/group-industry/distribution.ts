import { defineStore } from 'pinia'
import { computed, ref, type Ref } from 'vue'
import { apiRequest } from '@/services/api'
import type {
  Distribution,
  GroupSale,
  GroupSaleSuggestion,
  GroupMember,
  LineRentalRates,
  RecordSaleFromWalletTransactionInput,
  RecordSaleInput,
  UpdateSaleInput,
} from './types'

export const useGroupDistributionStore = defineStore('group-distribution', () => {
  const distribution = ref<Distribution | null>(null)
  const sales = ref<GroupSale[]>([])
  const members = ref<GroupMember[]>([])
  const lineRentalRates = ref<LineRentalRates | null>(null)
  const loadingDistribution = ref(false)
  const loadingSales = ref(false)
  const loadingMembers = ref(false)
  const loading = computed(() => loadingDistribution.value || loadingSales.value || loadingMembers.value)
  const error = ref<string | null>(null)
  const distributionError = ref<string | null>(null)
  const salesError = ref<string | null>(null)
  const membersError = ref<string | null>(null)
  const openedProjectId = ref<string | null>(null)
  const saleSuggestions = ref<GroupSaleSuggestion[]>([])
  const loadingSaleSuggestions = ref(false)
  const saleSuggestionsError = ref<string | null>(null)

  function openProject(projectId: string): void {
    if (openedProjectId.value === projectId) {
      return
    }
    openedProjectId.value = projectId
    distribution.value = null
    sales.value = []
    members.value = []
    lineRentalRates.value = null
    distributionError.value = null
    salesError.value = null
    membersError.value = null
    loadingDistribution.value = false
    loadingSales.value = false
    loadingMembers.value = false
    saleSuggestions.value = []
    saleSuggestionsError.value = null
    loadingSaleSuggestions.value = false
  }

  async function fetchForProject<T>(
    projectId: string,
    resource: string,
    held: Ref<T>,
    heldWhenFailed: T,
    loadingIndicator: Ref<boolean>,
    fetchError: Ref<string | null>,
    failureMessage: string,
  ): Promise<void> {
    openProject(projectId)
    loadingIndicator.value = true
    fetchError.value = null
    try {
      const fetched = await apiRequest<T>(`/group-industry/projects/${projectId}/${resource}`)
      if (openedProjectId.value === projectId) {
        held.value = fetched
      }
    } catch (e) {
      if (openedProjectId.value === projectId) {
        held.value = heldWhenFailed
        fetchError.value = e instanceof Error ? e.message : failureMessage
      }
    } finally {
      if (openedProjectId.value === projectId) {
        loadingIndicator.value = false
      }
    }
  }

  function fetchDistribution(projectId: string): Promise<void> {
    return fetchForProject(projectId, 'distribution', distribution, null, loadingDistribution, distributionError, 'Failed to fetch distribution')
  }

  function fetchSales(projectId: string): Promise<void> {
    return fetchForProject(projectId, 'sales', sales, [], loadingSales, salesError, 'Failed to fetch sales')
  }

  async function postSale(projectId: string, resource: string, input: RecordSaleInput | RecordSaleFromWalletTransactionInput): Promise<GroupSale> {
    const sale = await apiRequest<GroupSale>(
      `/group-industry/projects/${projectId}/${resource}`,
      {
        method: 'POST',
        body: JSON.stringify(input),
      },
    )
    sales.value.unshift(sale)
    return sale
  }

  async function recordSale(projectId: string, input: RecordSaleInput): Promise<GroupSale> {
    error.value = null
    try {
      return await postSale(projectId, 'sales', input)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to record sale'
      throw e
    }
  }

  function recordSaleFromWalletTransaction(projectId: string, input: RecordSaleFromWalletTransactionInput): Promise<GroupSale> {
    return postSale(projectId, 'sales/from-wallet-transaction', input)
  }

  async function updateSale(projectId: string, saleId: string, input: UpdateSaleInput): Promise<GroupSale> {
    error.value = null
    try {
      const corrected = await apiRequest<GroupSale>(
        `/group-industry/projects/${projectId}/sales/${saleId}`,
        {
          method: 'PATCH',
          body: JSON.stringify(input),
        },
      )
      sales.value = sales.value.map(sale => (sale.id === saleId ? corrected : sale))
      return corrected
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update sale'
      throw e
    }
  }

  async function deleteSale(projectId: string, saleId: string): Promise<void> {
    error.value = null
    try {
      await apiRequest(
        `/group-industry/projects/${projectId}/sales/${saleId}`,
        { method: 'DELETE' },
      )
      sales.value = sales.value.filter(sale => sale.id !== saleId)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete sale'
      throw e
    }
  }

  function fetchSaleSuggestions(projectId: string): Promise<void> {
    return fetchForProject(projectId, 'sale-suggestions', saleSuggestions, [], loadingSaleSuggestions, saleSuggestionsError, 'Failed to fetch sale suggestions')
  }

  function fetchMembers(projectId: string): Promise<void> {
    return fetchForProject(projectId, 'members', members, [], loadingMembers, membersError, 'Failed to fetch members')
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
    loadingDistribution,
    loadingSales,
    loadingMembers,
    error,
    distributionError,
    salesError,
    membersError,
    fetchDistribution,
    fetchSales,
    recordSale,
    updateSale,
    deleteSale,
    saleSuggestions,
    loadingSaleSuggestions,
    saleSuggestionsError,
    fetchSaleSuggestions,
    recordSaleFromWalletTransaction,
    fetchMembers,
    updateMember,
    kickMember,
    leaveProject,
    fetchLineRentalRates,
    updateLineRentalRates,
    clearError,
  }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { IndustryProject, IndustryProjectStep, SearchResult, ShoppingListResponse, CostEstimation, BpcKit, CopyCosts, ProfitMarginResult } from './types'
import { enrichProject, enrichStep, formatErrorMessage } from './compat'

export const useProjectsStore = defineStore('industry-projects', () => {
  const projects = ref<IndustryProject[]>([])
  const currentProject = ref<IndustryProject | null>(null)
  const searchResults = ref<SearchResult[]>([])
  const costEstimation = ref<CostEstimation | null>(null)
  const bpcKit = ref<BpcKit | null>(null)
  const copyCosts = ref<CopyCosts | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const totalProfit = ref(0)
  const defaultMaxJobDurationDays = ref(2.0)

  function setDefaultMaxJobDurationDays(days: number) {
    defaultMaxJobDurationDays.value = days
  }

  async function searchProducts(query: string) {
    if (query.length < 2) {
      searchResults.value = []
      return
    }
    try {
      const data = await apiRequest<{ results: SearchResult[] }>(
        `/industry/search?q=${encodeURIComponent(query)}`,
      )
      searchResults.value = data.results
    } catch (e) {
      searchResults.value = []
    }
  }

  async function createProject(
    typeId: number,
    runs: number,
    meLevel: number,
    teLevel: number = 0,
    maxJobDurationDays: number = 2.0,
    name?: string | null,
  ) {
    error.value = null
    try {
      const project = enrichProject(await apiRequest<IndustryProject>('/industry/projects', {
        method: 'POST',
        body: JSON.stringify({ typeId, runs, meLevel, teLevel, maxJobDurationDays, name }),
      }))
      projects.value.unshift(project)
      return project
    } catch (e) {
      error.value = formatErrorMessage(e, 'Échec de création du projet')
      throw e
    }
  }

  async function fetchProjects() {
    isLoading.value = true
    error.value = null
    try {
      const data = await apiRequest<{
        projects: IndustryProject[]
        totalProfit: number
      }>('/industry/projects')
      projects.value = data.projects.map(enrichProject)
      totalProfit.value = data.totalProfit
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch projects'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchProject(id: string) {
    isLoading.value = true
    error.value = null
    try {
      currentProject.value =
        enrichProject(await apiRequest<IndustryProject>(`/industry/projects/${id}`))
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch project'
    } finally {
      isLoading.value = false
    }
  }

  async function updateProject(id: string, data: Partial<IndustryProject>) {
    error.value = null
    try {
      const updated = enrichProject(await apiRequest<IndustryProject>(
        `/industry/projects/${id}`,
        {
          method: 'PATCH',
          body: JSON.stringify(data),
        },
      ))
      // PATCH response from projectToResource does not include steps/tree —
      // preserve existing ones to avoid wiping loaded data.
      const { steps: _s, tree: _t, ...safeUpdated } = updated as IndustryProject & { tree?: unknown }
      const idx = projects.value.findIndex((p) => p.id === id)
      if (idx !== -1) {
        projects.value[idx] = { ...projects.value[idx], ...safeUpdated }
      }
      if (currentProject.value?.id === id) {
        currentProject.value = { ...currentProject.value, ...safeUpdated }
      }
      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update project'
      throw e
    }
  }

  async function deleteProject(id: string) {
    error.value = null
    try {
      await apiRequest(`/industry/projects/${id}`, { method: 'DELETE' })
      projects.value = projects.value.filter((p) => p.id !== id)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete project'
    }
  }

  async function matchJobs(projectId: string): Promise<string | null> {
    try {
      const data = await apiRequest<{
        steps: IndustryProject['steps']
        jobsCost: number
        syncedCharacters: number
        warning: string | null
      }>(`/industry/projects/${projectId}/match-jobs`, { method: 'POST', body: '{}' })
      if (currentProject.value?.id === projectId) {
        currentProject.value.steps = data.steps?.map(enrichStep)
        currentProject.value.jobsCost = data.jobsCost
      }
      return data.warning
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to match jobs'
      return null
    }
  }

  async function fetchShoppingList(
    projectId: string,
    structureId?: number,
    transportCost?: number,
  ): Promise<ShoppingListResponse | null> {
    try {
      const params = new URLSearchParams()
      if (structureId) params.set('structureId', structureId.toString())
      if (transportCost !== undefined) params.set('transportCost', transportCost.toString())
      const queryString = params.toString() ? `?${params.toString()}` : ''
      const data = await apiRequest<ShoppingListResponse>(
        `/industry/projects/${projectId}/shopping-list${queryString}`,
      )
      return data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch shopping list'
      return null
    }
  }

  async function applyStock(projectId: string, items: { typeName: string; quantity: number }[]) {
    try {
      const data = await apiRequest<IndustryProject['steps']>(
        `/industry/projects/${projectId}/apply-stock`,
        {
          method: 'POST',
          body: JSON.stringify({ items }),
        },
      )
      // Update steps in current project
      if (currentProject.value?.id === projectId && currentProject.value.steps && Array.isArray(data)) {
        for (const updatedStep of (data as IndustryProjectStep[]).map(enrichStep)) {
          const idx = currentProject.value.steps.findIndex(s => s.id === updatedStep.id)
          if (idx !== -1) {
            currentProject.value.steps[idx] = updatedStep
          }
        }
      }
      return data
    } catch (e) {
      error.value = formatErrorMessage(e, 'Échec de l\'application du stock')
      throw e
    }
  }

  async function adaptStock(projectId: string) {
    try {
      const data = enrichProject(await apiRequest<IndustryProject>(
        `/industry/projects/${projectId}/adapt-stock`,
        { method: 'POST', body: '{}' },
      ))
      if (currentProject.value?.id === projectId) {
        currentProject.value = data
      }
      return data
    } catch (e) {
      error.value = formatErrorMessage(e, 'Échec de l\'adaptation du plan')
      throw e
    }
  }

  async function fetchCostEstimation(projectId: string): Promise<CostEstimation | null> {
    try {
      const data = await apiRequest<CostEstimation>(
        `/industry/projects/${projectId}/cost-estimation`,
      )
      costEstimation.value = data
      return data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch cost estimation'
      return null
    }
  }

  async function fetchBpcKit(projectId: string, desiredBpcCount: number = 1): Promise<BpcKit | null> {
    try {
      const params = new URLSearchParams()
      if (desiredBpcCount > 1) params.set('desired_bpc_count', desiredBpcCount.toString())
      const queryString = params.toString() ? `?${params.toString()}` : ''
      const data = await apiRequest<BpcKit>(
        `/industry/projects/${projectId}/bpc-kit${queryString}`,
      )
      bpcKit.value = data
      return data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch BPC kit'
      return null
    }
  }

  async function fetchCopyCosts(projectId: string): Promise<CopyCosts | null> {
    try {
      const data = await apiRequest<CopyCosts>(
        `/industry/projects/${projectId}/copy-costs`,
      )
      copyCosts.value = data
      return data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch copy costs'
      return null
    }
  }

  // Profit Margin
  const marginResult = ref<ProfitMarginResult | null>(null)
  const marginLoading = ref(false)

  async function analyzeMargin(
    typeId: number,
    params: {
      runs?: number
      me?: number
      te?: number
      solarSystemId?: number
      structureId?: number
      decryptorTypeId?: number | null
    } = {},
  ): Promise<ProfitMarginResult | null> {
    marginLoading.value = true
    try {
      const queryParams = new URLSearchParams()
      if (params.runs != null) queryParams.set('runs', params.runs.toString())
      if (params.me != null) queryParams.set('me', params.me.toString())
      if (params.te != null) queryParams.set('te', params.te.toString())
      if (params.solarSystemId != null) queryParams.set('solarSystemId', params.solarSystemId.toString())
      if (params.structureId != null) queryParams.set('structureId', params.structureId.toString())
      if (params.decryptorTypeId != null) queryParams.set('decryptorTypeId', params.decryptorTypeId.toString())
      const qs = queryParams.toString() ? `?${queryParams.toString()}` : ''
      const data = await apiRequest<ProfitMarginResult>(
        `/industry/profit-margin/${typeId}${qs}`,
      )
      marginResult.value = data
      return data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to analyze profit margin'
      return null
    } finally {
      marginLoading.value = false
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    projects,
    currentProject,
    searchResults,
    costEstimation,
    bpcKit,
    copyCosts,
    marginResult,
    marginLoading,
    isLoading,
    error,
    totalProfit,
    defaultMaxJobDurationDays,
    setDefaultMaxJobDurationDays,
    searchProducts,
    createProject,
    fetchProjects,
    fetchProject,
    updateProject,
    deleteProject,
    matchJobs,
    fetchShoppingList,
    fetchCostEstimation,
    fetchBpcKit,
    fetchCopyCosts,
    applyStock,
    adaptStock,
    analyzeMargin,
    clearError,
  }
})

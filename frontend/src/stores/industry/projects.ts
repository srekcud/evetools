import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { CharacterSyncFailure, IndustryProject, SearchResult, ShoppingListResponse, CostEstimation, BpcKit, CopyCosts, ProfitMarginResult } from './types'
import { enrichProject, enrichStep, formatErrorMessage } from './compat'

const MINIMUM_MAX_JOB_DURATION_DAYS = 0.5

export const useProjectsStore = defineStore('industry-projects', () => {
  const projects = ref<IndustryProject[]>([])
  const currentProject = ref<IndustryProject | null>(null)
  const searchResults = ref<SearchResult[]>([])
  const costEstimation = ref<CostEstimation | null>(null)
  const bpcKit = ref<BpcKit | null>(null)
  const copyCosts = ref<CopyCosts | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const defaultMaxJobDurationDays = ref(2.0)

  function setDefaultMaxJobDurationDays(days: number) {
    defaultMaxJobDurationDays.value = Math.max(days, MINIMUM_MAX_JOB_DURATION_DAYS)
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
    excludedTypeIds: number[] = [],
  ) {
    error.value = null
    try {
      const project = enrichProject(await apiRequest<IndustryProject>('/industry/projects', {
        method: 'POST',
        body: JSON.stringify({ typeId, runs, meLevel, teLevel, maxJobDurationDays, name, excludedTypeIds }),
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
      }>('/industry/projects')
      projects.value = data.projects.map(enrichProject)
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
      const { steps: _s, tree: _t, failedProducts: _f, ...safeUpdated } = updated as IndustryProject & { tree?: unknown }
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

  async function matchJobs(projectId: string): Promise<CharacterSyncFailure[]> {
    try {
      const data = await apiRequest<{
        steps: IndustryProject['steps']
        jobsCost: number
        syncedCharacters: number
        failedCharacters: CharacterSyncFailure[]
      }>(`/industry/projects/${projectId}/match-jobs`, { method: 'POST', body: '{}' })
      if (currentProject.value?.id === projectId) {
        currentProject.value.steps = data.steps?.map(enrichStep)
        currentProject.value.jobsCost = data.jobsCost
      }
      return data.failedCharacters
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to match jobs'
      return []
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

  async function fetchCostEstimation(projectId: string): Promise<CostEstimation> {
    try {
      const data = await apiRequest<CostEstimation>(
        `/industry/projects/${projectId}/cost-estimation`,
      )
      costEstimation.value = data
      return data
    } catch (e) {
      costEstimation.value = null
      throw e
    }
  }

  async function fetchBpcKit(projectId: string, desiredBpcCount: number = 1): Promise<BpcKit | null> {
    bpcKit.value = null
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

  async function fetchCopyCosts(projectId: string): Promise<CopyCosts> {
    try {
      const data = await apiRequest<CopyCosts>(
        `/industry/projects/${projectId}/copy-costs`,
      )
      copyCosts.value = data
      return data
    } catch (e) {
      copyCosts.value = null
      throw e
    }
  }

  // Profit Margin
  const marginResult = ref<ProfitMarginResult | null>(null)
  const marginLoading = ref(false)
  const marginError = ref<string | null>(null)
  let latestMarginRequestId = 0

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
    const requestId = ++latestMarginRequestId
    marginLoading.value = true
    marginError.value = null
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
      if (requestId !== latestMarginRequestId) return null
      marginResult.value = data
      return data
    } catch (e) {
      if (requestId !== latestMarginRequestId) return null
      marginResult.value = null
      marginError.value = e instanceof Error ? e.message : 'Failed to analyze profit margin'
      return null
    } finally {
      if (requestId === latestMarginRequestId) marginLoading.value = false
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
    marginError,
    isLoading,
    error,
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
    analyzeMargin,
    clearError,
  }
})

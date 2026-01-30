import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export interface IndustryProject {
  id: string
  productTypeName: string
  productTypeId: number
  runs: number
  meLevel: number
  status: string
  personalUse: boolean
  bpoCost: number | null
  materialCost: number | null
  transportCost: number | null
  jobsCost: number
  taxAmount: number | null
  sellPrice: number | null
  totalCost: number
  profit: number | null
  profitPercent: number | null
  notes: string | null
  createdAt: string
  completedAt: string | null
  steps?: IndustryProjectStep[]
  tree?: ProductionTreeNode | null
}

export interface IndustryProjectStep {
  id: string
  blueprintTypeId: number
  productTypeId: number
  productTypeName: string
  quantity: number
  runs: number
  depth: number
  activityType: string
  sortOrder: number
  purchased: boolean
  esiJobId: number | null
  esiJobCost: number | null
  esiJobStatus: string | null
  esiJobEndDate: string | null
  esiJobRuns: number | null
  esiJobCharacterName: string | null
  esiJobsCount: number | null
  esiJobsTotalRuns: number | null
  esiJobsActiveRuns: number | null
  esiJobsDeliveredRuns: number | null
  manualJobData: boolean
  recommendedStructureName: string | null
  structureBonus: number | null
  structureTimeBonus: number | null
  timePerRun: number | null
  estimatedDurationDays: number | null
  splitGroupId: string | null
  splitIndex: number
  totalGroupRuns: number | null
  isSplit: boolean
}

export interface ShoppingListItem {
  typeId: number
  typeName: string
  quantity: number
  volume: number
  totalVolume: number
  jitaUnitPrice: number | null
  jitaTotal: number | null
  importCost: number
  jitaWithImport: number | null
  structureUnitPrice: number | null
  structureTotal: number | null
  bestLocation: 'jita' | 'structure' | null
  bestPrice: number | null
  savings: number | null
}

export interface ShoppingListTotals {
  jita: number
  import: number
  jitaWithImport: number
  structure: number
  volume: number
  best: number
  savingsVsJitaWithImport: number
  savingsVsStructure: number
}

export interface ShoppingListResponse {
  materials: ShoppingListItem[]
  structureId: number
  structureName: string
  structureAccessible: boolean
  structureFromCache: boolean
  structureLastSync: string | null
  transportCostPerM3: number
  totals: ShoppingListTotals
  priceError: string | null
}

export interface ProductionTreeNode {
  blueprintTypeId: number
  productTypeId: number
  productTypeName: string
  quantity: number
  runs: number
  depth: number
  activityType: string
  hasCopy: boolean
  materials: TreeMaterial[]
  // Structure bonus info
  structureBonus: number
  structureName: string | null
  productCategory: string | null
}

export interface TreeMaterial {
  typeId: number
  typeName: string
  quantity: number
  isBuildable: boolean
  blueprint?: ProductionTreeNode
}

export interface SearchResult {
  typeId: number
  typeName: string
}

export interface BlacklistCategory {
  key: string
  label: string
  groupIds: number[]
  blacklisted: boolean
}

export interface BlacklistItem {
  typeId: number
  typeName: string
}

export interface BlacklistConfig {
  categories: BlacklistCategory[]
  items: BlacklistItem[]
}

export interface StructureConfig {
  id: string
  name: string
  securityType: 'highsec' | 'lowsec' | 'nullsec'
  structureType: 'station' | 'raitaru' | 'azbel' | 'sotiyo' | 'athanor' | 'tatara' | 'engineering_complex' | 'refinery'
  rigs: string[]
  isDefault: boolean
  manufacturingMaterialBonus: number
  reactionMaterialBonus: number
  createdAt: string
}

export interface RigOption {
  name: string
  bonus: number
  category: string
  size: 'M' | 'L' | 'XL'
  targetCategories: string[]
}

export interface RigOptions {
  manufacturing: RigOption[]
  reaction: RigOption[]
}

export const useIndustryStore = defineStore('industry', () => {
  const projects = ref<IndustryProject[]>([])
  const currentProject = ref<IndustryProject | null>(null)
  const searchResults = ref<SearchResult[]>([])
  const blacklist = ref<BlacklistConfig | null>(null)
  const structures = ref<StructureConfig[]>([])
  const rigOptions = ref<RigOptions | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const totalProfit = ref(0)

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

  async function createProject(typeId: number, runs: number, meLevel: number) {
    error.value = null
    try {
      const project = await apiRequest<IndustryProject>('/industry/projects', {
        method: 'POST',
        body: JSON.stringify({ typeId, runs, meLevel }),
      })
      projects.value.unshift(project)
      return project
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to create project'
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
      projects.value = data.projects
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
        await apiRequest<IndustryProject>(`/industry/projects/${id}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch project'
    } finally {
      isLoading.value = false
    }
  }

  async function updateProject(id: string, data: Partial<IndustryProject>) {
    error.value = null
    try {
      const updated = await apiRequest<IndustryProject>(
        `/industry/projects/${id}`,
        {
          method: 'PATCH',
          body: JSON.stringify(data),
        },
      )
      const idx = projects.value.findIndex((p) => p.id === id)
      if (idx !== -1) {
        projects.value[idx] = { ...projects.value[idx], ...updated }
      }
      if (currentProject.value?.id === id) {
        currentProject.value = { ...currentProject.value, ...updated }
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

  async function toggleStepPurchased(
    projectId: string,
    stepId: string,
    purchased: boolean,
  ) {
    try {
      const updated = await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps/${stepId}`,
        {
          method: 'PATCH',
          body: JSON.stringify({ purchased }),
        },
      )
      if (currentProject.value?.steps) {
        const idx = currentProject.value.steps.findIndex((s) => s.id === stepId)
        if (idx !== -1) {
          currentProject.value.steps[idx] = updated
        }
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update step'
    }
  }

  async function updateStepJobData(
    projectId: string,
    stepId: string,
    data: {
      esiJobsTotalRuns?: number | null
      esiJobCost?: number | null
      esiJobStatus?: string | null
      esiJobCharacterName?: string | null
      esiJobsCount?: number | null
      manualJobData?: boolean
    },
  ) {
    try {
      const updated = await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps/${stepId}`,
        {
          method: 'PATCH',
          body: JSON.stringify(data),
        },
      )
      if (currentProject.value?.steps) {
        const idx = currentProject.value.steps.findIndex((s) => s.id === stepId)
        if (idx !== -1) {
          currentProject.value.steps[idx] = updated
        }
      }
      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update step'
      throw e
    }
  }

  async function clearStepJobData(projectId: string, stepId: string) {
    try {
      const updated = await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps/${stepId}`,
        {
          method: 'PATCH',
          body: JSON.stringify({ clearJobData: true }),
        },
      )
      if (currentProject.value?.steps) {
        const idx = currentProject.value.steps.findIndex((s) => s.id === stepId)
        if (idx !== -1) {
          currentProject.value.steps[idx] = updated
        }
      }
      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to clear step job data'
      throw e
    }
  }

  async function createStep(projectId: string, typeId: number, runs: number, splitGroupId?: string) {
    try {
      const body: Record<string, unknown> = { typeId, runs }
      if (splitGroupId) {
        body.splitGroupId = splitGroupId
      }
      const step = await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps`,
        {
          method: 'POST',
          body: JSON.stringify(body),
        },
      )
      if (currentProject.value?.id === projectId && currentProject.value.steps) {
        currentProject.value.steps.push(step)
      }
      return step
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to create step'
      throw e
    }
  }

  async function addChildJob(projectId: string, splitGroupId: string | null, stepId: string | null, runs: number) {
    try {
      const body: Record<string, unknown> = { runs }
      if (splitGroupId) {
        body.splitGroupId = splitGroupId
      } else if (stepId) {
        body.stepId = stepId
      }

      // When using stepId, backend returns { newStep, updatedStep }
      // When using splitGroupId, backend returns just the step
      if (stepId) {
        const response = await apiRequest<{ newStep: IndustryProjectStep; updatedStep: IndustryProjectStep }>(
          `/industry/projects/${projectId}/steps`,
          {
            method: 'POST',
            body: JSON.stringify(body),
          },
        )
        if (currentProject.value?.id === projectId && currentProject.value.steps) {
          // Update the existing step
          const idx = currentProject.value.steps.findIndex(s => s.id === stepId)
          if (idx !== -1) {
            currentProject.value.steps[idx] = response.updatedStep
          }
          // Add the new step
          currentProject.value.steps.push(response.newStep)
        }
        return response.newStep
      } else {
        const step = await apiRequest<IndustryProjectStep>(
          `/industry/projects/${projectId}/steps`,
          {
            method: 'POST',
            body: JSON.stringify(body),
          },
        )
        if (currentProject.value?.id === projectId && currentProject.value.steps) {
          currentProject.value.steps.push(step)
        }
        return step
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to add child job'
      throw e
    }
  }

  async function deleteStep(projectId: string, stepId: string) {
    try {
      await apiRequest(`/industry/projects/${projectId}/steps/${stepId}`, {
        method: 'DELETE',
      })
      if (currentProject.value?.id === projectId && currentProject.value.steps) {
        currentProject.value.steps = currentProject.value.steps.filter(
          (s) => s.id !== stepId,
        )
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete step'
      throw e
    }
  }

  async function updateStepRuns(projectId: string, stepId: string, runs: number) {
    try {
      const updated = await apiRequest<IndustryProjectStep>(
        `/industry/projects/${projectId}/steps/${stepId}`,
        {
          method: 'PATCH',
          body: JSON.stringify({ runs }),
        },
      )
      if (currentProject.value?.id === projectId && currentProject.value.steps) {
        const idx = currentProject.value.steps.findIndex((s) => s.id === stepId)
        if (idx !== -1) {
          currentProject.value.steps[idx] = updated
        }
      }
      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update step runs'
      throw e
    }
  }

  async function matchJobs(projectId: string): Promise<string | null> {
    try {
      const data = await apiRequest<{
        steps: IndustryProjectStep[]
        jobsCost: number
        syncedCharacters: number
        warning: string | null
      }>(`/industry/projects/${projectId}/match-jobs`, { method: 'POST' })
      if (currentProject.value?.id === projectId) {
        currentProject.value.steps = data.steps
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

  // Blacklist
  async function fetchBlacklist() {
    try {
      blacklist.value = await apiRequest<BlacklistConfig>('/industry/blacklist')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch blacklist'
    }
  }

  async function updateBlacklist(groupIds: number[], typeIds: number[]) {
    try {
      blacklist.value = await apiRequest<BlacklistConfig>('/industry/blacklist', {
        method: 'PUT',
        body: JSON.stringify({ groupIds, typeIds }),
      })
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update blacklist'
    }
  }

  async function searchBlacklistItems(query: string): Promise<SearchResult[]> {
    if (query.length < 2) return []
    try {
      const data = await apiRequest<{ results: SearchResult[] }>(
        `/industry/blacklist/search?q=${encodeURIComponent(query)}`,
      )
      return data.results
    } catch (e) {
      return []
    }
  }

  // Structure Configs
  async function fetchStructures() {
    try {
      const data = await apiRequest<{
        structures: StructureConfig[]
        rigOptions: RigOptions
      }>('/industry/structures')
      structures.value = data.structures
      rigOptions.value = data.rigOptions
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch structures'
    }
  }

  async function createStructure(data: {
    name: string
    securityType: string
    structureType: string
    rigs: string[]
  }) {
    try {
      const structure = await apiRequest<StructureConfig>('/industry/structures', {
        method: 'POST',
        body: JSON.stringify({ ...data, isDefault: false }),
      })
      structures.value.push(structure)
      return structure
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to create structure'
      throw e
    }
  }

  async function updateStructure(id: string, data: Partial<StructureConfig>) {
    try {
      const updated = await apiRequest<StructureConfig>(`/industry/structures/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(data),
      })
      const idx = structures.value.findIndex((s) => s.id === id)
      if (idx !== -1) {
        structures.value[idx] = updated
      }
      return updated
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update structure'
      throw e
    }
  }

  async function deleteStructure(id: string) {
    try {
      await apiRequest(`/industry/structures/${id}`, { method: 'DELETE' })
      structures.value = structures.value.filter((s) => s.id !== id)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete structure'
      throw e
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    projects,
    currentProject,
    searchResults,
    blacklist,
    structures,
    rigOptions,
    isLoading,
    error,
    totalProfit,
    searchProducts,
    createProject,
    fetchProjects,
    fetchProject,
    updateProject,
    deleteProject,
    toggleStepPurchased,
    updateStepJobData,
    clearStepJobData,
    matchJobs,
    fetchShoppingList,
    fetchBlacklist,
    updateBlacklist,
    searchBlacklistItems,
    fetchStructures,
    createStructure,
    updateStructure,
    deleteStructure,
    createStep,
    addChildJob,
    deleteStep,
    updateStepRuns,
    clearError,
  }
})

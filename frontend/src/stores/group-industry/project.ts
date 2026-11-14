import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'
import type {
  GroupProject,
  BomItem,
  ContainerVerification,
  CorporationAssetsRefresh,
  CreateGroupProjectInput,
  MatchJobsFailures,
  MatchJobsResult,
  AvailableJob,
  PurchaseSuggestions,
  CreatePurchaseInput,
  ProjectCockpit,
  UpdateGroupProjectInput,
  WalletRefreshResult,
} from './types'

export const useGroupProjectStore = defineStore('group-project', () => {
  const myProjects = ref<GroupProject[]>([])
  const availableProjects = ref<GroupProject[]>([])
  const currentProject = ref<GroupProject | null>(null)
  const bomItems = ref<BomItem[]>([])
  const containerVerification = ref<ContainerVerification[]>([])
  const containerVerificationFailed = ref(false)
  const loading = ref(false)
  const bomLoading = ref(false)
  const error = ref<string | null>(null)
  const cockpit = ref<ProjectCockpit | null>(null)
  const cockpitError = ref<string | null>(null)

  // BOM computed filters
  const materials = computed(() => bomItems.value.filter(i => !i.isJob))
  const jobs = computed(() => bomItems.value.filter(i => i.isJob))
  const blueprintJobs = computed(() => jobs.value.filter(j => j.jobGroup === 'blueprint'))
  const componentJobs = computed(() => jobs.value.filter(j => j.jobGroup === 'component'))
  const finalJobs = computed(() => jobs.value.filter(j => j.jobGroup === 'final'))

  async function fetchMyProjects(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      myProjects.value = await apiRequest<GroupProject[]>('/industry/projects')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch projects'
    } finally {
      loading.value = false
    }
  }

  async function fetchAvailableProjects(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      availableProjects.value = await apiRequest<GroupProject[]>('/industry/projects/available')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch available projects'
    } finally {
      loading.value = false
    }
  }

  async function fetchProjectDetail(id: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      currentProject.value = await apiRequest<GroupProject>(`/industry/projects/${id}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch project'
    } finally {
      loading.value = false
    }
  }

  async function fetchBom(projectId: string): Promise<void> {
    bomLoading.value = true
    try {
      bomItems.value = await apiRequest<BomItem[]>(`/industry/projects/${projectId}/bom`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch BOM'
    } finally {
      bomLoading.value = false
    }
  }

  async function fetchCockpit(projectId: string): Promise<void> {
    cockpitError.value = null
    try {
      cockpit.value = await apiRequest<ProjectCockpit>(`/industry/projects/${projectId}/cockpit`)
    } catch (e) {
      cockpit.value = null
      cockpitError.value = e instanceof Error ? e.message : 'Failed to fetch cockpit'
    }
  }

  async function fetchContainerVerification(projectId: string): Promise<void> {
    containerVerificationFailed.value = false
    try {
      containerVerification.value = await apiRequest<ContainerVerification[]>(
        `/industry/projects/${projectId}/container-verification`,
      )
    } catch (e) {
      containerVerificationFailed.value = true
      error.value = e instanceof Error ? e.message : 'Failed to verify container'
    }
  }

  async function refreshCorporationAssets(): Promise<CorporationAssetsRefresh> {
    return apiRequest<CorporationAssetsRefresh>('/me/corporation/assets/refresh', {
      method: 'POST',
      body: JSON.stringify({}),
    })
  }

  async function createProject(input: CreateGroupProjectInput): Promise<GroupProject> {
    error.value = null
    try {
      const project = await apiRequest<GroupProject>('/industry/projects', {
        method: 'POST',
        body: JSON.stringify(input),
      })
      myProjects.value.unshift(project)
      return project
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to create project'
      throw e
    }
  }

  async function updateProject(id: string, data: UpdateGroupProjectInput): Promise<GroupProject | null> {
    error.value = null
    try {
      const updated = await apiRequest<GroupProject>(`/industry/projects/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(data),
      })
      const idx = myProjects.value.findIndex(p => p.id === id)
      if (idx !== -1) {
        myProjects.value[idx] = { ...myProjects.value[idx], ...updated }
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

  async function deleteProject(id: string): Promise<void> {
    error.value = null
    try {
      await apiRequest(`/industry/projects/${id}`, { method: 'DELETE' })
      myProjects.value = myProjects.value.filter(p => p.id !== id)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete project'
    }
  }

  async function joinProject(shortLinkCode: string): Promise<GroupProject | null> {
    error.value = null
    try {
      const project = await apiRequest<GroupProject>(
        `/industry/projects/join/${shortLinkCode}`,
        { method: 'POST', body: '{}' },
      )
      return project
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to join project'
      throw e
    }
  }

  async function matchJobs(projectId: string): Promise<MatchJobsFailures> {
    error.value = null
    try {
      const result = await apiRequest<MatchJobsResult>(
        `/industry/projects/${projectId}/match-jobs`,
        { method: 'POST', body: '{}' },
      )
      await fetchBom(projectId)
      return { failedCharacters: result.failedCharacters, failedLines: result.failedLines }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to match jobs'
      throw e
    }
  }

  async function fetchAvailableJobs(projectId: string): Promise<AvailableJob[]> {
    return apiRequest<AvailableJob[]>(`/industry/projects/${projectId}/available-jobs`)
  }

  async function linkJob(projectId: string, lineId: string, esiJobId: number): Promise<void> {
    await apiRequest(`/industry/projects/${projectId}/bom/${lineId}/link-job`, {
      method: 'POST',
      body: JSON.stringify({ esiJobId }),
    })
    await fetchBom(projectId)
  }

  async function unlinkJob(projectId: string, matchId: string): Promise<void> {
    await apiRequest(`/industry/projects/${projectId}/job-matches/${matchId}`, { method: 'DELETE' })
    await fetchBom(projectId)
  }

  async function fetchPurchaseSuggestions(projectId: string): Promise<PurchaseSuggestions> {
    return apiRequest<PurchaseSuggestions>(`/industry/projects/${projectId}/purchase-suggestions`)
  }

  async function createPurchase(projectId: string, lineId: string, input: CreatePurchaseInput): Promise<void> {
    await apiRequest(`/industry/projects/${projectId}/bom/${lineId}/purchases`, {
      method: 'POST',
      body: JSON.stringify(input),
    })
    await fetchBom(projectId)
  }

  async function refreshWallet(projectId: string): Promise<WalletRefreshResult> {
    return apiRequest<WalletRefreshResult>(`/industry/projects/${projectId}/wallet-refresh`, {
      method: 'POST',
      body: JSON.stringify({}),
    })
  }

  async function reserveLine(projectId: string, lineId: string, quantity: number): Promise<void> {
    await apiRequest(`/industry/projects/${projectId}/bom/${lineId}/reservation`, {
      method: 'POST',
      body: JSON.stringify({ quantity }),
    })
    await fetchBom(projectId)
  }

  async function releaseLine(projectId: string, lineId: string): Promise<void> {
    await apiRequest(`/industry/projects/${projectId}/bom/${lineId}/reservation`, { method: 'DELETE' })
    await fetchBom(projectId)
  }

  async function deletePurchase(projectId: string, purchaseId: string): Promise<void> {
    await apiRequest(`/industry/projects/${projectId}/purchases/${purchaseId}`, { method: 'DELETE' })
    await fetchBom(projectId)
  }

  function clearError(): void {
    error.value = null
  }

  return {
    myProjects,
    availableProjects,
    currentProject,
    bomItems,
    containerVerification,
    containerVerificationFailed,
    loading,
    bomLoading,
    error,
    cockpit,
    cockpitError,
    materials,
    jobs,
    blueprintJobs,
    componentJobs,
    finalJobs,
    fetchMyProjects,
    fetchAvailableProjects,
    fetchProjectDetail,
    fetchBom,
    fetchCockpit,
    fetchContainerVerification,
    refreshCorporationAssets,
    createProject,
    updateProject,
    deleteProject,
    joinProject,
    matchJobs,
    fetchAvailableJobs,
    linkJob,
    unlinkJob,
    fetchPurchaseSuggestions,
    createPurchase,
    refreshWallet,
    deletePurchase,
    reserveLine,
    releaseLine,
    clearError,
  }
})

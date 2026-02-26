import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'
import type {
  GroupProject,
  BomItem,
  ContainerVerification,
  CreateGroupProjectInput,
} from './types'

export const useGroupProjectStore = defineStore('group-project', () => {
  const myProjects = ref<GroupProject[]>([])
  const availableProjects = ref<GroupProject[]>([])
  const currentProject = ref<GroupProject | null>(null)
  const bomItems = ref<BomItem[]>([])
  const containerVerification = ref<ContainerVerification[]>([])
  const loading = ref(false)
  const bomLoading = ref(false)
  const error = ref<string | null>(null)

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
      myProjects.value = await apiRequest<GroupProject[]>('/group-industry/projects')
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
      availableProjects.value = await apiRequest<GroupProject[]>('/group-industry/projects/available')
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
      currentProject.value = await apiRequest<GroupProject>(`/group-industry/projects/${id}`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch project'
    } finally {
      loading.value = false
    }
  }

  async function fetchBom(projectId: string): Promise<void> {
    bomLoading.value = true
    try {
      bomItems.value = await apiRequest<BomItem[]>(`/group-industry/projects/${projectId}/bom`)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch BOM'
    } finally {
      bomLoading.value = false
    }
  }

  async function fetchContainerVerification(projectId: string): Promise<void> {
    try {
      containerVerification.value = await apiRequest<ContainerVerification[]>(
        `/group-industry/projects/${projectId}/container-verification`,
      )
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to verify container'
    }
  }

  async function createProject(input: CreateGroupProjectInput): Promise<GroupProject | null> {
    error.value = null
    try {
      const project = await apiRequest<GroupProject>('/group-industry/projects', {
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

  async function updateProject(id: string, data: Partial<GroupProject>): Promise<GroupProject | null> {
    error.value = null
    try {
      const updated = await apiRequest<GroupProject>(`/group-industry/projects/${id}`, {
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
      await apiRequest(`/group-industry/projects/${id}`, { method: 'DELETE' })
      myProjects.value = myProjects.value.filter(p => p.id !== id)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete project'
    }
  }

  async function joinProject(shortLinkCode: string): Promise<GroupProject | null> {
    error.value = null
    try {
      const project = await apiRequest<GroupProject>(
        `/group-industry/projects/join/${shortLinkCode}`,
        { method: 'POST', body: '{}' },
      )
      return project
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to join project'
      throw e
    }
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
    loading,
    bomLoading,
    error,
    materials,
    jobs,
    blueprintJobs,
    componentJobs,
    finalJobs,
    fetchMyProjects,
    fetchAvailableProjects,
    fetchProjectDetail,
    fetchBom,
    fetchContainerVerification,
    createProject,
    updateProject,
    deleteProject,
    joinProject,
    clearError,
  }
})

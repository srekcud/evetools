import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import type { GroupMemberRole, ProjectPhase } from '@/stores/group-industry/types'

export type WorkshopProjectProfitStatus = 'awaiting_sales' | 'provisional' | 'final' | 'unknown'

export type WorkshopProjectMember = {
  characterId: number | null
  characterName: string
  role: GroupMemberRole
}

export type WorkshopProject = {
  projectId: string
  members: WorkshopProjectMember[]
  phases: ProjectPhase[]
  profit: { amount: number | null; status: WorkshopProjectProfitStatus }
  engagedCapital: number | null
}

export const useIndustryWorkshopProjectsStore = defineStore('industry-workshop-projects', () => {
  const syncStore = useSyncStore()
  const rows = ref<WorkshopProject[]>([])
  const isLoaded = ref(false)
  const error = ref<string | null>(null)

  async function fetchWorkshopProjects(): Promise<void> {
    try {
      rows.value = await apiRequest<WorkshopProject[]>('/industry/workshop-projects')
      isLoaded.value = true
      error.value = null
    } catch (e) {
      rows.value = []
      isLoaded.value = false
      error.value = e instanceof Error ? e.message : 'Failed to fetch the workshop projects'
    }
  }

  watch(() => syncStore.getSyncProgress('industry-project'), () => {
    void fetchWorkshopProjects()
  })

  return { rows, isLoaded, error, fetchWorkshopProjects }
})

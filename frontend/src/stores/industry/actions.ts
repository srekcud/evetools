import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { GroupProjectStatus, ProjectNextActionCode, ProjectNextActionTab } from '@/stores/group-industry/types'

export type IndustryAction = {
  projectId: string
  projectName: string | null
  code: ProjectNextActionCode
  blocked: boolean
  tab: ProjectNextActionTab | null
  targetStatus: GroupProjectStatus | null
  bomLineId: string | null
  typeId: number | null
  typeName: string | null
  quantity: number | null
}

export const useIndustryActionsStore = defineStore('industry-actions', () => {
  const actions = ref<IndustryAction[]>([])
  const isLoaded = ref(false)
  const error = ref<string | null>(null)
  const count = ref<number | null>(null)

  async function fetchActions(): Promise<void> {}

  return { actions, isLoaded, error, count, fetchActions }
})

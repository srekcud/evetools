import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import type { GroupProjectStatus, ProjectNextActionCode, ProjectNextActionTab, ProjectSellOrder } from '@/stores/group-industry/types'

export type IndustryActionSeverity = 'warning' | 'info' | 'unknown'

export const PRICE_ALERT_ACTION_CODE = 'price_below_break_even'

export const UNDERCUT_ORDER_ACTION_CODE = 'undercut_sell_order'

export type IndustryActionCode = ProjectNextActionCode | typeof PRICE_ALERT_ACTION_CODE | typeof UNDERCUT_ORDER_ACTION_CODE

export type IndustryAction = {
  projectId: string
  projectName: string | null
  code: IndustryActionCode
  blocked: boolean
  tab: ProjectNextActionTab | null
  targetStatus: GroupProjectStatus | null
  bomLineId: string | null
  typeId: number | null
  typeName: string | null
  quantity: number | null
  severity: IndustryActionSeverity
  impactIsk: number | null
  jitaUnitPrice: number | null
  breakEvenPerUnit: number | null
  undercutOrder: ProjectSellOrder | null
}

export const useIndustryActionsStore = defineStore('industry-actions', () => {
  const syncStore = useSyncStore()
  const actions = ref<IndustryAction[]>([])
  const isLoaded = ref(false)
  const error = ref<string | null>(null)
  const count = computed<number | null>(() => (isLoaded.value && error.value === null ? actions.value.length : null))

  async function fetchActions(): Promise<void> {
    try {
      actions.value = await apiRequest<IndustryAction[]>('/industry/actions')
      isLoaded.value = true
      error.value = null
    } catch (e) {
      actions.value = []
      isLoaded.value = false
      error.value = e instanceof Error ? e.message : 'Failed to fetch the action queue'
    }
  }

  watch(() => syncStore.getSyncProgress('industry-project'), () => {
    void fetchActions()
  })

  return { actions, isLoaded, error, count, fetchActions }
})

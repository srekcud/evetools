import { defineStore } from 'pinia'
import { ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import type { SupplyLaunchpad } from '@/utils/planetarySupply'

export interface InputNeedsResponse {
  launchpads: SupplyLaunchpad[]
  complete: boolean
}

export const usePlanetarySupplyStore = defineStore('planetarySupply', () => {
  const data = ref<InputNeedsResponse | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function fetchInputNeeds(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await apiRequest<InputNeedsResponse>('/planetary/input-needs')
      data.value = { launchpads: response.launchpads, complete: response.complete }
    } catch (e) {
      data.value = null
      error.value = e instanceof Error ? e.message : i18n.global.t('pi.supply.errors.load')
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return { data, isLoading, error, fetchInputNeeds, clearError }
})

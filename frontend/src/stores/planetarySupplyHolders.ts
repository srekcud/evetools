import { defineStore } from 'pinia'
import { ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import type { SupplyHolders } from '@/utils/planetarySupply'

export const usePlanetarySupplyHoldersStore = defineStore('planetarySupplyHolders', () => {
  const data = ref<SupplyHolders | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function fetchSupplyHolders(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await apiRequest<SupplyHolders>('/planetary/supply-holders')
      if (!Array.isArray(response.characters)) {
        throw new Error(i18n.global.t('pi.supply.transfer.errors.load'))
      }
      data.value = {
        homeLocationId: response.homeLocationId,
        homeLocationName: response.homeLocationName,
        characters: response.characters,
      }
    } catch (e) {
      data.value = null
      error.value = e instanceof Error ? e.message : i18n.global.t('pi.supply.transfer.errors.load')
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return { data, isLoading, error, fetchSupplyHolders, clearError }
})

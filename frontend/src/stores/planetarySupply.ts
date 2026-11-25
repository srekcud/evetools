import { defineStore } from 'pinia'
import { ref } from 'vue'

export const usePlanetarySupplyStore = defineStore('planetarySupply', () => {
  const data = ref<null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function fetchInputNeeds(): Promise<void> {}

  function clearError(): void {}

  return { data, isLoading, error, fetchInputNeeds, clearError }
})

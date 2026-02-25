import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { StockpileTarget, StockpileStatus, StockpileImportPreview } from './types'

export const useStockpileStore = defineStore('industry-stockpile', () => {
  const targets = ref<StockpileTarget[]>([])
  const stockpileStatus = ref<StockpileStatus | null>(null)
  const importPreview = ref<StockpileImportPreview | null>(null)
  const loading = ref(false)
  const statusLoading = ref(false)
  const importLoading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTargets(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const data = await apiRequest<StockpileTarget[]>('/industry/stockpile-targets')
      targets.value = data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch targets'
    } finally {
      loading.value = false
    }
  }

  async function createTarget(data: {
    typeId: number
    typeName: string
    targetQuantity: number
    stage: string
  }): Promise<void> {
    error.value = null
    try {
      await apiRequest<StockpileTarget>('/industry/stockpile-targets', {
        method: 'POST',
        body: JSON.stringify(data),
      })
      await fetchTargets()
      await fetchStockpileStatus()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to create target'
    }
  }

  async function updateTarget(id: string, targetQuantity: number): Promise<void> {
    error.value = null
    try {
      await apiRequest<StockpileTarget>(`/industry/stockpile-targets/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ targetQuantity }),
      })
      await fetchTargets()
      await fetchStockpileStatus()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to update target'
    }
  }

  async function deleteTarget(id: string): Promise<void> {
    error.value = null
    try {
      await apiRequest<null>(`/industry/stockpile-targets/${id}`, {
        method: 'DELETE',
      })
      await fetchTargets()
      await fetchStockpileStatus()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to delete target'
    }
  }

  async function fetchStockpileStatus(): Promise<void> {
    statusLoading.value = true
    error.value = null
    try {
      const data = await apiRequest<StockpileStatus>('/industry/stockpile-status')
      stockpileStatus.value = data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch stockpile status'
    } finally {
      statusLoading.value = false
    }
  }

  async function previewImport(
    typeId: number,
    runs: number,
    me: number,
    te: number,
  ): Promise<void> {
    importLoading.value = true
    error.value = null
    try {
      const data = await apiRequest<StockpileImportPreview>(
        '/industry/stockpile-targets/preview',
        {
          method: 'POST',
          body: JSON.stringify({ typeId, runs, me, te }),
        },
      )
      importPreview.value = data
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to preview import'
    } finally {
      importLoading.value = false
    }
  }

  async function importFromBlueprint(
    typeId: number,
    runs: number,
    me: number,
    te: number,
    mode: 'replace' | 'merge',
  ): Promise<void> {
    importLoading.value = true
    error.value = null
    try {
      await apiRequest<null>('/industry/stockpile-targets/import', {
        method: 'POST',
        body: JSON.stringify({ typeId, runs, me, te, mode }),
      })
      importPreview.value = null
      await fetchTargets()
      await fetchStockpileStatus()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to import targets'
    } finally {
      importLoading.value = false
    }
  }

  async function clearAllTargets(): Promise<void> {
    error.value = null
    try {
      if (targets.value.length === 0) {
        await fetchTargets()
      }
      for (const target of targets.value) {
        await apiRequest<null>(`/industry/stockpile-targets/${target.id}`, {
          method: 'DELETE',
        })
      }
      targets.value = []
      await fetchStockpileStatus()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to clear targets'
    }
  }

  function clearPreview(): void {
    importPreview.value = null
  }

  function clearError(): void {
    error.value = null
  }

  return {
    targets,
    stockpileStatus,
    importPreview,
    loading,
    statusLoading,
    importLoading,
    error,
    fetchTargets,
    createTarget,
    updateTarget,
    deleteTarget,
    clearAllTargets,
    fetchStockpileStatus,
    previewImport,
    importFromBlueprint,
    clearPreview,
    clearError,
  }
})

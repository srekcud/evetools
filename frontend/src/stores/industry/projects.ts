import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { i18n } from '@/i18n'
import type { SearchResult, ProfitMarginResult } from './types'

const MINIMUM_MAX_JOB_DURATION_DAYS = 0.5

export const DEFAULT_MINIMUM_OUTSIDE_SAVING = 500000

export const useProjectsStore = defineStore('industry-projects', () => {
  const searchResults = ref<SearchResult[]>([])
  const error = ref<string | null>(null)
  const defaultMaxJobDurationDays = ref(2.0)

  function setDefaultMaxJobDurationDays(days: number) {
    defaultMaxJobDurationDays.value = Math.max(days, MINIMUM_MAX_JOB_DURATION_DAYS)
  }

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

  async function fetchManufacturableType(typeId: number): Promise<SearchResult> {
    return apiRequest<SearchResult>(`/industry/types/${typeId}`)
  }

  // Profit Margin
  const marginResult = ref<ProfitMarginResult | null>(null)
  const marginLoading = ref(false)
  const marginError = ref<string | null>(null)
  let latestMarginRequestId = 0

  async function analyzeMargin(
    typeId: number,
    params: {
      runs?: number
      me?: number
      te?: number
      solarSystemId?: number
      structureId?: number
      decryptorTypeId?: number | null
      inventorCharacterId?: string | null
    } = {},
  ): Promise<ProfitMarginResult | null> {
    const requestId = ++latestMarginRequestId
    marginLoading.value = true
    marginError.value = null
    try {
      const queryParams = new URLSearchParams()
      if (params.runs != null) queryParams.set('runs', params.runs.toString())
      if (params.me != null) queryParams.set('me', params.me.toString())
      if (params.te != null) queryParams.set('te', params.te.toString())
      if (params.solarSystemId != null) queryParams.set('solarSystemId', params.solarSystemId.toString())
      if (params.structureId != null) queryParams.set('structureId', params.structureId.toString())
      if (params.decryptorTypeId != null) queryParams.set('decryptorTypeId', params.decryptorTypeId.toString())
      if (params.inventorCharacterId != null) queryParams.set('inventorCharacterId', params.inventorCharacterId)
      const qs = queryParams.toString() ? `?${queryParams.toString()}` : ''
      const data = await apiRequest<ProfitMarginResult>(
        `/industry/profit-margin/${typeId}${qs}`,
      )
      if (requestId !== latestMarginRequestId) return null
      marginResult.value = data
      return data
    } catch (e) {
      if (requestId !== latestMarginRequestId) return null
      marginResult.value = null
      marginError.value = e instanceof Error ? e.message : i18n.global.t('industry.errors.analyzeProfitMargin')
      return null
    } finally {
      if (requestId === latestMarginRequestId) marginLoading.value = false
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    searchResults,
    error,
    marginResult,
    marginLoading,
    marginError,
    defaultMaxJobDurationDays,
    setDefaultMaxJobDurationDays,
    searchProducts,
    fetchManufacturableType,
    analyzeMargin,
    clearError,
  }
})

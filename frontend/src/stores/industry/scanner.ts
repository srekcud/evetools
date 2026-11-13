import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { BatchScanItem, BpcPrice, BuyVsBuildResult, PivotAnalysisResult, ScannerFavorite } from './types'

type ScanFilters = {
  category: string
  minMargin: number | null
  minDailyVolume: number | null
  sellVenue: string
  structureId: number | null
}

export const useScannerStore = defineStore('industry-scanner', () => {
  // Batch scan state
  const scanResults = ref<BatchScanItem[]>([])
  const scanLoading = ref(false)
  const scanError = ref<string | null>(null)
  const lastScanAt = ref<Date | null>(null)
  const scanDiagnosisRows = ref<BatchScanItem[] | null>(null)
  const scanDiagnosisFailed = ref(false)
  const scanFilters = ref<ScanFilters>({
    category: 'all',
    minMargin: null,
    minDailyVolume: null,
    sellVenue: 'jita',
    structureId: null,
  })

  // BPC prices state
  const bpcPrices = ref<BpcPrice[]>([])
  const bpcPricesLoading = ref(false)

  // Scanner favorites state
  const favorites = ref<ScannerFavorite[]>([])
  const favoritesLoading = ref(false)

  // Buy vs Build state
  const buyVsBuildResult = ref<BuyVsBuildResult | null>(null)
  const buyVsBuildLoading = ref(false)
  const buyVsBuildError = ref<string | null>(null)

  // Pivot Advisor state
  const pivotResult = ref<PivotAnalysisResult | null>(null)
  const pivotLoading = ref(false)
  const pivotError = ref<string | null>(null)

  let latestScanRequestId = 0
  let latestBuyVsBuildRequestId = 0
  let latestPivotRequestId = 0

  function scanUrl(filters: ScanFilters, withMinimums: boolean): string {
    const params = new URLSearchParams()
    if (filters.category && filters.category !== 'all') params.set('category', filters.category)
    if (withMinimums && filters.minMargin != null) params.set('minMargin', String(filters.minMargin))
    if (withMinimums && filters.minDailyVolume != null) params.set('minDailyVolume', String(filters.minDailyVolume))
    if (filters.sellVenue) params.set('sellVenue', filters.sellVenue)
    if (filters.structureId != null) params.set('structureId', String(filters.structureId))

    const query = params.toString()
    return `/industry/profit-scan${query ? `?${query}` : ''}`
  }

  async function diagnoseEmptyScan(requestId: number, filters: ScanFilters): Promise<void> {
    if (filters.minMargin == null && filters.minDailyVolume == null) {
      scanDiagnosisRows.value = []
      return
    }

    try {
      const rows = await apiRequest<BatchScanItem[]>(scanUrl(filters, false))
      if (requestId !== latestScanRequestId) return
      scanDiagnosisRows.value = rows
    } catch {
      if (requestId !== latestScanRequestId) return
      scanDiagnosisFailed.value = true
    }
  }

  async function fetchBatchScan(filters?: Partial<ScanFilters>): Promise<void> {
    const requestId = ++latestScanRequestId
    scanLoading.value = true
    scanError.value = null
    scanDiagnosisRows.value = null
    scanDiagnosisFailed.value = false

    if (filters) {
      Object.assign(scanFilters.value, filters)
    }

    try {
      const data = await apiRequest<BatchScanItem[]>(scanUrl(scanFilters.value, true))
      if (requestId !== latestScanRequestId) return
      scanResults.value = data
      lastScanAt.value = new Date()
      if (data.length === 0) {
        await diagnoseEmptyScan(requestId, scanFilters.value)
      }
    } catch (e) {
      if (requestId !== latestScanRequestId) return
      scanResults.value = []
      scanError.value = e instanceof Error ? e.message : 'Scan failed'
    } finally {
      if (requestId === latestScanRequestId) scanLoading.value = false
    }
  }

  async function fetchBuyVsBuild(
    typeId: number,
    runs: number,
    me: number,
    solarSystemId?: number | null,
    structureId?: number | null,
  ): Promise<void> {
    const requestId = ++latestBuyVsBuildRequestId
    buyVsBuildLoading.value = true
    buyVsBuildError.value = null

    try {
      const params = new URLSearchParams()
      params.set('runs', String(runs))
      params.set('me', String(me))
      if (solarSystemId != null) params.set('solarSystemId', String(solarSystemId))
      if (structureId != null) params.set('structureId', String(structureId))

      const data = await apiRequest<BuyVsBuildResult>(
        `/industry/buy-vs-build/${typeId}?${params.toString()}`,
      )
      if (requestId !== latestBuyVsBuildRequestId) return
      buyVsBuildResult.value = data
    } catch (e) {
      if (requestId !== latestBuyVsBuildRequestId) return
      buyVsBuildResult.value = null
      buyVsBuildError.value = e instanceof Error ? e.message : 'Analysis failed'
    } finally {
      if (requestId === latestBuyVsBuildRequestId) buyVsBuildLoading.value = false
    }
  }

  function clearBuyVsBuild(): void {
    buyVsBuildResult.value = null
    buyVsBuildError.value = null
  }

  async function fetchPivotAnalysis(
    typeId: number,
    runs: number,
    solarSystemId?: number | null,
  ): Promise<void> {
    const requestId = ++latestPivotRequestId
    pivotLoading.value = true
    pivotError.value = null

    try {
      const params = new URLSearchParams()
      params.set('runs', String(runs))
      if (solarSystemId != null) params.set('solarSystemId', String(solarSystemId))

      const data = await apiRequest<PivotAnalysisResult>(
        `/industry/pivot-advisor/${typeId}?${params.toString()}`,
      )
      if (requestId !== latestPivotRequestId) return
      pivotResult.value = data
    } catch (e) {
      if (requestId !== latestPivotRequestId) return
      pivotResult.value = null
      pivotError.value = e instanceof Error ? e.message : 'Analysis failed'
    } finally {
      if (requestId === latestPivotRequestId) pivotLoading.value = false
    }
  }

  function clearPivot(): void {
    pivotResult.value = null
    pivotError.value = null
  }

  async function fetchBpcPrices(): Promise<void> {
    bpcPricesLoading.value = true
    try {
      const data = await apiRequest<BpcPrice[]>('/industry/bpc-prices')
      bpcPrices.value = data
    } catch {
      // best-effort, don't block the UI
    } finally {
      bpcPricesLoading.value = false
    }
  }

  async function upsertBpcPrice(blueprintTypeId: number, pricePerRun: number): Promise<void> {
    await apiRequest('/industry/bpc-prices', {
      method: 'PUT',
      body: JSON.stringify({ blueprintTypeId, pricePerRun }),
    })

    const existing = bpcPrices.value.find(p => p.blueprintTypeId === blueprintTypeId)
    if (existing) {
      existing.pricePerRun = pricePerRun
      existing.updatedAt = new Date().toISOString()
    } else {
      bpcPrices.value.push({ blueprintTypeId, pricePerRun, updatedAt: new Date().toISOString() })
    }

    await fetchBatchScan()
  }

  async function deleteBpcPrice(blueprintTypeId: number): Promise<void> {
    await apiRequest(`/industry/bpc-prices/${blueprintTypeId}`, {
      method: 'DELETE',
    })

    bpcPrices.value = bpcPrices.value.filter(p => p.blueprintTypeId !== blueprintTypeId)

    await fetchBatchScan()
  }

  async function fetchFavorites(): Promise<void> {
    favoritesLoading.value = true
    try {
      const data = await apiRequest<ScannerFavorite[]>('/industry/scanner-favorites')
      favorites.value = data
    } catch {
      // best-effort
    } finally {
      favoritesLoading.value = false
    }
  }

  async function addFavorite(typeId: number): Promise<void> {
    await apiRequest('/industry/scanner-favorites', {
      method: 'POST',
      body: JSON.stringify({ typeId }),
    })

    if (!favorites.value.some(f => f.typeId === typeId)) {
      favorites.value.push({ typeId, createdAt: new Date().toISOString() })
    }
  }

  async function removeFavorite(typeId: number): Promise<void> {
    await apiRequest(`/industry/scanner-favorites/${typeId}`, {
      method: 'DELETE',
    })

    favorites.value = favorites.value.filter(f => f.typeId !== typeId)
  }

  function isFavorite(typeId: number): boolean {
    return favorites.value.some(f => f.typeId === typeId)
  }

  return {
    // Batch scan
    scanResults,
    scanLoading,
    scanError,
    lastScanAt,
    scanFilters,
    scanDiagnosisRows,
    scanDiagnosisFailed,
    fetchBatchScan,

    // BPC prices
    bpcPrices,
    bpcPricesLoading,
    fetchBpcPrices,
    upsertBpcPrice,
    deleteBpcPrice,

    // Scanner favorites
    favorites,
    favoritesLoading,
    fetchFavorites,
    addFavorite,
    removeFavorite,
    isFavorite,

    // Buy vs Build
    buyVsBuildResult,
    buyVsBuildLoading,
    buyVsBuildError,
    fetchBuyVsBuild,
    clearBuyVsBuild,

    // Pivot Advisor
    pivotResult,
    pivotLoading,
    pivotError,
    fetchPivotAnalysis,
    clearPivot,
  }
})

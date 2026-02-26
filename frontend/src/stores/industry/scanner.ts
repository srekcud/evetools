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

  async function fetchBatchScan(filters?: Partial<ScanFilters>): Promise<void> {
    scanLoading.value = true
    scanError.value = null

    if (filters) {
      Object.assign(scanFilters.value, filters)
    }

    try {
      const params = new URLSearchParams()
      const f = scanFilters.value
      if (f.category && f.category !== 'all') params.set('category', f.category)
      if (f.minMargin != null) params.set('minMargin', String(f.minMargin))
      if (f.minDailyVolume != null) params.set('minDailyVolume', String(f.minDailyVolume))
      if (f.sellVenue) params.set('sellVenue', f.sellVenue)
      if (f.structureId != null) params.set('structureId', String(f.structureId))

      const query = params.toString()
      const url = `/industry/profit-scan${query ? `?${query}` : ''}`

      const data = await apiRequest<BatchScanItem[]>(url)
      scanResults.value = data
      lastScanAt.value = new Date()
    } catch (e) {
      scanError.value = e instanceof Error ? e.message : 'Scan failed'
    } finally {
      scanLoading.value = false
    }
  }

  async function fetchBuyVsBuild(
    typeId: number,
    runs: number,
    me: number,
    solarSystemId?: number | null,
    structureId?: number | null,
  ): Promise<void> {
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
      buyVsBuildResult.value = data
    } catch (e) {
      buyVsBuildError.value = e instanceof Error ? e.message : 'Analysis failed'
    } finally {
      buyVsBuildLoading.value = false
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
    pivotLoading.value = true
    pivotError.value = null

    try {
      const params = new URLSearchParams()
      params.set('runs', String(runs))
      if (solarSystemId != null) params.set('solarSystemId', String(solarSystemId))

      const data = await apiRequest<PivotAnalysisResult>(
        `/industry/pivot-advisor/${typeId}?${params.toString()}`,
      )
      pivotResult.value = data
    } catch (e) {
      pivotError.value = e instanceof Error ? e.message : 'Analysis failed'
    } finally {
      pivotLoading.value = false
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

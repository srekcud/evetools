import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'

// ========== Types ==========

export interface PinContent {
  typeId: number
  typeName: string
  amount: number
}

export interface Pin {
  pinId: number
  typeId: number
  typeName: string | null
  pinCategory: string
  schematicId: number | null
  schematicName: string | null
  installTime: string | null
  expiryTime: string | null
  extractorProductTypeId: number | null
  extractorProductName: string | null
  extractorCycleTime: number | null
  extractorQtyPerCycle: number | null
  extractorNumHeads: number | null
  contents: PinContent[]
  dailyOutput: number | null
}

export interface Colony {
  id: string
  characterId: number
  characterName: string
  planetId: number
  planetType: string
  solarSystemId: number
  solarSystemName: string | null
  upgradeLevel: number
  numPins: number
  lastUpdate: string
  cachedAt: string
  extractorCount: number
  factoryCount: number
  activeExtractors: number
  nearestExpiry: string | null
  status: 'active' | 'expiring' | 'expired' | 'idle'
  pins: Pin[]
  routes: unknown[]
}

export interface PlanetaryStats {
  totalColonies: number
  totalExtractors: number
  activeExtractors: number
  expiringExtractors: number
  expiredExtractors: number
  totalFactories: number
  estimatedDailyIsk: number
  nearestExpiry: string | null
}

export interface ProductionInput {
  typeId: number
  typeName: string
  dailyConsumed: number
  dailyProduced: number
  delta: number
}

export interface ProductionItem {
  typeId: number
  typeName: string
  dailyQuantity: number
  unitPrice: number
  dailyIskValue: number
  inputs: ProductionInput[]
}

export interface ProductionTier {
  tier: string
  items: ProductionItem[]
  dailyIskValue: number
}

// ========== Store ==========

export const usePlanetaryStore = defineStore('planetary', () => {
  // State
  const colonies = ref<Colony[]>([])
  const stats = ref<PlanetaryStats | null>(null)
  const production = ref<ProductionTier[]>([])
  const isLoading = ref(false)
  const isSyncing = ref(false)
  const error = ref<string | null>(null)
  const lastSyncAt = ref<string | null>(null)

  // Computed
  const coloniesByCharacter = computed(() => {
    const grouped: Record<number, { characterName: string; characterId: number; colonies: Colony[] }> = {}
    for (const colony of colonies.value) {
      if (!grouped[colony.characterId]) {
        grouped[colony.characterId] = {
          characterName: colony.characterName,
          characterId: colony.characterId,
          colonies: [],
        }
      }
      grouped[colony.characterId].colonies.push(colony)
    }
    return Object.values(grouped)
  })

  const expiredColonies = computed(() =>
    colonies.value.filter(c => c.status === 'expired')
  )

  const expiringColonies = computed(() =>
    colonies.value.filter(c => c.status === 'expiring')
  )

  const totalDailyIsk = computed(() =>
    production.value.reduce((sum, tier) => sum + tier.dailyIskValue, 0)
  )

  // Actions
  async function fetchColonies(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      colonies.value = await apiRequest<Colony[]>('/planetary')
      if (colonies.value.length > 0) {
        const mostRecent = colonies.value.reduce((latest, c) =>
          c.cachedAt > latest ? c.cachedAt : latest, colonies.value[0].cachedAt
        )
        lastSyncAt.value = mostRecent
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement des colonies'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchColonyDetail(id: string): Promise<Colony | null> {
    error.value = null

    try {
      const detail = await apiRequest<Colony>(`/planetary/${id}`)
      // Merge pins/routes into the existing colony in the list
      const idx = colonies.value.findIndex(c => c.id === id)
      if (idx !== -1) {
        colonies.value[idx] = { ...colonies.value[idx], pins: detail.pins, routes: detail.routes }
      }
      return detail
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement du detail'
      return null
    }
  }

  async function fetchStats(): Promise<void> {
    error.value = null

    try {
      stats.value = await apiRequest<PlanetaryStats>('/planetary/stats')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement des statistiques'
    }
  }

  async function fetchProduction(): Promise<void> {
    error.value = null

    try {
      const data = await apiRequest<{ tiers: ProductionTier[] }>('/planetary/production')
      production.value = data.tiers
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement de la production'
    }
  }

  async function syncColonies(): Promise<void> {
    isSyncing.value = true
    error.value = null

    try {
      await apiRequest('/planetary/sync', { method: 'POST', body: JSON.stringify({}) })
      // Reload data after sync
      await Promise.all([fetchColonies(), fetchStats(), fetchProduction()])
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Erreur lors de la synchronisation'
    } finally {
      isSyncing.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return {
    // State
    colonies,
    stats,
    production,
    isLoading,
    isSyncing,
    error,
    lastSyncAt,

    // Computed
    coloniesByCharacter,
    expiredColonies,
    expiringColonies,
    totalDailyIsk,

    // Actions
    fetchColonies,
    fetchColonyDetail,
    fetchStats,
    fetchProduction,
    syncColonies,
    clearError,
  }
})

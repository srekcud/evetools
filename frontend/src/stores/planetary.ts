import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiRequest } from '@/services/api'
import type { ProjectSellOrder } from '@/stores/group-industry/types'

// ========== Types ==========

export interface PinContent {
  typeId: number
  typeName: string | null
  amount: number
  volume: number | null
}

export interface SchematicIO {
  typeId: number
  typeName: string | null
  quantity: number
}

export interface PlanetaryRevenueContributor {
  characterId: number
  characterName: string
  value: number
}

export interface PlanetaryRevenueDay {
  date: string
  amount: number
}

export interface PlanetaryRevenueTier {
  tier: string
  realisedIsk: number
  change: number | null
  daily: PlanetaryRevenueDay[]
  contributors: PlanetaryRevenueContributor[]
}

export interface PlanetaryRevenueToFix {
  characterId: number
  characterName: string
  planetId: number
  planetName: string | null
  solarSystemName: string | null
}

export type PlanetaryWalletStatus = 'never_read' | 'no_token' | 'missing_scope' | 'invalid_auth'

export interface PlanetaryRevenueUnsyncedWallet {
  characterId: number
  characterName: string
  status: PlanetaryWalletStatus
}

export interface PlanetarySellOrders {
  orders: ProjectSellOrder[]
  charactersToReconnect: string[]
  syncedAt: string | null
}

export interface PlanetaryRevenue {
  periodEnd: string
  days: number
  tiers: PlanetaryRevenueTier[]
  realisedIsk: number
  realisedChange: number | null
  realisedDaily: PlanetaryRevenueDay[]
  realisedContributors: PlanetaryRevenueContributor[]
  estimate: {
    dailyIsk: number
    periodIsk: number
    contributors: PlanetaryRevenueContributor[]
  }
  toFix: PlanetaryRevenueToFix[]
  unsyncedWallets: PlanetaryRevenueUnsyncedWallet[]
  sellOrders: PlanetarySellOrders
}

export interface Pin {
  pinId: number
  typeId: number
  typeName: string | null
  pinCategory: string
  capacity: number | null
  schematicId: number | null
  schematicName: string | null
  schematicCycleTime: number | null
  schematicInputs: SchematicIO[]
  schematicOutput: SchematicIO | null
  installTime: string | null
  expiryTime: string | null
  extractorProductTypeId: number | null
  extractorProductName: string | null
  extractorCycleTime: number | null
  extractorQtyPerCycle: number | null
  extractorNumHeads: number | null
  contents: PinContent[]
  dailyOutput: number | null
  outputTier: string | null
}

export interface Route {
  routeId: number
  sourcePinId: number
  destinationPinId: number
  contentTypeId: number
  contentTypeName: string | null
  contentVolume: number | null
  quantity: number
  waypoints: number[]
}

export interface FactoryProgress {
  finalProductTypeId: number
  finalProductName: string | null
  factoryCount: number
  stockedFinalProduct: number
  maxProducts: number
  remainingSeconds: number
  endsAt: string
}

export interface Colony {
  id: string
  characterId: number
  characterName: string
  planetId: number
  planetName: string | null
  planetType: string
  solarSystemId: number
  solarSystemName: string | null
  solarSystemSecurity: number | null
  upgradeLevel: number
  cpuUsed?: number | null
  cpuMax?: number | null
  powergridUsed?: number | null
  powergridMax?: number | null
  commandCenterSkillLevel?: number | null
  commandCenterUpgradable?: boolean | null
  tier: string | null
  storageFull: boolean | null
  numPins: number
  lastUpdate: string
  cachedAt: string
  extractorCount: number
  factoryCount: number
  activeExtractors: number
  nearestExpiry: string | null
  factoryProgress: FactoryProgress | null
  factoryAutonomyDays?: number | null
  factoryRefillBy?: string | null
  status: 'active' | 'expiring' | 'expired' | 'idle' | 'factory'
  pins: Pin[]
  routes: Route[]
}

export interface PlanetaryInputNeedsFeed {
  typeId: number
  typeName: string | null
  emptyAt: string | null
}

export interface PlanetaryInputNeedsLaunchpad {
  colonyId: string
  pinId: number
  feeds: PlanetaryInputNeedsFeed[]
}

export interface PlanetaryInputNeeds {
  launchpads: PlanetaryInputNeedsLaunchpad[]
  complete: boolean
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
  expiryThresholdMinutes?: number
}

export interface PlanetarySlotCharacter {
  characterId: number
  characterName: string
  isMain: boolean
  coloniesUsed: number
  consolidationLevel: number | null
  maxColonies: number | null
}

export interface PlanetarySlots {
  characters: PlanetarySlotCharacter[]
  totalUsed: number
  totalMax: number | null
}

export interface ProductionInput {
  typeId: number
  typeName: string | null
  dailyConsumed: number
  dailyProduced: number
  delta: number
}

export interface ProductionItem {
  typeId: number
  typeName: string | null
  dailyQuantity: number
  dailyConsumedInternally: number
  dailySellableQuantity: number
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
  const slots = ref<PlanetarySlots | null>(null)
  const production = ref<ProductionTier[]>([])
  const inputNeeds = ref<PlanetaryInputNeeds | null>(null)
  const revenue = ref<PlanetaryRevenue | null>(null)
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
        // Only update if more recent (don't overwrite a manual sync timestamp)
        if (!lastSyncAt.value || mostRecent > lastSyncAt.value) {
          lastSyncAt.value = mostRecent
        }
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

  async function fetchSlots(): Promise<void> {
    error.value = null

    try {
      slots.value = await apiRequest<PlanetarySlots>('/planetary/slots')
    } catch (e) {
      slots.value = null
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement des emplacements'
    }
  }

  async function fetchInputNeeds(): Promise<void> {
    error.value = null

    try {
      const data = await apiRequest<PlanetaryInputNeeds>('/planetary/input-needs')
      inputNeeds.value = { launchpads: data.launchpads, complete: data.complete }
    } catch (e) {
      inputNeeds.value = null
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement des besoins en intrants'
    }
  }

  async function fetchRevenue(days: number): Promise<void> {
    error.value = null

    try {
      revenue.value = await apiRequest<PlanetaryRevenue>(`/planetary/revenue?days=${days}`)
    } catch (e) {
      revenue.value = null
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement des revenus'
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
      // POST is async (204) - Mercure will notify when sync completes
      // isSyncing stays true until finishSync() is called by the view
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Erreur lors de la synchronisation'
      isSyncing.value = false
    }
  }

  async function finishSync(): Promise<void> {
    isSyncing.value = false
    lastSyncAt.value = new Date().toISOString()
    await Promise.all([fetchColonies(), fetchStats(), fetchSlots(), fetchProduction(), fetchInputNeeds()])
  }

  function failSync(message?: string): void {
    isSyncing.value = false
    error.value = message || 'Erreur lors de la synchronisation'
  }

  function clearError(): void {
    error.value = null
  }

  return {
    // State
    colonies,
    stats,
    slots,
    production,
    inputNeeds,
    revenue,
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
    fetchSlots,
    fetchProduction,
    fetchInputNeeds,
    fetchRevenue,
    syncColonies,
    finishSync,
    failSync,
    clearError,
  }
})

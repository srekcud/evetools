<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { usePlanetaryStore, type Colony, type Pin } from '@/stores/planetary'
import { useSyncStore } from '@/stores/sync'
import { useFormatters } from '@/composables/useFormatters'
import MainLayout from '@/layouts/MainLayout.vue'

const planetaryStore = usePlanetaryStore()
const syncStore = useSyncStore()
const { formatIsk, formatTimeSince, formatNumber } = useFormatters()

// ========== Mercure sync tracking ==========

const planetarySyncProgress = computed(() => syncStore.getSyncProgress('planetary'))

const isSyncing = computed(() =>
  planetaryStore.isSyncing || syncStore.isLoading('planetary')
)

// Debounce finishSync to handle multi-character sync (one cycle per character)
let syncDebounce: ReturnType<typeof setTimeout> | null = null

watch(
  () => planetarySyncProgress.value,
  (progress) => {
    if (!progress) return

    if (progress.status === 'completed') {
      // Wait before reloading — another character's sync may start shortly
      if (syncDebounce) clearTimeout(syncDebounce)
      syncDebounce = setTimeout(() => {
        planetaryStore.finishSync()
        syncStore.clearSyncStatus('planetary')
        syncDebounce = null
      }, 3000)
    } else if (progress.status === 'error') {
      if (syncDebounce) { clearTimeout(syncDebounce); syncDebounce = null }
      planetaryStore.failSync(progress.message || undefined)
      syncStore.clearSyncStatus('planetary')
    } else if (progress.status === 'started' || progress.status === 'in_progress') {
      // New character sync started — cancel pending reload
      if (syncDebounce) { clearTimeout(syncDebounce); syncDebounce = null }
    }
  }
)

// ========== Planet Type Config ==========

const PLANET_TYPE_CONFIG: Record<string, { typeId: number; badgeBg: string; badgeText: string; badgeBorder: string }> = {
  temperate: { typeId: 11, badgeBg: 'bg-green-500/15', badgeText: 'text-green-400', badgeBorder: 'border-green-500/20' },
  barren: { typeId: 2016, badgeBg: 'bg-yellow-500/15', badgeText: 'text-yellow-400', badgeBorder: 'border-yellow-500/20' },
  lava: { typeId: 2015, badgeBg: 'bg-red-500/15', badgeText: 'text-red-400', badgeBorder: 'border-red-500/20' },
  ice: { typeId: 12, badgeBg: 'bg-cyan-500/15', badgeText: 'text-cyan-400', badgeBorder: 'border-cyan-500/20' },
  gas: { typeId: 13, badgeBg: 'bg-purple-500/15', badgeText: 'text-purple-400', badgeBorder: 'border-purple-500/20' },
  oceanic: { typeId: 2014, badgeBg: 'bg-blue-500/15', badgeText: 'text-blue-400', badgeBorder: 'border-blue-500/20' },
  plasma: { typeId: 2063, badgeBg: 'bg-orange-500/15', badgeText: 'text-orange-400', badgeBorder: 'border-orange-500/20' },
  storm: { typeId: 2017, badgeBg: 'bg-gray-500/15', badgeText: 'text-gray-400', badgeBorder: 'border-gray-500/20' },
}

const TIER_CONFIG: Record<string, { label: string; badgeBg: string; badgeText: string; badgeBorder: string }> = {
  P0: { label: 'Brut', badgeBg: 'bg-slate-600/50', badgeText: 'text-slate-300', badgeBorder: 'border-slate-600/50' },
  P1: { label: 'Transforme', badgeBg: 'bg-cyan-500/15', badgeText: 'text-cyan-400', badgeBorder: 'border-cyan-500/20' },
  P2: { label: 'Raffine', badgeBg: 'bg-indigo-500/15', badgeText: 'text-indigo-400', badgeBorder: 'border-indigo-500/20' },
  P3: { label: 'Specialise', badgeBg: 'bg-amber-500/15', badgeText: 'text-amber-400', badgeBorder: 'border-amber-500/20' },
  P4: { label: 'Avance', badgeBg: 'bg-rose-500/15', badgeText: 'text-rose-400', badgeBorder: 'border-rose-500/20' },
}

// ========== UI State ==========

const expandedColonies = ref<Set<string>>(new Set())
const expandedTiers = ref<Set<string>>(new Set())

// ========== Timer countdown ==========

const now = ref(new Date())
let timerInterval: ReturnType<typeof setInterval> | null = null

onMounted(async () => {
  timerInterval = setInterval(() => {
    now.value = new Date()
  }, 1000)

  await Promise.all([
    planetaryStore.fetchColonies(),
    planetaryStore.fetchStats(),
    planetaryStore.fetchProduction(),
  ])
})

onUnmounted(() => {
  if (timerInterval) {
    clearInterval(timerInterval)
  }
  if (syncDebounce) {
    clearTimeout(syncDebounce)
  }
})

// ========== Computed Stats ==========

const statsData = computed(() => planetaryStore.stats)

const totalCharacterCount = computed(() => {
  return planetaryStore.coloniesByCharacter.length
})

// ========== Timer Helpers ==========

function getTimerInfo(expiryTime: string | null): { remaining: number; formatted: string; status: 'active' | 'expiring' | 'expired' } {
  if (!expiryTime) return { remaining: -1, formatted: 'N/D', status: 'expired' }

  const expiry = new Date(expiryTime)
  const diff = expiry.getTime() - now.value.getTime()

  if (diff <= 0) {
    return { remaining: 0, formatted: 'EXPIRE', status: 'expired' }
  }

  const totalSeconds = Math.floor(diff / 1000)
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60

  const formatted = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
  const status = hours < 24 ? 'expiring' : 'active'

  return { remaining: totalSeconds, formatted, status }
}

function getTimerColorClasses(status: 'active' | 'expiring' | 'expired'): { dot: string; text: string; pulse: string } {
  switch (status) {
    case 'active':
      return { dot: 'bg-emerald-400', text: 'text-emerald-400', pulse: '' }
    case 'expiring':
      return { dot: 'bg-amber-500', text: 'text-amber-400', pulse: 'timer-urgent' }
    case 'expired':
      return { dot: 'bg-red-500', text: 'text-red-400', pulse: 'animate-pulse' }
  }
}

// ========== Colony helpers ==========

function getSecurityColorClass(security: number | null): string {
  if (security === null) return 'text-slate-500'
  if (security >= 0.5) return 'text-emerald-400'
  if (security >= 0.1) return 'text-yellow-400'
  return 'text-red-400'
}

function formatSecurity(security: number | null): string {
  if (security === null) return '?'
  return security.toFixed(1)
}

function getPlanetConfig(planetType: string) {
  const key = planetType.toLowerCase()
  return PLANET_TYPE_CONFIG[key] || PLANET_TYPE_CONFIG.barren
}

function getPlanetIconUrl(planetType: string, size: number = 64): string {
  const config = getPlanetConfig(planetType)
  return `https://images.evetech.net/types/${config.typeId}/icon?size=${size}`
}

function getExtractorPins(colony: Colony): Pin[] {
  return colony.pins.filter(p => p.pinCategory === 'extractor' || p.extractorProductTypeId !== null)
}

function getFactoryPins(colony: Colony): Pin[] {
  return colony.pins.filter(p => p.pinCategory === 'factory' || (p.schematicId !== null && p.pinCategory !== 'extractor'))
}

function getStoragePins(colony: Colony): Pin[] {
  return colony.pins.filter(p =>
    p.pinCategory === 'storage' || p.pinCategory === 'launchpad' || p.pinCategory === 'command_center'
  )
}

// ========== Flow View helpers (routes-based) ==========

// Approximate volume per unit by PI tier
const VOLUME_BY_TIER: Record<string, number> = {
  P0: 0.01,
  P1: 0.38,
  P2: 1.50,
  P3: 6.00,
  P4: 100.00,
}

interface FlowLine {
  contentTypeName: string
  contentTypeId: number
  quantityPerCycle: number
  cycleTimeSeconds: number | null // null = unknown source pin cycle
  dailyQuantity: number | null    // null if cycle time unknown
}

interface StorageFlowData {
  incoming: FlowLine[]
  outgoing: FlowLine[]
  netDailyVolume: number | null   // positive = accumulating, negative = draining
  fillDays: number | null         // days until full at current net rate
  capacity: number | null
}

function getSourcePinCycleTime(colony: Colony, sourcePinId: number): number | null {
  const pin = colony.pins.find(p => p.pinId === sourcePinId)
  if (!pin) return null

  // Extractors: use extractorCycleTime
  if (pin.extractorCycleTime && pin.extractorCycleTime > 0) {
    return pin.extractorCycleTime
  }
  // Factories: use schematicCycleTime
  if (pin.schematicCycleTime && pin.schematicCycleTime > 0) {
    return pin.schematicCycleTime
  }

  return null
}

function guessItemTier(contentTypeId: number): string {
  // Use production data from the store to find the tier
  for (const tier of planetaryStore.production) {
    if (tier.items.some(i => i.typeId === contentTypeId)) {
      return tier.tier
    }
  }
  return 'P0'
}

function getStorageFlowData(colony: Colony, storagePin: Pin): StorageFlowData {
  // Store per-route data: { quantity, cycleTime } grouped by contentTypeId and direction
  interface RouteEntry { quantity: number; cycleTime: number | null }
  const inMap = new Map<number, { name: string; routes: RouteEntry[] }>()
  const outMap = new Map<number, { name: string; routes: RouteEntry[] }>()

  for (const route of colony.routes) {
    if (route.destinationPinId === storagePin.pinId) {
      const entry: RouteEntry = { quantity: route.quantity, cycleTime: getSourcePinCycleTime(colony, route.sourcePinId) }
      const existing = inMap.get(route.contentTypeId)
      if (existing) {
        existing.routes.push(entry)
      } else {
        inMap.set(route.contentTypeId, { name: route.contentTypeName, routes: [entry] })
      }
    }
    if (route.sourcePinId === storagePin.pinId) {
      const entry: RouteEntry = { quantity: route.quantity, cycleTime: getSourcePinCycleTime(colony, route.destinationPinId) }
      const existing = outMap.get(route.contentTypeId)
      if (existing) {
        existing.routes.push(entry)
      } else {
        outMap.set(route.contentTypeId, { name: route.contentTypeName, routes: [entry] })
      }
    }
  }

  function buildFlowLines(map: Map<number, { name: string; routes: RouteEntry[] }>): FlowLine[] {
    const lines: FlowLine[] = []
    for (const [typeId, data] of map) {
      const totalQty = data.routes.reduce((sum, r) => sum + r.quantity, 0)
      const allCyclesKnown = data.routes.every(r => r.cycleTime !== null && r.cycleTime > 0)
      const dailyQuantity = allCyclesKnown
        ? Math.round(data.routes.reduce((sum, r) => sum + r.quantity * (86400 / r.cycleTime!), 0))
        : null
      const firstCycle = data.routes.find(r => r.cycleTime !== null)?.cycleTime ?? null

      lines.push({
        contentTypeName: data.name,
        contentTypeId: typeId,
        quantityPerCycle: totalQty,
        cycleTimeSeconds: firstCycle,
        dailyQuantity,
      })
    }
    return lines
  }

  const incoming = buildFlowLines(inMap)
  const outgoing = buildFlowLines(outMap)

  // Compute net daily volume (in m3)
  let netDailyVolume: number | null = null
  const allInKnown = incoming.every(f => f.dailyQuantity !== null)
  const allOutKnown = outgoing.every(f => f.dailyQuantity !== null)

  if (allInKnown && allOutKnown && (incoming.length > 0 || outgoing.length > 0)) {
    const computeVolume = (flows: FlowLine[]) =>
      flows.reduce((sum, f) => {
        const vol = VOLUME_BY_TIER[guessItemTier(f.contentTypeId)] ?? 0.38
        return sum + (f.dailyQuantity ?? 0) * vol
      }, 0)

    netDailyVolume = Math.round((computeVolume(incoming) - computeVolume(outgoing)) * 100) / 100
  }

  // Fill days (only meaningful when net flow is positive = accumulating)
  let fillDays: number | null = null
  if (netDailyVolume !== null && netDailyVolume > 0 && storagePin.capacity && storagePin.capacity > 0) {
    fillDays = Math.round((storagePin.capacity / netDailyVolume) * 10) / 10
  }

  return { incoming, outgoing, netDailyVolume, fillDays, capacity: storagePin.capacity }
}

function getNetFlowColorClass(net: number | null): string {
  if (net === null) return 'text-slate-500'
  if (net > 0) return 'text-emerald-400'
  if (net < 0) return 'text-red-400'
  return 'text-slate-500'
}

function formatNetFlow(net: number | null): string {
  if (net === null) return '-'
  const sign = net > 0 ? '+' : ''
  return `${sign}${formatNumber(net, 1)} m3/jour`
}

interface ColonyProductionItem {
  typeName: string
  dailyQuantity: number
  outputTier: string | null
  unitPrice: number
  dailyIskValue: number
}

function findUnitPrice(typeId: number): number {
  for (const tier of planetaryStore.production) {
    const item = tier.items.find(i => i.typeId === typeId)
    if (item && item.unitPrice) return item.unitPrice
  }
  return 0
}

function getColonyProduction(colony: Colony): ColonyProductionItem[] {
  const factories = getFactoryPins(colony)
  const items: ColonyProductionItem[] = []

  for (const pin of factories) {
    if (pin.schematicOutput && pin.schematicCycleTime && pin.schematicCycleTime > 0) {
      const cyclesPerDay = (3600 / pin.schematicCycleTime) * 24
      const dailyQty = Math.round(pin.schematicOutput.quantity * cyclesPerDay)
      const unitPrice = findUnitPrice(pin.schematicOutput.typeId)
      const existing = items.find(i => i.typeName === pin.schematicOutput!.typeName)
      if (existing) {
        existing.dailyQuantity += dailyQty
        existing.dailyIskValue += dailyQty * unitPrice
      } else {
        items.push({
          typeName: pin.schematicOutput.typeName,
          dailyQuantity: dailyQty,
          outputTier: pin.outputTier,
          unitPrice,
          dailyIskValue: dailyQty * unitPrice,
        })
      }
    }
  }

  return items
}

function getColonyBorderClass(colony: Colony): string {
  if (colony.status === 'expired') return 'border-red-500/20'
  if (colony.status === 'expiring') return 'border-amber-500/15'
  return 'border-slate-700/40'
}

function isStaleData(colony: Colony): boolean {
  const cachedDate = new Date(colony.cachedAt)
  const diffMs = now.value.getTime() - cachedDate.getTime()
  return diffMs > 60 * 60 * 1000 // older than 1 hour
}

async function toggleColony(colonyId: string): Promise<void> {
  if (expandedColonies.value.has(colonyId)) {
    expandedColonies.value.delete(colonyId)
  } else {
    expandedColonies.value.add(colonyId)
    // Fetch detail (pins/routes) if not already loaded
    const colony = planetaryStore.colonies.find(c => c.id === colonyId)
    if (colony && colony.pins.length === 0) {
      await planetaryStore.fetchColonyDetail(colonyId)
    }
  }
}

function toggleTier(tier: string): void {
  if (expandedTiers.value.has(tier)) {
    expandedTiers.value.delete(tier)
  } else {
    expandedTiers.value.add(tier)
  }
}

function formatCycleTime(seconds: number | null): string {
  if (!seconds) return '-'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes} min`
  const hours = Math.floor(minutes / 60)
  const remainingMin = minutes % 60
  return remainingMin > 0 ? `${hours}h ${remainingMin}min` : `${hours}h`
}

function formatDailyOutput(pin: Pin): string {
  if (!pin.extractorQtyPerCycle || !pin.extractorCycleTime) return '-'
  const cyclesPerHour = 3600 / pin.extractorCycleTime
  const hourly = Math.round(pin.extractorQtyPerCycle * cyclesPerHour)
  return `max ~${formatNumber(hourly)} / heure`
}

// ========== Production tier helpers ==========

function getTierTotalVolume(tier: { items: { dailyQuantity: number }[] }): number {
  return tier.items.reduce((sum, item) => sum + item.dailyQuantity, 0)
}

function getTierPercentage(tierIsk: number): string {
  const total = planetaryStore.totalDailyIsk
  if (total <= 0) return '-'
  return ((tierIsk / total) * 100).toFixed(1) + '%'
}

function getTierIskColor(tier: string): string {
  const config = TIER_CONFIG[tier]
  return config?.badgeText || 'text-slate-400'
}

function getWorstDelta(inputs: { delta: number }[]): number {
  if (!inputs.length) return 0
  return inputs.reduce((worst, i) => Math.min(worst, i.delta), inputs[0].delta)
}

function getSupplyDeltaColor(delta: number): { dot: string; text: string } {
  if (delta > 0) return { dot: 'bg-blue-400', text: 'text-blue-400' }
  if (delta === 0) return { dot: 'bg-emerald-400', text: 'text-emerald-400' }
  return { dot: 'bg-red-400', text: 'text-red-400 font-bold' }
}

function formatDelta(delta: number): string {
  if (delta === 0) return '0'
  const sign = delta > 0 ? '+' : ''
  return sign + formatNumber(delta, 0)
}
</script>

<template>
  <MainLayout>
    <div class="space-y-6">

      <!-- ============ HEADER ============ -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <div class="relative w-10 h-10">
            <svg class="w-10 h-10 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
            </svg>
            <div class="absolute inset-[-4px] border border-cyan-500/20 rounded-full orbit-ring"></div>
          </div>
          <div>
            <h1 class="text-2xl font-bold text-white tracking-wide">Interaction Planetaire</h1>
            <p class="text-sm text-slate-500 -mt-0.5">Gestion des colonies PI</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="text-xs text-slate-500">
            Derniere sync :
            <span class="text-slate-400">{{ planetaryStore.lastSyncAt ? formatTimeSince(planetaryStore.lastSyncAt) : 'jamais' }}</span>
          </span>
          <button
            @click="planetaryStore.syncColonies()"
            :disabled="isSyncing"
            class="flex items-center gap-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            title="Met a jour le cache local depuis l'ESI. Les donnees refletent votre derniere interaction en jeu."
          >
            <svg
              :class="['w-4 h-4', isSyncing ? 'animate-spin' : '']"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ isSyncing ? 'Sync...' : 'Synchroniser' }}
          </button>
        </div>
      </div>

      <!-- ============ ERROR BANNER ============ -->
      <div
        v-if="planetaryStore.error"
        class="flex items-center gap-3 px-4 py-3 bg-red-500/10 border border-red-500/30 rounded-lg"
      >
        <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <span class="text-red-400 text-sm">{{ planetaryStore.error }}</span>
        <button @click="planetaryStore.clearError()" class="ml-auto text-red-400 hover:text-red-300">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- ============ LOADING STATE ============ -->
      <div v-if="planetaryStore.isLoading && !planetaryStore.colonies.length" class="flex items-center justify-center py-20">
        <div class="flex flex-col items-center gap-4">
          <svg class="animate-spin h-10 w-10 text-cyan-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span class="text-slate-400 text-sm">Chargement des colonies...</span>
        </div>
      </div>

      <!-- ============ EMPTY STATE ============ -->
      <div
        v-else-if="!planetaryStore.isLoading && planetaryStore.colonies.length === 0 && !planetaryStore.error"
        class="flex flex-col items-center justify-center py-20 text-center"
      >
        <svg class="w-16 h-16 text-slate-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" />
        </svg>
        <h3 class="text-lg font-semibold text-slate-300 mb-2">Aucune colonie PI</h3>
        <p class="text-sm text-slate-500 max-w-md">
          Aucune colonie detectee. Cliquez sur "Synchroniser" pour charger vos donnees PI depuis l'ESI,
          ou verifiez que vos personnages ont le scope Planetary Interaction.
        </p>
      </div>

      <!-- ============ MAIN CONTENT (when data available) ============ -->
      <template v-else-if="planetaryStore.colonies.length > 0">

        <!-- ============ KPI CARDS ============ -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <!-- Colonies -->
          <div class="relative bg-slate-900 rounded-xl p-5 border border-slate-800 overflow-hidden scan-line">
            <div class="flex items-center justify-between">
              <span class="text-slate-400 text-sm font-medium uppercase tracking-wider">Colonies</span>
              <svg class="w-5 h-5 text-cyan-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" />
              </svg>
            </div>
            <div class="mt-2 text-3xl font-bold text-white font-mono-tech">{{ statsData?.totalColonies ?? planetaryStore.colonies.length }}</div>
            <div class="mt-1 text-xs text-slate-500">sur {{ totalCharacterCount }} personnage{{ totalCharacterCount > 1 ? 's' : '' }}</div>
          </div>

          <!-- Extracteurs actifs -->
          <div class="relative bg-slate-900 rounded-xl p-5 border border-emerald-500/20 overflow-hidden">
            <div class="flex items-center justify-between">
              <span class="text-emerald-400 text-sm font-medium uppercase tracking-wider">Extracteurs actifs</span>
              <svg class="w-5 h-5 text-emerald-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <div class="mt-2 flex items-baseline gap-3">
              <span class="text-3xl font-bold text-emerald-400 font-mono-tech">{{ statsData?.activeExtractors ?? 0 }}</span>
              <span v-if="(statsData?.expiredExtractors ?? 0) > 0" class="text-sm text-red-400 font-medium">
                {{ statsData?.expiredExtractors }} expire{{ (statsData?.expiredExtractors ?? 0) > 1 ? 's' : '' }}
              </span>
            </div>
            <div class="mt-1 text-xs text-slate-500">{{ statsData?.totalExtractors ?? 0 }} extracteurs au total</div>
          </div>

          <!-- Expirent bientot -->
          <div class="relative bg-slate-900 rounded-xl p-5 border border-amber-500/20 overflow-hidden">
            <div class="flex items-center justify-between">
              <span class="text-amber-400 text-sm font-medium uppercase tracking-wider">Expirent bientot</span>
              <svg class="w-5 h-5 text-amber-400/60 timer-urgent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div class="mt-2 text-3xl font-bold text-amber-400 font-mono-tech">{{ statsData?.expiringExtractors ?? 0 }}</div>
            <div class="mt-1 text-xs text-slate-500">dans les prochaines 24h</div>
          </div>

          <!-- Production theorique -->
          <div class="relative bg-slate-900 rounded-xl p-5 border border-cyan-500/20 overflow-hidden">
            <div class="flex items-center justify-between">
              <span class="text-cyan-400 text-sm font-medium uppercase tracking-wider">Production theorique/jour</span>
              <svg class="w-5 h-5 text-cyan-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
              </svg>
            </div>
            <div class="mt-2 text-3xl font-bold text-cyan-400 font-mono-tech">{{ formatIsk(statsData?.estimatedDailyIsk ?? planetaryStore.totalDailyIsk) }}</div>
            <div class="mt-1 text-xs text-slate-500 flex items-center gap-1">
              ISK / jour
              <span class="text-slate-600 cursor-help border-b border-dotted border-slate-600" title="Valorisation basee sur les ordres de vente (sell) a Jita 4-4, mises a jour toutes les 2h">prix sell Jita</span>
            </div>
          </div>
        </div>

        <!-- ============ ALERTS BANNER ============ -->
        <div v-if="planetaryStore.expiredColonies.length > 0 || planetaryStore.expiringColonies.length > 0" class="flex flex-wrap gap-2">
          <div v-if="planetaryStore.expiredColonies.length > 0" class="flex items-center gap-2 px-3 py-1.5 bg-red-500/10 border border-red-500/30 rounded-lg text-sm">
            <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></div>
            <span class="text-red-400">{{ planetaryStore.expiredColonies.length }} extracteur{{ planetaryStore.expiredColonies.length > 1 ? 's' : '' }} expire{{ planetaryStore.expiredColonies.length > 1 ? 's' : '' }}</span>
            <span class="text-red-300/60">-</span>
            <span class="text-red-300/80 text-xs">
              {{ planetaryStore.expiredColonies.map(c => `${c.solarSystemName || 'Inconnu'} (${c.planetType})`).join(', ') }}
            </span>
          </div>
          <div v-if="planetaryStore.expiringColonies.length > 0" class="flex items-center gap-2 px-3 py-1.5 bg-amber-500/10 border border-amber-500/30 rounded-lg text-sm">
            <div class="w-2 h-2 rounded-full bg-amber-500 timer-urgent"></div>
            <span class="text-amber-400">{{ planetaryStore.expiringColonies.length }} expirent dans moins de 24h</span>
          </div>
        </div>

        <!-- ============ PRODUCTION SUMMARY BY TIER ============ -->
        <div v-if="planetaryStore.production.length > 0" class="bg-slate-900 rounded-xl border border-slate-800 p-5">
          <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            Production globale par tier
          </h3>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-xs text-slate-500 uppercase tracking-wider">
                  <th class="text-left pb-3 font-medium w-6"></th>
                  <th class="text-left pb-3 font-medium">Tier</th>
                  <th class="text-right pb-3 font-medium">Produits</th>
                  <th class="text-right pb-3 font-medium">Volume / jour</th>
                  <th class="text-right pb-3 font-medium">
                    <span class="cursor-help border-b border-dotted border-slate-600" title="Prix sell Jita 4-4, rafraichis toutes les 2h">ISK / jour</span>
                  </th>
                  <th class="text-right pb-3 font-medium">% total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-800">
                <template v-for="tier in planetaryStore.production" :key="tier.tier">
                  <!-- Tier Row -->
                  <tr
                    class="cursor-pointer hover:bg-slate-800/30 transition-colors"
                    :class="{ 'opacity-40': tier.items.length === 0 }"
                    @click="toggleTier(tier.tier)"
                  >
                    <td class="py-2.5 pr-1">
                      <svg
                        :class="['w-4 h-4 transition-transform duration-200', tier.items.length === 0 ? 'text-slate-600' : 'text-slate-500']"
                        :style="expandedTiers.has(tier.tier) ? 'transform: rotate(90deg)' : ''"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                      </svg>
                    </td>
                    <td class="py-2.5">
                      <div class="flex items-center gap-2">
                        <span :class="['text-[10px] px-1.5 py-0.5 rounded border', TIER_CONFIG[tier.tier]?.badgeBg, TIER_CONFIG[tier.tier]?.badgeText, TIER_CONFIG[tier.tier]?.badgeBorder]">
                          {{ tier.tier }}
                        </span>
                        <span class="text-slate-400">{{ TIER_CONFIG[tier.tier]?.label || tier.tier }}</span>
                      </div>
                    </td>
                    <td class="py-2.5 text-right" :class="tier.items.length > 0 ? 'text-white font-medium' : 'text-slate-500'">
                      {{ tier.items.length > 0 ? tier.items.length : '0' }}
                    </td>
                    <td class="py-2.5 text-right" :class="tier.items.length > 0 ? 'text-slate-300 font-mono-tech' : 'text-slate-500'">
                      {{ tier.items.length > 0 ? formatNumber(getTierTotalVolume(tier), 0) : '-' }}
                    </td>
                    <td class="py-2.5 text-right" :class="tier.dailyIskValue > 0 ? getTierIskColor(tier.tier) + ' font-medium' : 'text-slate-500'">
                      {{ tier.dailyIskValue > 0 ? formatIsk(tier.dailyIskValue) : '-' }}
                    </td>
                    <td class="py-2.5 text-right" :class="tier.dailyIskValue > 0 ? 'text-slate-300' : 'text-slate-500'">
                      {{ tier.dailyIskValue > 0 ? getTierPercentage(tier.dailyIskValue) : '-' }}
                    </td>
                  </tr>

                  <!-- Tier Detail -->
                  <tr v-if="expandedTiers.has(tier.tier) && tier.items.length > 0">
                    <td></td>
                    <td colspan="5" class="py-2 pl-2">
                      <div class="bg-slate-800/50 rounded-lg p-3">
                        <!-- Header -->
                        <div class="flex items-center gap-4 text-[10px] text-slate-500 uppercase tracking-wider mb-2 px-1">
                          <span class="flex-1">Produit</span>
                          <span v-if="tier.tier !== 'P0'" class="w-28 text-right">Entrees</span>
                          <span class="w-14 text-right">Sortie</span>
                          <span class="w-20 text-right">ISK/j</span>
                          <span v-if="tier.tier !== 'P0'" class="w-24 text-right">Appro.</span>
                        </div>
                        <!-- Items -->
                        <div class="space-y-1.5">
                          <div
                            v-for="item in tier.items"
                            :key="item.typeId"
                            class="flex items-center gap-4 text-xs px-1"
                          >
                            <div class="flex items-center gap-2 flex-1">
                              <span class="w-1.5 h-1.5 rounded-full" :class="TIER_CONFIG[tier.tier]?.badgeText?.replace('text-', 'bg-') || 'bg-cyan-400'"></span>
                              <span class="text-slate-300">{{ item.typeName }}</span>
                              <span v-if="item.inputs?.length" class="text-slate-600 text-[10px]">
                                &larr; {{ item.inputs.map(i => i.typeName).join(' + ') }}
                              </span>
                            </div>
                            <span v-if="tier.tier !== 'P0'" class="w-28 text-right text-slate-500 font-mono-tech">
                              {{ item.inputs?.length ? formatNumber(item.inputs.reduce((s, i) => s + i.dailyConsumed, 0), 0) : '-' }}
                            </span>
                            <span class="w-14 text-right text-slate-400 font-mono-tech">{{ formatNumber(item.dailyQuantity, 0) }}/j</span>
                            <span class="w-20 text-right font-mono-tech" :class="item.dailyIskValue > 0 ? 'text-cyan-400' : 'text-slate-500'">
                              {{ item.dailyIskValue > 0 ? formatIsk(item.dailyIskValue) : '-' }}
                            </span>
                            <div v-if="tier.tier !== 'P0'" class="w-24 flex items-center gap-1.5 justify-end">
                              <template v-if="item.inputs?.length">
                                <span
                                  class="w-1.5 h-1.5 rounded-full"
                                  :class="getSupplyDeltaColor(getWorstDelta(item.inputs)).dot"
                                ></span>
                                <span
                                  class="font-mono-tech"
                                  :class="getSupplyDeltaColor(getWorstDelta(item.inputs)).text"
                                >
                                  {{ formatDelta(getWorstDelta(item.inputs)) }}
                                </span>
                              </template>
                              <span v-else class="text-slate-500">-</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
              <tfoot>
                <tr class="border-t border-slate-700">
                  <td></td>
                  <td class="py-3">
                    <span class="text-cyan-400 font-semibold">Total</span>
                  </td>
                  <td class="py-3 text-right text-cyan-400 font-bold">
                    {{ planetaryStore.production.reduce((sum, t) => sum + t.items.length, 0) }}
                  </td>
                  <td class="py-3 text-right text-slate-500">-</td>
                  <td class="py-3 text-right text-cyan-400 font-bold font-mono-tech">
                    {{ formatIsk(planetaryStore.totalDailyIsk) }}
                  </td>
                  <td class="py-3 text-right text-cyan-400 font-bold">100%</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="mt-3 pt-3 border-t border-slate-800">
            <p class="text-[11px] text-slate-500 italic">
              Production theorique : qty_per_cycle x (3600 / cycle_time) x 24, chainee via schematics SDE, valorisee aux prix sell Jita 4-4 (MAJ toutes les 2h)
            </p>
          </div>
        </div>

        <!-- ============ COLONIES BY CHARACTER ============ -->
        <div
          v-for="group in planetaryStore.coloniesByCharacter"
          :key="group.characterId"
          class="space-y-2"
        >
          <!-- Character Header -->
          <div class="flex items-center gap-3 px-2">
            <div class="w-8 h-8 rounded-full bg-slate-700 border border-slate-600 overflow-hidden flex-shrink-0">
              <img
                :src="`https://images.evetech.net/characters/${group.characterId}/portrait?size=64`"
                :alt="group.characterName"
                class="w-8 h-8 rounded-full"
              />
            </div>
            <h2 class="text-lg font-semibold text-white">{{ group.characterName }}</h2>
            <span class="text-xs px-2 py-0.5 bg-slate-800 rounded-full text-slate-400">
              {{ group.colonies.length }} colonie{{ group.colonies.length > 1 ? 's' : '' }}
            </span>
            <div class="flex-1 h-px bg-slate-800"></div>
          </div>

          <!-- Colony Cards -->
          <div class="space-y-1">
            <div
              v-for="colony in group.colonies"
              :key="colony.id"
              class="colony-card bg-slate-900 rounded-xl overflow-hidden cursor-pointer"
              :class="[getColonyBorderClass(colony), expandedColonies.has(colony.id) ? 'expanded' : '']"
              style="border-width: 1px;"
            >
              <!-- Colony Header Row -->
              <div class="px-5 py-4" @click="toggleColony(colony.id)">
                <div class="flex items-center gap-4">
                  <!-- Planet info -->
                  <div class="flex items-center gap-3 min-w-[240px]">
                    <img
                      :src="getPlanetIconUrl(colony.planetType)"
                      :alt="colony.planetType"
                      class="w-9 h-9 rounded-full"
                    />
                    <div>
                      <div class="flex items-center gap-2">
                        <span class="text-white font-semibold text-[15px]">
                          {{ colony.planetName || `${colony.solarSystemName || 'Inconnu'} ${colony.planetId}` }}
                        </span>
                        <span :class="['text-[10px] px-1.5 py-0.5 rounded-full font-medium uppercase tracking-wider border',
                          getPlanetConfig(colony.planetType).badgeBg,
                          getPlanetConfig(colony.planetType).badgeText,
                          getPlanetConfig(colony.planetType).badgeBorder
                        ]">
                          {{ colony.planetType }}
                        </span>
                      </div>
                      <div class="flex items-center gap-1.5 text-xs text-slate-500">
                        <span>{{ colony.solarSystemName || 'Inconnu' }}</span>
                        <span class="text-slate-600">|</span>
                        <span :class="getSecurityColorClass(colony.solarSystemSecurity)" class="font-medium">{{ formatSecurity(colony.solarSystemSecurity) }}</span>
                      </div>
                    </div>
                  </div>

                  <!-- Upgrade level -->
                  <div class="flex items-center gap-1.5 min-w-[90px]">
                    <span class="text-xs text-slate-500 mr-1">CC Lv</span>
                    <div class="flex gap-0.5">
                      <div
                        v-for="level in 5"
                        :key="level"
                        :class="['w-3.5 h-1.5 rounded-sm', level <= colony.upgradeLevel ? 'bg-cyan-400' : 'bg-slate-700']"
                      ></div>
                    </div>
                  </div>

                  <!-- Installations -->
                  <div class="min-w-[60px] text-center">
                    <span class="text-white font-medium">{{ colony.numPins }}</span>
                    <span class="text-slate-500 text-xs ml-1">inst.</span>
                  </div>

                  <!-- Extractor timers -->
                  <div class="flex items-center gap-3 flex-1">
                    <!-- Detail loaded: show individual extractor timers -->
                    <template v-if="colony.pins.length > 0">
                      <template v-for="pin in getExtractorPins(colony)" :key="pin.pinId">
                        <div class="flex items-center gap-1.5">
                          <div :class="['w-2 h-2 rounded-full', getTimerColorClasses(getTimerInfo(pin.expiryTime).status).dot, getTimerColorClasses(getTimerInfo(pin.expiryTime).status).pulse]"></div>
                          <span :class="['font-mono-tech text-sm', getTimerColorClasses(getTimerInfo(pin.expiryTime).status).text, getTimerInfo(pin.expiryTime).status === 'expired' ? 'font-bold' : '']">
                            {{ getTimerInfo(pin.expiryTime).formatted }}
                          </span>
                        </div>
                      </template>
                      <span v-if="getExtractorPins(colony).length === 0" class="text-slate-500 text-xs italic">
                        Aucun extracteur
                      </span>
                    </template>
                    <!-- Collection mode: show summary from colony-level metadata -->
                    <template v-else-if="colony.extractorCount > 0">
                      <div class="flex items-center gap-1.5">
                        <div :class="['w-2 h-2 rounded-full', getTimerColorClasses(getTimerInfo(colony.nearestExpiry).status).dot, getTimerColorClasses(getTimerInfo(colony.nearestExpiry).status).pulse]"></div>
                        <span :class="['font-mono-tech text-sm', getTimerColorClasses(getTimerInfo(colony.nearestExpiry).status).text, getTimerInfo(colony.nearestExpiry).status === 'expired' ? 'font-bold' : '']">
                          {{ getTimerInfo(colony.nearestExpiry).formatted }}
                        </span>
                      </div>
                      <span class="text-slate-500 text-xs">
                        &middot; {{ colony.extractorCount }} extracteur{{ colony.extractorCount > 1 ? 's' : '' }}
                      </span>
                    </template>
                    <!-- No extractors at all -->
                    <span v-else class="text-slate-500 text-xs italic">
                      Aucun extracteur
                    </span>
                  </div>

                  <!-- Last update -->
                  <div class="text-right min-w-[100px]">
                    <div class="text-xs text-slate-500">MAJ {{ formatTimeSince(colony.cachedAt) }}</div>
                    <div v-if="isStaleData(colony)" class="mt-0.5">
                      <span class="stale-badge text-[10px] text-slate-900 px-1.5 py-0.5 rounded-full font-medium">OBSOLETE</span>
                    </div>
                  </div>

                  <!-- Expand chevron -->
                  <svg
                    :class="['w-5 h-5 text-slate-500 transition-transform duration-200', expandedColonies.has(colony.id) ? 'rotate-180' : '']"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </div>
              </div>

              <!-- ============ EXPANDED COLONY DETAIL ============ -->
              <div v-if="expandedColonies.has(colony.id)" class="border-t border-slate-800">
                <div class="px-5 py-4 space-y-5">

                  <!-- Extractors Detail -->
                  <div v-if="getExtractorPins(colony).length > 0">
                    <h4 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-3 flex items-center gap-2">
                      <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                      </svg>
                      Extracteurs
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                      <div
                        v-for="pin in getExtractorPins(colony)"
                        :key="pin.pinId"
                        class="bg-slate-800/50 rounded-lg p-3 border border-slate-700"
                      >
                        <div class="flex items-center justify-between mb-2">
                          <div class="flex items-center gap-2">
                            <div :class="['w-7 h-7 rounded border flex items-center justify-center',
                              pin.outputTier ? TIER_CONFIG[pin.outputTier]?.badgeBg || 'bg-slate-700' : 'bg-slate-700',
                              pin.outputTier ? TIER_CONFIG[pin.outputTier]?.badgeBorder || 'border-slate-600' : 'border-slate-600']">
                              <span :class="['text-[9px] font-bold',
                                pin.outputTier ? TIER_CONFIG[pin.outputTier]?.badgeText || 'text-slate-400' : 'text-slate-400']">
                                {{ pin.outputTier || 'P0' }}
                              </span>
                            </div>
                            <div>
                              <span class="text-white text-sm font-medium">{{ pin.extractorProductName || pin.typeName || 'Inconnu' }}</span>
                              <div class="text-[10px] text-slate-500">Unite d'extraction</div>
                            </div>
                          </div>
                          <div class="text-right">
                            <div :class="['font-mono-tech text-lg font-bold', getTimerColorClasses(getTimerInfo(pin.expiryTime).status).text]">
                              {{ getTimerInfo(pin.expiryTime).formatted }}
                            </div>
                            <div class="text-[10px] text-slate-500">restant</div>
                          </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-xs">
                          <div class="bg-slate-800/50 rounded px-2 py-1.5">
                            <span class="text-slate-500 block">Cycle</span>
                            <span class="text-white font-medium">{{ formatCycleTime(pin.extractorCycleTime) }}</span>
                          </div>
                          <div class="bg-slate-800/50 rounded px-2 py-1.5">
                            <span class="text-slate-500 block">Qte/cycle</span>
                            <span class="text-white font-medium">{{ pin.extractorQtyPerCycle ? formatNumber(pin.extractorQtyPerCycle, 0) : '-' }}</span>
                          </div>
                          <div class="bg-slate-800/50 rounded px-2 py-1.5">
                            <span class="text-slate-500 block">Tetes d'extr.</span>
                            <span class="text-white font-medium">{{ pin.extractorNumHeads ?? '-' }}</span>
                          </div>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-xs">
                          <span class="text-slate-500" title="Debit initial. Le rendement reel decroit au cours du cycle d'extraction.">Debit initial</span>
                          <span class="text-cyan-400 font-medium">{{ formatDailyOutput(pin) }}</span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Factories Detail -->
                  <div v-if="getFactoryPins(colony).length > 0">
                    <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                      <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                      </svg>
                      Usines <span class="text-slate-500 font-normal">({{ getFactoryPins(colony).length }})</span>
                    </h4>
                    <div class="bg-slate-800/60 rounded-lg border border-slate-700/30 overflow-hidden">
                      <table class="w-full text-sm">
                        <thead>
                          <tr class="text-[10px] text-slate-500 uppercase tracking-wider border-b border-slate-700/30">
                            <th class="text-left px-3 py-2 font-medium">Schema</th>
                            <th class="text-left px-3 py-2 font-medium">Entrees</th>
                            <th class="text-left px-3 py-2 font-medium">Sortie</th>
                            <th class="text-right px-3 py-2 font-medium">Cycle</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700/20">
                          <tr v-for="pin in getFactoryPins(colony)" :key="pin.pinId" class="hover:bg-slate-700/20">
                            <td class="px-3 py-2 text-white font-medium">{{ pin.schematicName || pin.typeName || 'Inconnu' }}</td>
                            <td class="px-3 py-2 text-slate-400 text-xs">
                              <template v-if="pin.schematicInputs && pin.schematicInputs.length > 0">
                                {{ pin.schematicInputs.map(i => `${formatNumber(i.quantity, 0)} ${i.typeName}`).join(' + ') }}
                              </template>
                              <span v-else class="text-slate-500">-</span>
                            </td>
                            <td class="px-3 py-2">
                              <template v-if="pin.schematicOutput">
                                <div class="flex items-center gap-1.5">
                                  <span v-if="pin.outputTier" :class="['text-[9px] px-1 py-0.5 rounded border font-bold',
                                    TIER_CONFIG[pin.outputTier]?.badgeBg, TIER_CONFIG[pin.outputTier]?.badgeText, TIER_CONFIG[pin.outputTier]?.badgeBorder]">
                                    {{ pin.outputTier }}
                                  </span>
                                  <span :class="['text-xs', pin.outputTier ? TIER_CONFIG[pin.outputTier]?.badgeText || 'text-white' : 'text-white']">
                                    {{ formatNumber(pin.schematicOutput.quantity, 0) }} {{ pin.schematicOutput.typeName }}
                                  </span>
                                </div>
                              </template>
                              <span v-else class="text-slate-500 text-xs">-</span>
                            </td>
                            <td class="px-3 py-2 text-right text-slate-400 text-xs font-mono-tech">
                              {{ formatCycleTime(pin.schematicCycleTime) }}
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>

                  <!-- Production + Storage side by side -->
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <!-- Per-colony production summary -->
                    <div v-if="getColonyProduction(colony).length > 0">
                      <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                        Production theorique / jour
                      </h4>
                      <div class="bg-slate-800/60 rounded-lg border border-slate-700/30 overflow-hidden">
                        <div class="divide-y divide-slate-700/20">
                          <div
                            v-for="prod in getColonyProduction(colony)"
                            :key="prod.typeName"
                            class="flex items-center justify-between px-3 py-2.5"
                          >
                            <div class="flex items-center gap-1.5">
                              <span v-if="prod.outputTier" :class="['text-[9px] px-1 py-0.5 rounded border font-bold',
                                TIER_CONFIG[prod.outputTier]?.badgeBg, TIER_CONFIG[prod.outputTier]?.badgeText, TIER_CONFIG[prod.outputTier]?.badgeBorder]">
                                {{ prod.outputTier }}
                              </span>
                              <span :class="['text-sm', prod.outputTier ? TIER_CONFIG[prod.outputTier]?.badgeText || 'text-white' : 'text-white']">
                                {{ prod.typeName }}
                              </span>
                            </div>
                            <div class="text-right flex items-center gap-3">
                              <span class="text-slate-500 text-xs font-mono-tech">{{ formatNumber(prod.dailyQuantity, 0) }}/j</span>
                              <span class="text-cyan-400 text-sm font-medium font-mono-tech min-w-[70px] text-right">
                                {{ prod.dailyIskValue > 0 ? formatIsk(prod.dailyIskValue) : '-' }}
                              </span>
                            </div>
                          </div>
                        </div>
                        <!-- Total row -->
                        <div v-if="getColonyProduction(colony).length > 0" class="border-t border-slate-700/50 px-3 py-2.5 flex items-center justify-between bg-slate-800/30">
                          <span class="text-cyan-400 text-sm font-semibold">Total</span>
                          <span class="text-cyan-400 text-sm font-bold font-mono-tech">
                            {{ formatIsk(getColonyProduction(colony).reduce((sum, p) => sum + p.dailyIskValue, 0)) }}
                          </span>
                        </div>
                      </div>
                    </div>

                    <!-- Flow View (routes-based) -->
                    <div v-if="getStoragePins(colony).length > 0">
                      <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                        Flux de stockage
                      </h4>
                      <div class="space-y-2">
                        <div
                          v-for="storagePin in getStoragePins(colony)"
                          :key="storagePin.pinId"
                          class="bg-slate-800/60 rounded-lg border border-slate-700/30 overflow-hidden"
                        >
                          <!-- Pin header -->
                          <div class="px-3 py-2 flex items-center justify-between">
                            <span class="text-slate-300 text-sm font-medium">{{ storagePin.typeName || 'Stockage' }}</span>
                            <span v-if="storagePin.capacity" class="text-[10px] text-slate-500 font-mono-tech">
                              capacite : {{ formatNumber(storagePin.capacity, 0) }} m3
                            </span>
                          </div>

                          <!-- Flow data -->
                          <template v-if="colony.routes.length > 0">
                            <!-- Use v-for with single-element array to compute flowData once per storagePin -->
                            <template v-for="flowData in [getStorageFlowData(colony, storagePin)]" :key="storagePin.pinId">
                              <div class="px-3 pb-3 space-y-2">
                                <!-- Incoming flows -->
                                <div v-if="flowData.incoming.length > 0" class="space-y-1">
                                  <div class="flex items-center gap-1.5 text-[10px] text-emerald-400/70 uppercase tracking-wider font-medium">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                    </svg>
                                    Entrant
                                  </div>
                                  <div
                                    v-for="flow in flowData.incoming"
                                    :key="'in-' + flow.contentTypeId"
                                    class="flex items-center justify-between pl-4 text-xs"
                                  >
                                    <span class="text-emerald-400">{{ flow.contentTypeName }}</span>
                                    <div class="flex items-center gap-3 text-slate-400 font-mono-tech">
                                      <span>{{ formatNumber(flow.quantityPerCycle, 0) }}/cycle</span>
                                      <span v-if="flow.dailyQuantity !== null" class="text-emerald-400/80">
                                        ~{{ formatNumber(flow.dailyQuantity, 0) }}/jour
                                      </span>
                                    </div>
                                  </div>
                                </div>

                                <!-- Outgoing flows -->
                                <div v-if="flowData.outgoing.length > 0" class="space-y-1">
                                  <div class="flex items-center gap-1.5 text-[10px] text-amber-400/70 uppercase tracking-wider font-medium">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                                    </svg>
                                    Sortant
                                  </div>
                                  <div
                                    v-for="flow in flowData.outgoing"
                                    :key="'out-' + flow.contentTypeId"
                                    class="flex items-center justify-between pl-4 text-xs"
                                  >
                                    <span class="text-amber-400">{{ flow.contentTypeName }}</span>
                                    <div class="flex items-center gap-3 text-slate-400 font-mono-tech">
                                      <span>{{ formatNumber(flow.quantityPerCycle, 0) }}/cycle</span>
                                      <span v-if="flow.dailyQuantity !== null" class="text-amber-400/80">
                                        ~{{ formatNumber(flow.dailyQuantity, 0) }}/jour
                                      </span>
                                    </div>
                                  </div>
                                </div>

                                <!-- No flows -->
                                <div
                                  v-if="flowData.incoming.length === 0 && flowData.outgoing.length === 0"
                                  class="text-xs text-slate-500 italic py-1"
                                >
                                  Aucun flux configure
                                </div>

                                <!-- Net flow summary -->
                                <div
                                  v-if="flowData.incoming.length > 0 || flowData.outgoing.length > 0"
                                  class="border-t border-slate-700/30 pt-2 mt-1 flex items-center justify-between text-xs"
                                >
                                  <span class="text-slate-500">Flux net</span>
                                  <div class="flex items-center gap-3">
                                    <span :class="['font-mono-tech font-medium', getNetFlowColorClass(flowData.netDailyVolume)]">
                                      {{ formatNetFlow(flowData.netDailyVolume) }}
                                    </span>
                                    <span
                                      v-if="flowData.fillDays !== null"
                                      class="text-slate-500 font-mono-tech"
                                    >
                                      remplissage ~{{ flowData.fillDays }} jours
                                    </span>
                                  </div>
                                </div>
                              </div>
                            </template>
                          </template>

                          <!-- No routes loaded -->
                          <div v-else class="px-3 pb-2">
                            <span class="text-xs text-slate-500 italic">Chargement des routes...</span>
                          </div>
                        </div>
                      </div>
                    </div>

                  </div>

                  <!-- No detail fallback -->
                  <div v-if="getExtractorPins(colony).length === 0 && getFactoryPins(colony).length === 0 && getStoragePins(colony).length === 0">
                    <p class="text-sm text-slate-500 italic">Aucun detail disponible pour cette colonie. Synchronisez pour charger les donnees des installations.</p>
                  </div>

                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ============ PI TIER LEGEND ============ -->
        <div class="bg-slate-900 rounded-xl border border-slate-800 p-4">
          <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-6 text-xs flex-wrap">
              <span class="text-slate-500 uppercase tracking-wider font-medium">Tiers PI</span>
              <div v-for="(config, tier) in TIER_CONFIG" :key="tier" class="flex items-center gap-1.5">
                <span :class="['px-1.5 py-0.5 rounded border', config.badgeBg, config.badgeText, config.badgeBorder]">{{ tier }}</span>
                <span class="text-slate-500">{{ config.label }}</span>
              </div>
            </div>
            <div class="flex items-center gap-4 text-xs text-slate-500">
              <div class="flex items-center gap-1.5">
                <div class="w-2 h-2 rounded-full bg-emerald-400"></div>
                <span>> 24h</span>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="w-2 h-2 rounded-full bg-amber-500"></div>
                <span>&lt; 24h</span>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="w-2 h-2 rounded-full bg-red-500"></div>
                <span>Expire</span>
              </div>
            </div>
          </div>
        </div>

      </template>

    </div>
  </MainLayout>
</template>

<style scoped>
.font-mono-tech {
  font-family: 'Share Tech Mono', monospace;
}

/* Orbital ring animation */
@keyframes orbit {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.orbit-ring {
  animation: orbit 20s linear infinite;
}

/* Timer pulse for expiring soon */
@keyframes urgentPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
.timer-urgent {
  animation: urgentPulse 1.5s ease-in-out infinite;
}

/* Stale data shimmer */
@keyframes staleShimmer {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}
.stale-badge {
  background: linear-gradient(90deg, #f59e0b, #d97706, #f59e0b);
  background-size: 200% 100%;
  animation: staleShimmer 3s ease infinite;
}

/* Scan line effect on KPI */
.scan-line {
  position: relative;
}
.scan-line::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(6,182,212,0.4), transparent);
  animation: scanDown 4s linear infinite;
}
@keyframes scanDown {
  0% { top: 0; opacity: 0; }
  10% { opacity: 1; }
  90% { opacity: 1; }
  100% { top: 100%; opacity: 0; }
}

/* Colony card hover */
.colony-card {
  transition: all 0.2s ease;
  border-left: 3px solid transparent;
}
.colony-card:hover {
  background: rgba(30, 41, 59, 0.5);
  border-left-color: rgba(6, 182, 212, 0.5);
}
.colony-card.expanded {
  border-left-color: rgb(6, 182, 212);
  background: rgba(30, 41, 59, 0.5);
}
</style>

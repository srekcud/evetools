<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { useEveImages } from '@/composables/useEveImages'
import { safeJsonParse } from '@/services/api'
import MainLayout from '@/layouts/MainLayout.vue'
import type { Asset } from '@/types'

const authStore = useAuthStore()
const syncStore = useSyncStore()
const { getTypeIconUrl, onImageError } = useEveImages()

const user = computed(() => authStore.user)
const characters = computed(() => user.value?.characters || [])

// State
const selectedCharacterId = ref<string | null>(null)
const assets = ref<Asset[]>([])
const isLoading = ref(false)
const isRefreshing = ref(false)
const error = ref('')
const searchQuery = ref('')
const viewMode = ref<'character' | 'corporation'>('character')
const selectedSolarSystem = ref<string>('')
const selectedLocation = ref<string>('')
const expandedContainers = ref<Set<number>>(new Set())
const collapsedLocations = ref<Set<string>>(new Set())


// Get unique solar systems (only those with proper names)
const solarSystems = computed(() => {
  const systems = new Set<string>()
  for (const asset of assets.value) {
    if (asset.solarSystemName) {
      systems.add(asset.solarSystemName)
    }
  }
  return Array.from(systems).sort()
})

// Get unique locations (filtered by selected solar system, exclude "Structure #xxx")
const locations = computed(() => {
  const locs = new Map<string, { solarSystemName: string | null }>()
  for (const asset of assets.value) {
    // Skip locations that look like unresolved IDs
    if (asset.locationName.startsWith('Structure #') || asset.locationName.startsWith('Location #')) {
      continue
    }
    if (!locs.has(asset.locationName)) {
      locs.set(asset.locationName, {
        solarSystemName: asset.solarSystemName
      })
    }
  }

  let result = Array.from(locs.entries()).map(([name, data]) => ({
    name,
    solarSystemName: data.solarSystemName
  }))

  // Filter by selected solar system
  if (selectedSolarSystem.value) {
    result = result.filter(loc => loc.solarSystemName === selectedSolarSystem.value)
  }

  return result.sort((a, b) => a.name.localeCompare(b.name))
})

// Build a set of all item_ids that exist in our asset list
const existingItemIds = computed(() => {
  return new Set(assets.value.map(a => a.itemId))
})

// Build a map of container item_id -> items inside it
const containerContents = computed(() => {
  const contents = new Map<number, Asset[]>()
  for (const asset of assets.value) {
    if (asset.locationType === 'item' && existingItemIds.value.has(asset.locationId)) {
      // This item is inside a container that exists in our asset list
      const containerId = asset.locationId
      if (!contents.has(containerId)) {
        contents.set(containerId, [])
      }
      contents.get(containerId)!.push(asset)
    }
  }
  return contents
})

// Check if an asset is a container (has items inside it)
function isContainer(asset: Asset): boolean {
  return containerContents.value.has(asset.itemId)
}

// Get container contents
function getContainerContents(asset: Asset): Asset[] {
  return containerContents.value.get(asset.itemId) || []
}

// Toggle container expansion
function toggleContainer(itemId: number) {
  if (expandedContainers.value.has(itemId)) {
    expandedContainers.value.delete(itemId)
  } else {
    expandedContainers.value.add(itemId)
  }
  expandedContainers.value = new Set(expandedContainers.value)
}

// Toggle location collapse
function toggleLocation(locationName: string) {
  if (collapsedLocations.value.has(locationName)) {
    collapsedLocations.value.delete(locationName)
  } else {
    collapsedLocations.value.add(locationName)
  }
  collapsedLocations.value = new Set(collapsedLocations.value)
}

// Filter assets by search, solar system, and location
const filteredAssets = computed(() => {
  let result = assets.value

  // Filter by solar system
  if (selectedSolarSystem.value) {
    result = result.filter(a => a.solarSystemName === selectedSolarSystem.value)
  }

  // Filter by location name
  if (selectedLocation.value) {
    result = result.filter(a => a.locationName === selectedLocation.value)
  }

  // Filter by search query
  if (searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(a =>
      a.typeName.toLowerCase().includes(query) ||
      a.locationName.toLowerCase().includes(query) ||
      (a.solarSystemName && a.solarSystemName.toLowerCase().includes(query))
    )
  }

  return result
})

// Group assets by location
const assetsByLocation = computed(() => {
  const grouped: Record<string, {
    locationId: number
    locationName: string
    solarSystemName: string | null
    assets: Asset[]
    totalQuantity: number
  }> = {}

  for (const asset of filteredAssets.value) {
    // Skip items that are inside containers that exist in our asset list
    // (they'll be shown when the container is expanded)
    if (asset.locationType === 'item' && existingItemIds.value.has(asset.locationId)) {
      continue
    }

    const key = asset.locationName || `Location ${asset.locationId}`
    if (!grouped[key]) {
      grouped[key] = {
        locationId: asset.locationId,
        locationName: key,
        solarSystemName: asset.solarSystemName,
        assets: [],
        totalQuantity: 0
      }
    }
    grouped[key].assets.push(asset)
    grouped[key].totalQuantity += asset.quantity

    // Add container contents to total
    if (isContainer(asset)) {
      for (const item of getContainerContents(asset)) {
        grouped[key].totalQuantity += item.quantity
      }
    }
  }

  // Sort by location name
  return Object.values(grouped).sort((a, b) => a.locationName.localeCompare(b.locationName))
})

// Reset location filter when solar system changes
watch(selectedSolarSystem, () => {
  selectedLocation.value = ''
})

// Stats
const totalItems = computed(() => assets.value.reduce((sum, a) => sum + a.quantity, 0))
const totalTypes = computed(() => new Set(assets.value.map(a => a.typeId)).size)
const totalLocations = computed(() => new Set(assets.value.map(a => a.locationId)).size)
const totalSystems = computed(() => new Set(assets.value.filter(a => a.solarSystemName).map(a => a.solarSystemName)).size)

// Fetch assets
async function fetchAssets() {
  if (viewMode.value === 'character' && !selectedCharacterId.value) return

  isLoading.value = true
  error.value = ''

  try {
    const endpoint = viewMode.value === 'character'
      ? `/api/me/characters/${selectedCharacterId.value}/assets`
      : '/api/me/corporation/assets'

    const response = await fetch(endpoint, {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (!response.ok) throw new Error('Failed to fetch assets')

    const data = await safeJsonParse<{ items?: Asset[] }>(response)
    assets.value = data.items || []
  } catch (e) {
    error.value = 'Failed to load assets'
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

// Get current sync type based on view mode
const currentSyncType = computed(() =>
  viewMode.value === 'character' ? 'character-assets' : 'corporation-assets'
)

// Get current sync progress from Mercure
const currentSyncProgress = computed(() =>
  syncStore.getSyncProgress(currentSyncType.value)
)

// Watch for sync completion via Mercure
watch(
  () => currentSyncProgress.value,
  (progress) => {
    if (!progress) return

    if (progress.status === 'completed') {
      // Sync completed - fetch fresh data
      fetchAssets()
      isRefreshing.value = false
      syncStore.clearSyncStatus(currentSyncType.value)
    } else if (progress.status === 'error') {
      // Sync failed
      error.value = progress.message || 'Sync failed'
      isRefreshing.value = false
      syncStore.clearSyncStatus(currentSyncType.value)
    } else if (progress.status === 'started' || progress.status === 'in_progress') {
      // Sync in progress
      isRefreshing.value = true
    }
  }
)

// Refresh assets
async function refreshAssets() {
  if (viewMode.value === 'character' && !selectedCharacterId.value) return

  isRefreshing.value = true
  error.value = ''

  try {
    const endpoint = viewMode.value === 'character'
      ? `/api/me/characters/${selectedCharacterId.value}/assets/refresh`
      : '/api/me/corporation/assets/refresh'

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({})
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response)
      throw new Error(data.error || 'Failed to refresh')
    }

    const data = await safeJsonParse<{ status?: string }>(response)

    if (data.status === 'pending') {
      // Async sync started - Mercure will notify us of progress
      // If Mercure is not connected, fall back to polling
      if (!syncStore.isConnected) {
        let attempts = 0
        const maxAttempts = 12 // Max 60 seconds (12 * 5s)
        const poll = async () => {
          attempts++
          await fetchAssets()

          // Check if we have fresh data (within last 2 minutes)
          const hasRecentData = assets.value.length > 0

          if (hasRecentData || attempts >= maxAttempts) {
            isRefreshing.value = false
          } else {
            setTimeout(poll, 5000) // Poll every 5 seconds
          }
        }
        setTimeout(poll, 3000) // First check after 3 seconds
      }
      // Otherwise, the Mercure watcher will handle completion
    } else {
      // Sync completed immediately, reload assets
      await fetchAssets()
      isRefreshing.value = false
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to refresh assets'
    isRefreshing.value = false
  }
}

// Initialize - also watch for characters loading after mount
onMounted(() => {
  if (characters.value.length > 0) {
    const main = characters.value.find(c => c.isMain)
    selectedCharacterId.value = main?.id || characters.value[0].id
  }
})

// Watch for characters becoming available (handles race condition on login)
watch(characters, (newChars) => {
  if (newChars.length > 0 && !selectedCharacterId.value) {
    const main = newChars.find(c => c.isMain)
    selectedCharacterId.value = main?.id || newChars[0].id
  }
}, { immediate: true })

// Watch for character/mode changes
watch([selectedCharacterId, viewMode], () => {
  // Reset filters when switching character or mode
  selectedSolarSystem.value = ''
  selectedLocation.value = ''
  searchQuery.value = ''
  expandedContainers.value = new Set()
  collapsedLocations.value = new Set()
  fetchAssets()
}, { immediate: true })

function formatNumber(n: number): string {
  return n.toLocaleString()
}

// Format asset display name: "Custom Name (Type)" or just "Type"
function getDisplayName(asset: Asset): string {
  if (asset.itemName) {
    return `${asset.itemName} (${asset.typeName})`
  }
  return asset.typeName
}

</script>

<template>
  <MainLayout>
      <!-- Action bar -->
      <div class="flex items-center justify-between mb-6">
        <p class="text-slate-400">Parcourir vos assets EVE</p>
        <!-- Refresh button -->
        <button
          @click="refreshAssets"
          :disabled="isRefreshing || isLoading"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <svg :class="['w-4 h-4', isRefreshing && 'animate-spin']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ isRefreshing ? 'Sync...' : 'Rafraîchir' }}
        </button>
      </div>

      <!-- Sync progress bar (Mercure real-time) -->
      <div v-if="currentSyncProgress && (currentSyncProgress.status === 'started' || currentSyncProgress.status === 'in_progress')" class="mb-6">
        <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-slate-300">{{ currentSyncProgress.message || 'Synchronisation...' }}</span>
            <span v-if="currentSyncProgress.progress !== null" class="text-sm text-cyan-400 font-mono">{{ currentSyncProgress.progress }}%</span>
          </div>
          <div class="h-2 bg-slate-800 rounded-full overflow-hidden">
            <div
              class="h-full bg-gradient-to-r from-cyan-500 to-cyan-400 transition-all duration-300"
              :style="{ width: (currentSyncProgress.progress ?? 0) + '%' }"
            ></div>
          </div>
        </div>
      </div>

      <div>
      <!-- Error message -->
      <div v-if="error" class="mb-6 p-4 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400 flex items-center justify-between">
        <span>{{ error }}</span>
        <button @click="error = ''" class="text-red-400 hover:text-red-300">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <!-- Controls -->
      <div class="flex flex-col gap-4 mb-6">
        <!-- Row 1: View mode, character selector, search -->
        <div class="flex flex-col sm:flex-row gap-4">
          <!-- View mode tabs -->
          <div class="flex bg-slate-900 rounded-lg p-1">
            <button
              @click="viewMode = 'character'"
              :class="['px-4 py-2 rounded-md text-sm font-medium transition-colors', viewMode === 'character' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-slate-200']"
            >
              Personnage
            </button>
            <button
              @click="viewMode = 'corporation'"
              :class="['px-4 py-2 rounded-md text-sm font-medium transition-colors', viewMode === 'corporation' ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-slate-200']"
            >
              Corporation
            </button>
          </div>

          <!-- Character selector -->
          <select
            v-if="viewMode === 'character'"
            v-model="selectedCharacterId"
            class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-cyan-500"
          >
            <option v-for="char in characters" :key="char.id" :value="char.id">
              {{ char.name }} {{ char.isMain ? '(Main)' : '' }}
            </option>
          </select>

          <!-- Search -->
          <div class="flex-1 relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Rechercher items, emplacements ou systèmes..."
              class="w-full bg-slate-900 border border-slate-700 rounded-lg pl-10 pr-4 py-2 text-sm focus:outline-none focus:border-cyan-500"
            />
          </div>
        </div>

        <!-- Row 2: Location filters -->
        <div class="flex flex-col sm:flex-row gap-4">
          <!-- Solar System filter -->
          <div class="relative">
            <select
              v-model="selectedSolarSystem"
              class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 pr-8 text-sm focus:outline-none focus:border-cyan-500 min-w-[200px]"
            >
              <option value="">Tous les systèmes ({{ solarSystems.length }})</option>
              <option v-for="system in solarSystems" :key="system" :value="system">
                {{ system }}
              </option>
            </select>
          </div>

          <!-- Location/Structure filter -->
          <div class="relative">
            <select
              v-model="selectedLocation"
              class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 pr-8 text-sm focus:outline-none focus:border-cyan-500 min-w-[250px]"
            >
              <option value="">Tous les emplacements ({{ locations.length }})</option>
              <option v-for="loc in locations" :key="loc.name" :value="loc.name">
                {{ loc.name }}
              </option>
            </select>
          </div>

          <!-- Clear filters button -->
          <button
            v-if="selectedSolarSystem || selectedLocation || searchQuery"
            @click="selectedSolarSystem = ''; selectedLocation = ''; searchQuery = ''"
            class="px-3 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-400 hover:text-slate-200 text-sm flex items-center gap-2"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Effacer filtres
          </button>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
          <p class="text-2xl font-bold text-cyan-400">{{ formatNumber(totalItems) }}</p>
          <p class="text-sm text-slate-500">Items total</p>
        </div>
        <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
          <p class="text-2xl font-bold text-indigo-400">{{ formatNumber(totalTypes) }}</p>
          <p class="text-sm text-slate-500">Types d'items</p>
        </div>
        <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
          <p class="text-2xl font-bold text-amber-400">{{ formatNumber(totalSystems) }}</p>
          <p class="text-sm text-slate-500">Systèmes</p>
        </div>
        <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
          <p class="text-2xl font-bold text-emerald-400">{{ formatNumber(totalLocations) }}</p>
          <p class="text-sm text-slate-500">Emplacements</p>
        </div>
      </div>

      <!-- Loading state -->
      <div v-if="isLoading" class="flex flex-col items-center justify-center py-20">
        <svg class="w-10 h-10 animate-spin text-cyan-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        <p class="text-slate-400">Chargement des assets...</p>
      </div>

      <!-- Empty state -->
      <div v-else-if="assets.length === 0" class="text-center py-20">
        <template v-if="isRefreshing">
          <svg class="w-16 h-16 mx-auto text-cyan-500 mb-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          <p class="text-slate-300 font-medium mb-2">Synchronisation en cours...</p>
          <p class="text-slate-500 text-sm max-w-md mx-auto">
            Récupération de vos assets depuis l'API EVE Online. Cette opération peut prendre quelques minutes lors de la première synchronisation.
          </p>
        </template>
        <template v-else>
          <svg class="w-16 h-16 mx-auto text-slate-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
          </svg>
          <p class="text-slate-500 mb-4">Aucun asset trouvé</p>
          <button
            @click="refreshAssets"
            :disabled="isRefreshing"
            class="text-cyan-400 hover:underline text-sm"
          >
            Rafraîchir depuis l'API EVE
          </button>
        </template>
      </div>

      <!-- Assets by location -->
      <div v-else class="space-y-4">
        <div
          v-for="location in assetsByLocation"
          :key="location.locationName"
          class="bg-slate-900 rounded-lg border border-slate-800 overflow-hidden"
        >
          <!-- Location header (clickable to collapse) -->
          <div
            class="px-5 py-4 bg-slate-800/50 border-b border-slate-800 flex items-center justify-between cursor-pointer hover:bg-slate-800/70 transition-colors"
            @click="toggleLocation(location.locationName)"
          >
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg bg-slate-700/50 flex items-center justify-center">
                <svg
                  :class="['w-5 h-5 text-slate-400 transition-transform', collapsedLocations.has(location.locationName) && '-rotate-90']"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </div>
              <div>
                <h3 class="font-medium text-slate-200">{{ location.locationName }}</h3>
                <div class="flex items-center gap-2">
                  <span v-if="location.solarSystemName && !location.locationName.startsWith(location.solarSystemName)" class="text-xs text-cyan-400">{{ location.solarSystemName }}</span>
                  <span class="text-xs text-slate-500">{{ location.assets.length }} types d'items</span>
                </div>
              </div>
            </div>
            <span class="text-sm text-cyan-400 font-mono">{{ formatNumber(location.totalQuantity) }} items</span>
          </div>

          <!-- Items (collapsible) -->
          <div v-if="!collapsedLocations.has(location.locationName)" class="divide-y divide-slate-800">
            <template v-for="asset in location.assets" :key="asset.id">
              <!-- Regular item or container header -->
              <div
                :class="[
                  'px-5 py-3 flex items-center justify-between transition-colors',
                  isContainer(asset) ? 'hover:bg-slate-800/50 cursor-pointer' : 'hover:bg-slate-800/30'
                ]"
                @click="isContainer(asset) && toggleContainer(asset.itemId)"
              >
                <div class="flex items-center gap-3">
                  <img
                    :src="getTypeIconUrl(asset.typeId, 32)"
                    :alt="asset.typeName"
                    class="w-8 h-8 rounded"
                    @error="onImageError"
                  />
                  <div class="flex items-center gap-2">
                    <span class="text-slate-200">{{ getDisplayName(asset) }}</span>
                    <!-- Container indicator -->
                    <span v-if="isContainer(asset)" class="flex items-center gap-1 text-xs text-amber-400">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                      </svg>
                      {{ getContainerContents(asset).length }} items
                      <svg
                        :class="['w-3 h-3 transition-transform', expandedContainers.has(asset.itemId) && 'rotate-180']"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                      </svg>
                    </span>
                    <span v-else-if="asset.locationFlag" class="text-xs text-slate-500">({{ asset.locationFlag }})</span>
                  </div>
                </div>
                <span class="text-slate-400 font-mono text-sm">x{{ formatNumber(asset.quantity) }}</span>
              </div>

              <!-- Container contents (when expanded) -->
              <div
                v-if="isContainer(asset) && expandedContainers.has(asset.itemId)"
                class="bg-slate-950/50 border-l-2 border-amber-500/30"
              >
                <div
                  v-for="item in getContainerContents(asset)"
                  :key="item.id"
                  class="px-5 py-2 pl-12 flex items-center justify-between hover:bg-slate-800/20 transition-colors"
                >
                  <div class="flex items-center gap-3">
                    <img
                      :src="getTypeIconUrl(item.typeId, 32)"
                      :alt="item.typeName"
                      class="w-6 h-6 rounded"
                      @error="onImageError"
                    />
                    <span class="text-slate-300 text-sm">{{ getDisplayName(item) }}</span>
                  </div>
                  <span class="text-slate-500 font-mono text-sm">x{{ formatNumber(item.quantity) }}</span>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- No results -->
      <div v-if="!isLoading && assets.length > 0 && filteredAssets.length === 0" class="text-center py-12">
        <p class="text-slate-500">Aucun item correspondant à vos filtres</p>
      </div>
    </div>
  </MainLayout>
</template>

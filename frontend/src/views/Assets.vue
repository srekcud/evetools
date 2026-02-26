<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { safeJsonParse } from '@/services/api'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'
import AssetsStatsCards from '@/components/assets/AssetsStatsCards.vue'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import ContractsTab from '@/components/assets/ContractsTab.vue'
import AssetDistributionChart from '@/components/assets/AssetDistributionChart.vue'
import CorpVisibilityPanel from '@/components/assets/CorpVisibilityPanel.vue'
import type { Asset, CorpAssetVisibility } from '@/types'

const { t } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const syncStore = useSyncStore()

// Tab state
type AssetTabId = 'assets' | 'contracts'
const validTabs: AssetTabId[] = ['assets', 'contracts']
const activeTab = ref<AssetTabId>('assets')

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

// Corp visibility
const visibility = ref<CorpAssetVisibility | null>(null)
const visibilityPanel = ref<InstanceType<typeof CorpVisibilityPanel> | null>(null)

const isCorpView = computed(() => viewMode.value === 'corporation')
const isDirector = computed(() => visibility.value?.isDirector === true)
const noDivisionsShared = computed(() =>
  isCorpView.value
  && !isDirector.value
  && visibility.value != null
  && visibility.value.visibleDivisions.length === 0
  && visibility.value.configuredByName == null
)

// Get unique solar systems
const solarSystems = computed(() => {
  const systems = new Set<string>()
  for (const asset of assets.value) {
    if (asset.solarSystemName) {
      systems.add(asset.solarSystemName)
    }
  }
  return Array.from(systems).sort()
})

// Get unique locations (filtered by selected solar system)
const locations = computed(() => {
  const locs = new Map<string, { solarSystemName: string | null }>()
  for (const asset of assets.value) {
    if (asset.locationName.startsWith('Structure #') || asset.locationName.startsWith('Location #')) {
      continue
    }
    if (!locs.has(asset.locationName)) {
      locs.set(asset.locationName, { solarSystemName: asset.solarSystemName })
    }
  }

  let result = Array.from(locs.entries()).map(([name, data]) => ({
    name,
    solarSystemName: data.solarSystemName
  }))

  if (selectedSolarSystem.value) {
    result = result.filter(loc => loc.solarSystemName === selectedSolarSystem.value)
  }

  return result.sort((a, b) => a.name.localeCompare(b.name))
})

// Build a set of all item_ids
const existingItemIds = computed(() => new Set(assets.value.map(a => a.itemId)))

// Build a map of container item_id -> items inside it
const containerContents = computed(() => {
  const contents = new Map<number, Asset[]>()
  for (const asset of assets.value) {
    if (asset.locationType === 'item' && existingItemIds.value.has(asset.locationId)) {
      const containerId = asset.locationId
      if (!contents.has(containerId)) {
        contents.set(containerId, [])
      }
      contents.get(containerId)!.push(asset)
    }
  }
  return contents
})

function isContainer(asset: Asset): boolean {
  return containerContents.value.has(asset.itemId)
}

function getContainerContents(asset: Asset): Asset[] {
  return containerContents.value.get(asset.itemId) || []
}

function toggleContainer(itemId: number) {
  if (expandedContainers.value.has(itemId)) {
    expandedContainers.value.delete(itemId)
  } else {
    expandedContainers.value.add(itemId)
  }
  expandedContainers.value = new Set(expandedContainers.value)
}

function toggleLocation(locationName: string) {
  if (collapsedLocations.value.has(locationName)) {
    collapsedLocations.value.delete(locationName)
  } else {
    collapsedLocations.value.add(locationName)
  }
  collapsedLocations.value = new Set(collapsedLocations.value)
}

// Filter assets
const filteredAssets = computed(() => {
  let result = assets.value

  if (selectedSolarSystem.value) {
    result = result.filter(a => a.solarSystemName === selectedSolarSystem.value)
  }
  if (selectedLocation.value) {
    result = result.filter(a => a.locationName === selectedLocation.value)
  }
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

    if (isContainer(asset)) {
      for (const item of getContainerContents(asset)) {
        grouped[key].totalQuantity += item.quantity
      }
    }
  }

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

// Corp visibility
async function fetchVisibility() {
  try {
    const response = await fetch('/api/me/corporation/assets/visibility', {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })
    if (!response.ok) throw new Error('Failed to fetch visibility')
    visibility.value = await safeJsonParse<CorpAssetVisibility>(response)
  } catch (e) {
    console.error('Failed to load corp visibility:', e)
    visibility.value = null
  }
}

async function saveVisibility(divisions: number[]) {
  try {
    const response = await fetch('/api/me/corporation/assets/visibility', {
      method: 'PUT',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/merge-patch+json'
      },
      body: JSON.stringify({ visibleDivisions: divisions })
    })
    if (!response.ok) throw new Error('Failed to save visibility')
    visibilityPanel.value?.onSaveComplete()
    await fetchVisibility()
    await fetchAssets()
  } catch (e) {
    visibilityPanel.value?.onSaveError()
    error.value = e instanceof Error ? e.message : 'Failed to save visibility'
  }
}

// Sync progress
const currentSyncType = computed(() =>
  viewMode.value === 'character' ? 'character-assets' : 'corporation-assets'
)

const currentSyncProgress = computed(() =>
  syncStore.getSyncProgress(currentSyncType.value)
)

watch(
  () => currentSyncProgress.value,
  (progress) => {
    if (!progress) return
    if (progress.status === 'completed') {
      fetchAssets()
      isRefreshing.value = false
      syncStore.clearSyncStatus(currentSyncType.value)
    } else if (progress.status === 'error') {
      error.value = progress.message || 'Sync failed'
      isRefreshing.value = false
      syncStore.clearSyncStatus(currentSyncType.value)
    } else if (progress.status === 'started' || progress.status === 'in_progress') {
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
      if (!syncStore.isConnected) {
        let attempts = 0
        const maxAttempts = 12
        const poll = async () => {
          attempts++
          await fetchAssets()
          const hasRecentData = assets.value.length > 0
          if (hasRecentData || attempts >= maxAttempts) {
            isRefreshing.value = false
          } else {
            setTimeout(poll, 5000)
          }
        }
        setTimeout(poll, 3000)
      }
    } else {
      await fetchAssets()
      isRefreshing.value = false
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to refresh assets'
    isRefreshing.value = false
  }
}

function clearFilters() {
  selectedSolarSystem.value = ''
  selectedLocation.value = ''
  searchQuery.value = ''
}

// Initialize
onMounted(() => {
  // Deep linking: read tab from query param
  const tabParam = route.query.tab as string | undefined
  if (tabParam && validTabs.includes(tabParam as AssetTabId)) {
    activeTab.value = tabParam as AssetTabId
  }

  if (characters.value.length > 0) {
    const main = characters.value.find(c => c.isMain)
    selectedCharacterId.value = main?.id || characters.value[0].id
  }
})

watch(characters, (newChars) => {
  if (newChars.length > 0 && !selectedCharacterId.value) {
    const main = newChars.find(c => c.isMain)
    selectedCharacterId.value = main?.id || newChars[0].id
  }
}, { immediate: true })

watch([selectedCharacterId, viewMode], () => {
  selectedSolarSystem.value = ''
  selectedLocation.value = ''
  searchQuery.value = ''
  expandedContainers.value = new Set()
  collapsedLocations.value = new Set()
  if (viewMode.value === 'corporation') {
    fetchVisibility()
  } else {
    visibility.value = null
  }
  fetchAssets()
}, { immediate: true })
</script>

<template>
  <MainLayout>
    <div class="space-y-6">
      <!-- Tabs -->
      <div class="border-b border-slate-800">
        <nav class="flex gap-6">
          <button
            v-for="tab in [
              { id: 'assets', label: t('assets.tabs.assets') },
              { id: 'contracts', label: t('assets.tabs.contracts') },
            ]"
            :key="tab.id"
            @click="activeTab = tab.id as AssetTabId"
            :class="[
              'pb-3 text-sm font-medium border-b-2 transition-colors',
              activeTab === tab.id
                ? 'border-cyan-500 text-cyan-400'
                : 'border-transparent text-slate-400 hover:text-slate-200'
            ]"
          >
            {{ tab.label }}
          </button>
        </nav>
      </div>

      <!-- Assets Tab -->
      <div v-if="activeTab === 'assets'" key="assets" class="space-y-6">
        <!-- Action bar -->
        <div class="flex items-center justify-between">
          <p class="text-slate-400">{{ t('assets.subtitle') }}</p>
          <button
            @click="refreshAssets"
            :disabled="isRefreshing || isLoading"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <svg :class="['w-4 h-4', isRefreshing && 'animate-spin']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            {{ isRefreshing ? t('common.actions.syncing') : t('common.actions.refresh') }}
          </button>
        </div>

        <!-- Sync progress bar (Mercure real-time) -->
        <div v-if="currentSyncProgress && (currentSyncProgress.status === 'started' || currentSyncProgress.status === 'in_progress')">
          <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm text-slate-300">{{ currentSyncProgress.message || t('assets.syncing') }}</span>
              <span v-if="currentSyncProgress.progress !== null" class="text-sm text-cyan-400 font-mono">{{ currentSyncProgress.progress }}%</span>
            </div>
            <div class="h-2 bg-slate-800 rounded-full overflow-hidden">
              <div
                class="h-full bg-linear-to-r from-cyan-500 to-cyan-400 transition-all duration-300"
                :style="{ width: (currentSyncProgress.progress ?? 0) + '%' }"
              ></div>
            </div>
          </div>
        </div>

        <!-- Corp visibility panel (Director only) -->
        <CorpVisibilityPanel
          v-if="isCorpView && isDirector && visibility"
          ref="visibilityPanel"
          :visibility="visibility"
          @save="saveVisibility"
        />

        <!-- Error message -->
        <ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />

        <!-- No divisions shared (non-director, no config) -->
        <div v-if="noDivisionsShared" class="text-center py-20">
          <svg class="w-16 h-16 mx-auto text-slate-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
          <p class="text-slate-500">{{ t('assets.visibility.noDivisionsShared') }}</p>
        </div>

        <!-- Filters, stats, and assets list (hidden when no divisions shared) -->
        <template v-if="!noDivisionsShared">
          <!-- Filters -->
          <AssetsFilterBar
            :view-mode="viewMode"
            :characters="characters"
            :selected-character-id="selectedCharacterId"
            :search-query="searchQuery"
            :solar-systems="solarSystems"
            :locations="locations"
            :selected-solar-system="selectedSolarSystem"
            :selected-location="selectedLocation"
            @update:view-mode="viewMode = $event"
            @update:selected-character-id="selectedCharacterId = $event"
            @update:search-query="searchQuery = $event"
            @update:selected-solar-system="selectedSolarSystem = $event"
            @update:selected-location="selectedLocation = $event"
            @clear-filters="clearFilters"
          />

          <!-- Stats -->
          <AssetsStatsCards
            :total-items="totalItems"
            :total-types="totalTypes"
            :total-systems="totalSystems"
            :total-locations="totalLocations"
          />

          <!-- Distribution chart -->
          <AssetDistributionChart
            v-if="!isLoading && assetsByLocation.length > 0"
            :location-groups="assetsByLocation"
          />

          <!-- Loading state -->
          <div v-if="isLoading" class="flex flex-col items-center justify-center py-20">
            <svg class="w-10 h-10 animate-spin text-cyan-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <p class="text-slate-400">{{ t('assets.loading') }}</p>
          </div>

          <!-- Empty state -->
          <div v-else-if="assets.length === 0" class="text-center py-20">
            <template v-if="isRefreshing">
              <svg class="w-16 h-16 mx-auto text-cyan-500 mb-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <p class="text-slate-300 font-medium mb-2">{{ t('assets.syncInProgress') }}</p>
              <p class="text-slate-500 text-sm max-w-md mx-auto">
                {{ t('assets.syncDescription') }}
              </p>
            </template>
            <template v-else>
              <svg class="w-16 h-16 mx-auto text-slate-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
              <p class="text-slate-500 mb-4">{{ t('assets.noAssets') }}</p>
              <button
                @click="refreshAssets"
                :disabled="isRefreshing"
                class="text-cyan-400 hover:underline text-sm"
              >
                {{ t('assets.refreshFromEve') }}
              </button>
            </template>
          </div>

          <!-- Assets by location -->
          <div v-else class="space-y-4">
            <AssetLocationGroup
              v-for="location in assetsByLocation"
              :key="location.locationName"
              :location="location"
              :expanded-containers="expandedContainers"
              :collapsed-locations="collapsedLocations"
              :container-contents="containerContents"
              @toggle-container="toggleContainer"
              @toggle-location="toggleLocation"
            />
          </div>

          <!-- No results -->
          <div v-if="!isLoading && assets.length > 0 && filteredAssets.length === 0" class="text-center py-12">
            <p class="text-slate-500">{{ t('assets.noMatchingItems') }}</p>
          </div>
        </template>
      </div>

      <!-- Contracts Tab -->
      <div v-else-if="activeTab === 'contracts'" key="contracts">
        <ContractsTab />
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, shallowRef, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { readAssetFilterQuery, readLastCharacterId, rememberLastCharacterId, writeAssetFilterQuery } from '@/utils/assetFilterQuery'
import { isUnresolvedLocationName, isUnresolvedStructureName } from '@/utils/assetLocation'
import { summarizeAssetSafety } from '@/utils/assetSafety'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { usePilotName } from '@/composables/usePilotName'
import { ApiError, apiRequest, carriesServerMessage } from '@/services/api'
import { SYNC_EVENT_TIMEOUT_MS } from '@/constants/syncConstants'
import { COLLAPSE_LOCATIONS_ROW_THRESHOLD, FRESHNESS_TICK_MS, SHIP_CATEGORY_ID } from '@/constants/assetsConstants'
import { useFormatters } from '@/composables/useFormatters'
import { useTypeNames } from '@/composables/useTypeNames'
import { normalizeSearchText } from '@/utils/commandPaletteSearch'
import { filterTypeGroups, groupAssetsByType } from '@/utils/assetsByType'
import { summarizeSelection } from '@/utils/assetSelection'
import { useCorporationAssetVisibility, type CorporationVisibilityPayload } from '@/composables/useCorporationAssetVisibility'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'
import AssetsStatsCards from '@/components/assets/AssetsStatsCards.vue'
import AssetSafetyBlock from '@/components/assets/AssetSafetyBlock.vue'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import AssetTypeView from '@/components/assets/AssetTypeView.vue'
import AssetSelectionBar from '@/components/assets/AssetSelectionBar.vue'
import AssetDistributionChart from '@/components/assets/AssetDistributionChart.vue'
import CorpVisibilityPanel from '@/components/assets/CorpVisibilityPanel.vue'
import PatrimoineModuleTabs from '@/components/patrimoine/PatrimoineModuleTabs.vue'
import type { Asset } from '@/types'

type LocationSort = 'items' | 'name'

interface DivisionCount {
  name: string
  count: number
}

interface LocationSummary {
  locationId: number
  locationType: string
  locationName: string
  solarSystemName: string | null
  assets: Asset[]
  totalQuantity: number
  shipCount: number
  divisions: DivisionCount[]
}
import { useFlash } from '@/composables/useUpdateFlash'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { pilotName } = usePilotName()
const authStore = useAuthStore()
const { typeNameLabel } = useTypeNames()
const { formatTimeSince } = useFormatters()
const syncStore = useSyncStore()

const user = computed(() => authStore.user)
const characters = computed(() => user.value?.characters || [])
const urlFilters = readAssetFilterQuery(route.query)

// State
const selectedCharacterId = ref<string | null>(null)
const assets = shallowRef<Asset[]>([])
const isLoading = ref(false)
const isReloading = ref(false)
const hasLoadedCurrentOwner = ref(false)
let pendingAssetsRequest: AbortController | null = null
const isRefreshing = ref(false)
const error = ref('')
const refreshNotice = ref('')
const nextRefreshAt = ref<string | null>(null)
const now = ref(Date.now())
const searchQuery = ref(urlFilters.search)
const viewMode = ref(urlFilters.viewMode)
const groupBy = ref<'location' | 'type'>('location')
const selectedSolarSystem = ref<string>(urlFilters.solarSystem)
const selectedLocation = ref<string>(urlFilters.location)
const selectedDivision = ref<string>(urlFilters.division)
const expandedContainers = ref<Set<number>>(new Set())
const collapsedLocations = ref<Set<string>>(new Set())
const selectedLocationNames = ref<Set<string>>(new Set())
const locationSort = ref<LocationSort>('items')
const freshnessNow = ref(new Date())

const { visibility, visibilityPanel, isDirector, saveError: visibilitySaveError, fetchVisibility, saveVisibility, clearVisibility } = useCorporationAssetVisibility()

const isCorpView = computed(() => viewMode.value === 'corporation')
const isTypeView = computed(() => !isCorpView.value && groupBy.value === 'type')

const refreshBlockedUntil = computed(() =>
  isCorpView.value && nextRefreshAt.value !== null && Date.parse(nextRefreshAt.value) > now.value
    ? nextRefreshAt.value
    : null
)

const refreshButtonLabel = computed(() => {
  if (isRefreshing.value) return t('common.actions.syncing')
  if (refreshBlockedUntil.value !== null) return t('assets.refreshAvailableAt', { time: formatEveClock(refreshBlockedUntil.value) })
  return t('common.actions.refresh')
})

let cooldownTimeoutId: ReturnType<typeof setTimeout> | null = null

function stopCooldownTimeout() {
  if (cooldownTimeoutId === null) return
  clearTimeout(cooldownTimeoutId)
  cooldownTimeoutId = null
}

function scheduleCooldownEnd() {
  stopCooldownTimeout()
  now.value = Date.now()
  if (nextRefreshAt.value === null) return
  const remainingMs = Date.parse(nextRefreshAt.value) - now.value
  if (remainingMs <= 0) return
  cooldownTimeoutId = setTimeout(scheduleCooldownEnd, remainingMs)
}

watch(nextRefreshAt, scheduleCooldownEnd)
onUnmounted(stopCooldownTimeout)
const noDivisionsShared = computed(() =>
  isCorpView.value
  && !isDirector.value
  && visibility.value != null
  && visibility.value.accessibleDivisions.length === 0
  && visibility.value.updatedAt == null
)

const hasSharedDivisions = computed(() =>
  isCorpView.value
  && !isDirector.value
  && visibility.value != null
  && visibility.value.accessibleDivisions.length > 0
)

const sharedDivisionNames = computed(() => {
  const currentVisibility = visibility.value
  if (!currentVisibility) return []
  return currentVisibility.accessibleDivisions
    .map(num => currentVisibility.allDivisions[num])
    .filter((name): name is string => name != null)
    .sort((a, b) => a.localeCompare(b))
})

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

const rootAssets = computed(() =>
  assets.value.filter(asset => asset.locationType !== 'item' || !existingItemIds.value.has(asset.locationId)),
)

const divisions = computed(() => {
  const rowCounts = new Map<string, number>()
  for (const asset of assets.value) {
    if (asset.divisionName !== null) {
      rowCounts.set(asset.divisionName, (rowCounts.get(asset.divisionName) ?? 0) + 1)
    }
  }
  return Array.from(rowCounts, ([name, rowCount]) => ({ name, rowCount })).sort((a, b) => a.name.localeCompare(b.name))
})

function isInSelectedDivision(asset: Asset): boolean {
  return !selectedDivision.value || asset.divisionName === selectedDivision.value
}

function locationOptionLabel(locationName: string, solarSystemName: string | null): string {
  if (isUnresolvedStructureName(locationName)) {
    return solarSystemName
      ? t('assets.unknownStructure', { system: solarSystemName })
      : t('assets.unknownStructureNoSystem')
  }
  if (isUnresolvedLocationName(locationName)) {
    return solarSystemName
      ? t('assets.unknownLocation', { system: solarSystemName })
      : t('assets.unknownLocationNoSystem')
  }
  return locationName
}

// Get unique locations (filtered by selected solar system)
const locations = computed(() => {
  const locs = new Map<string, { solarSystemName: string | null }>()
  for (const asset of rootAssets.value) {
    if (!locs.has(asset.locationName)) {
      locs.set(asset.locationName, { solarSystemName: asset.solarSystemName })
    }
  }

  let result = Array.from(locs.entries()).map(([name, data]) => ({
    name,
    label: locationOptionLabel(name, data.solarSystemName),
    solarSystemName: data.solarSystemName
  }))

  if (selectedSolarSystem.value) {
    result = result.filter(loc => loc.solarSystemName === selectedSolarSystem.value)
  }

  return result.sort((a, b) => a.label.localeCompare(b.label))
})

// Build a set of all item_ids
const existingItemIds = computed(() => new Set(assets.value.map(a => a.itemId)))

// Build a map of container item_id -> items inside it
const containerContents = computed(() => {
  const contents = new Map<number, Asset[]>()
  for (const asset of assets.value) {
    if (asset.locationType === 'item' && existingItemIds.value.has(asset.locationId)) {
      const containerId = asset.locationId
      const siblings = contents.get(containerId)
      if (siblings) {
        siblings.push(asset)
      } else {
        contents.set(containerId, [asset])
      }
    }
  }
  return contents
})

function applyResolvedItemName(itemId: number, itemName: string): void {
  const index = assets.value.findIndex(asset => asset.itemId === itemId)
  if (index === -1) {
    return
  }
  const updated = assets.value.slice()
  updated[index] = { ...updated[index], itemName, canResolveItemName: false }
  assets.value = updated
}

function isContainer(asset: Asset): boolean {
  return containerContents.value.has(asset.itemId)
}

const assetsByItemId = computed(() => new Map(assets.value.map(a => [a.itemId, a])))

const normalizedSearchQuery = computed(() => normalizeSearchText(searchQuery.value))

function containsQuery(text: string, query: string): boolean {
  return normalizeSearchText(text).includes(query)
}

function matchesItemSearch(asset: Asset, query: string): boolean {
  return containsQuery(typeNameLabel(asset.typeName, asset.typeId), query) ||
    (asset.itemName !== null && containsQuery(asset.itemName, query))
}

function matchesLocationSearch(asset: Asset, query: string): boolean {
  return containsQuery(asset.locationName, query) ||
    (asset.solarSystemName !== null && containsQuery(asset.solarSystemName, query))
}

function matchesSearch(asset: Asset, query: string): boolean {
  return matchesItemSearch(asset, query) || matchesLocationSearch(asset, query)
}

const visibleContainerContents = computed(() => {
  const query = normalizedSearchQuery.value
  if (!query) {
    return containerContents.value
  }
  const visible = new Map<number, Asset[]>()
  for (const [containerId, contents] of containerContents.value) {
    const container = assetsByItemId.value.get(containerId)
    const containerMatches = container !== undefined && matchesSearch(container, query)
    visible.set(containerId, containerMatches ? contents : contents.filter(item => matchesItemSearch(item, query)))
  }
  return visible
})

function getContainerContents(asset: Asset): Asset[] {
  return visibleContainerContents.value.get(asset.itemId) || []
}

function toggleContainer(itemId: number) {
  if (expandedContainers.value.has(itemId)) {
    expandedContainers.value.delete(itemId)
  } else {
    expandedContainers.value.add(itemId)
  }
  expandedContainers.value = new Set(expandedContainers.value)
}

function toggleLocationSelection(locationName: string) {
  const next = new Set(selectedLocationNames.value)
  if (!next.delete(locationName)) {
    next.add(locationName)
  }
  selectedLocationNames.value = next
}

function clearLocationSelection() {
  selectedLocationNames.value = new Set()
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
  if (selectedDivision.value) {
    result = result.filter(isInSelectedDivision)
  }
  if (searchQuery.value.trim()) {
    const query = normalizedSearchQuery.value
    result = result.filter(a => matchesSearch(a, query) || getContainerContents(a).length > 0)
  }

  return result
})

function compareDivisions(a: DivisionCount, b: DivisionCount): number {
  return b.count - a.count || a.name.localeCompare(b.name)
}

function compareLocations(a: LocationSummary, b: LocationSummary): number {
  if (locationSort.value === 'items') {
    return b.totalQuantity - a.totalQuantity || a.locationName.localeCompare(b.locationName)
  }
  return a.locationName.localeCompare(b.locationName)
}

const assetsByLocation = computed(() => {
  const grouped: Record<string, LocationSummary> = {}
  const divisionCounts: Record<string, Map<string, number>> = {}

  for (const asset of filteredAssets.value) {
    if (asset.locationType === 'item' && existingItemIds.value.has(asset.locationId)) {
      continue
    }

    const key = asset.locationName || `Location ${asset.locationId}`
    if (!grouped[key]) {
      grouped[key] = {
        locationId: asset.locationId,
        locationType: asset.locationType,
        locationName: key,
        solarSystemName: asset.solarSystemName,
        assets: [],
        totalQuantity: 0,
        shipCount: 0,
        divisions: [],
      }
      divisionCounts[key] = new Map()
    }

    let quantity = asset.quantity
    if (isContainer(asset)) {
      for (const item of getContainerContents(asset)) {
        quantity += item.quantity
      }
    }

    grouped[key].assets.push(asset)
    grouped[key].totalQuantity += quantity
    if (asset.categoryId === SHIP_CATEGORY_ID && asset.isSingleton === true) {
      grouped[key].shipCount += asset.quantity
    }
    if (asset.divisionName !== null) {
      const known = divisionCounts[key].get(asset.divisionName)
      divisionCounts[key].set(asset.divisionName, known === undefined ? quantity : known + quantity)
    }
  }

  if (!selectedDivision.value) {
    for (const [key, counts] of Object.entries(divisionCounts)) {
      grouped[key].divisions = Array.from(counts, ([name, count]) => ({ name, count })).sort(compareDivisions)
    }
  }

  return Object.values(grouped).sort(compareLocations)
})

const typeGroups = computed(() => {
  if (!isTypeView.value) {
    return []
  }
  return filterTypeGroups(
    groupAssetsByType(assets.value, asset => typeNameLabel(asset.typeName, asset.typeId)),
    { solarSystem: selectedSolarSystem.value, location: selectedLocation.value, searchQuery: searchQuery.value },
  )
})

const selectedLocations = computed(() =>
  assetsByLocation.value.filter(location => selectedLocationNames.value.has(location.locationName)),
)

const selectionSummary = computed(() => summarizeSelection(selectedLocations.value, visibleContainerContents.value))

const largestLocationQuantity = computed(() =>
  assetsByLocation.value.reduce((largest, location) => Math.max(largest, location.totalQuantity), 0),
)

const lastSyncedAt = computed(() => {
  let newest: string | null = null
  for (const asset of assets.value) {
    if (newest === null || Date.parse(asset.cachedAt) > Date.parse(newest)) {
      newest = asset.cachedAt
    }
  }
  return newest
})

const freshnessLabel = computed(() =>
  lastSyncedAt.value === null
    ? null
    : t('assets.syncedAt', { time: formatTimeSince(lastSyncedAt.value, freshnessNow.value) }),
)

let freshnessIntervalId: ReturnType<typeof setInterval> | null = null

function startFreshnessClock() {
  freshnessIntervalId = setInterval(() => {
    freshnessNow.value = new Date()
  }, FRESHNESS_TICK_MS)
}

function stopFreshnessClock() {
  if (freshnessIntervalId === null) return
  clearInterval(freshnessIntervalId)
  freshnessIntervalId = null
}

onMounted(startFreshnessClock)
onUnmounted(stopFreshnessClock)

function revealSearchMatches() {
  const query = normalizedSearchQuery.value
  if (!query) {
    return
  }
  const opened = new Set(expandedContainers.value)
  for (const [containerId, contents] of containerContents.value) {
    if (contents.some(item => matchesItemSearch(item, query))) {
      opened.add(containerId)
    }
  }
  expandedContainers.value = opened

  const reopenedLocations = new Set(collapsedLocations.value)
  for (const location of assetsByLocation.value) {
    const matchesThroughContent = location.assets.some(asset =>
      !matchesLocationSearch(asset, query) &&
      (matchesItemSearch(asset, query) || getContainerContents(asset).length > 0),
    )
    if (matchesThroughContent) {
      reopenedLocations.delete(location.locationName)
    }
  }
  collapsedLocations.value = reopenedLocations
}

watch(searchQuery, revealSearchMatches)

// Reset location filter when solar system changes
watch(selectedSolarSystem, () => {
  selectedLocation.value = ''
})

const assetSafetyLocations = computed(() => summarizeAssetSafety(assets.value))

async function showAssetSafetyContent(locationName: string) {
  const location = assetSafetyLocations.value.find(candidate => candidate.locationName === locationName)
  if (location === undefined) {
    return
  }
  selectedSolarSystem.value = location.solarSystemName
  await nextTick()
  selectedLocation.value = locationName
  const reopened = new Set(collapsedLocations.value)
  reopened.delete(locationName)
  collapsedLocations.value = reopened
}

// Stats
const divisionAssets = computed(() => assets.value.filter(isInSelectedDivision))
const totalItems = computed(() => divisionAssets.value.reduce((sum, a) => sum + a.quantity, 0))
const totalTypes = computed(() => new Set(divisionAssets.value.map(a => a.typeId)).size)
const totalLocations = computed(() => new Set(rootAssets.value.filter(isInSelectedDivision).map(a => a.locationId)).size)
const totalSystems = computed(() => new Set(divisionAssets.value.filter(a => a.solarSystemName).map(a => a.solarSystemName)).size)

// Fetch assets
function abortPendingAssetsRequest() {
  pendingAssetsRequest?.abort()
  pendingAssetsRequest = null
}

function dropFiltersMissingFromAssets() {
  if (selectedSolarSystem.value && !solarSystems.value.includes(selectedSolarSystem.value)) {
    selectedSolarSystem.value = ''
  }
  if (selectedLocation.value && !locations.value.some(location => location.name === selectedLocation.value)) {
    selectedLocation.value = ''
  }
  if (selectedDivision.value && !divisions.value.some(division => division.name === selectedDivision.value)) {
    selectedDivision.value = ''
  }
}

function collapseLocationsOfLargeInventory() {
  if (rootAssets.value.length <= COLLAPSE_LOCATIONS_ROW_THRESHOLD) {
    return
  }
  collapsedLocations.value = new Set(assetsByLocation.value.map(location => location.locationName))
}

async function fetchAssets() {
  if (viewMode.value === 'character' && !selectedCharacterId.value) return

  abortPendingAssetsRequest()
  const request = new AbortController()
  pendingAssetsRequest = request

  const isReload = hasLoadedCurrentOwner.value
  isLoading.value = !isReload
  isReloading.value = isReload
  error.value = ''

  const isCorporationRequest = viewMode.value === 'corporation'

  try {
    const endpoint = isCorporationRequest
      ? '/me/corporation/assets'
      : `/me/characters/${selectedCharacterId.value}/assets`

    const data = await apiRequest<{ items?: Asset[]; nextRefreshAt?: string | null }>(endpoint, {
      signal: request.signal,
    })
    if (request.signal.aborted) return
    assets.value = data.items || []
    nextRefreshAt.value = isCorporationRequest ? (data.nextRefreshAt ?? null) : null
    hasLoadedCurrentOwner.value = true
    freshnessNow.value = new Date()
    dropFiltersMissingFromAssets()
    if (!isReload) {
      collapseLocationsOfLargeInventory()
      revealSearchMatches()
    }
  } catch (e) {
    if (request.signal.aborted) return
    error.value = t('assets.loadFailed')
    console.error(e)
  } finally {
    if (pendingAssetsRequest === request) {
      pendingAssetsRequest = null
      isLoading.value = false
      isReloading.value = false
    }
  }
}

onUnmounted(abortPendingAssetsRequest)

async function onSaveVisibility(payload: CorporationVisibilityPayload) {
  if (await saveVisibility(payload)) {
    await fetchAssets()
  } else {
    error.value = visibilitySaveError.value
  }
}

// Sync progress
const currentSyncType = computed(() =>
  viewMode.value === 'character' ? 'character-assets' : 'corporation-assets'
)

const currentSyncProgress = computed(() =>
  syncStore.getSyncProgress(currentSyncType.value)
)
const { isFlashing: assetsUpdated, flash: flashAssets } = useFlash()

let syncTimeoutId: ReturnType<typeof setTimeout> | null = null

function stopSyncTimeout() {
  if (syncTimeoutId === null) return
  clearTimeout(syncTimeoutId)
  syncTimeoutId = null
}

function startSyncTimeout() {
  stopSyncTimeout()
  syncTimeoutId = setTimeout(giveUpWaitingForSync, SYNC_EVENT_TIMEOUT_MS)
}

async function giveUpWaitingForSync() {
  syncTimeoutId = null
  isRefreshing.value = false
  syncStore.clearSyncStatus(currentSyncType.value)
  await fetchAssets()
  error.value = t('assets.syncNoAnswer')
}

watch(isRefreshing, (refreshing) => {
  if (refreshing && syncStore.isConnected) {
    startSyncTimeout()
  } else {
    stopSyncTimeout()
  }
})

onUnmounted(stopSyncTimeout)

watch(
  () => currentSyncProgress.value,
  (progress) => {
    if (!progress) return
    if (progress.status === 'completed') {
      stopSyncTimeout()
      fetchAssets().then(flashAssets)
      isRefreshing.value = false
      syncStore.clearSyncStatus(currentSyncType.value)
    } else if (progress.status === 'error') {
      stopSyncTimeout()
      error.value = progress.message || t('common.errors.syncFailed')
      isRefreshing.value = false
      syncStore.clearSyncStatus(currentSyncType.value)
    } else if (progress.status === 'started' || progress.status === 'in_progress') {
      isRefreshing.value = true
      startSyncTimeout()
    }
  }
)

// Refresh assets
async function refreshAssets() {
  if (viewMode.value === 'character' && !selectedCharacterId.value) return

  isRefreshing.value = true
  error.value = ''
  refreshNotice.value = ''

  try {
    const endpoint = viewMode.value === 'character'
      ? `/me/characters/${selectedCharacterId.value}/assets/refresh`
      : '/me/corporation/assets/refresh'

    const data = await apiRequest<{ status?: string; error?: string; nextRefreshAt?: string | null }>(endpoint, {
      method: 'POST',
      body: JSON.stringify({}),
    })

    if (data.status === 'error') {
      error.value = data.error || t('assets.refreshFailed')
      isRefreshing.value = false
    } else if (data.status === 'up_to_date') {
      nextRefreshAt.value = data.nextRefreshAt ?? null
      isRefreshing.value = false
    } else if (data.status === 'in_progress') {
      refreshNotice.value = t('assets.syncAlreadyRunning')
      isRefreshing.value = false
    } else if (data.status === 'pending') {
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
    const hasReadableMessage = e instanceof Error && !(e instanceof ApiError && !carriesServerMessage(e))
    error.value = hasReadableMessage ? e.message : t('assets.refreshFailed')
    isRefreshing.value = false
  }
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

function clearFilters() {
  selectedSolarSystem.value = ''
  selectedLocation.value = ''
  selectedDivision.value = ''
  searchQuery.value = ''
}

function initialCharacterId(): string | null {
  const available = characters.value
  if (available.length === 0) return null
  const ownedId = (id: string | null) => id !== null && available.some(c => c.id === id) ? id : null
  const lastCharacterId = user.value ? readLastCharacterId(user.value.id) : null
  return ownedId(urlFilters.characterId) ?? ownedId(lastCharacterId) ?? (available.find(c => c.isMain) ?? available[0]).id
}

watch(characters, () => {
  if (!selectedCharacterId.value) {
    selectedCharacterId.value = initialCharacterId()
  }
}, { immediate: true })

watch(selectedCharacterId, (characterId) => {
  if (characterId && user.value) {
    rememberLastCharacterId(user.value.id, characterId)
  }
}, { immediate: true })

watch([viewMode, selectedCharacterId, searchQuery, selectedSolarSystem, selectedLocation, selectedDivision], () => {
  router.replace({
    query: writeAssetFilterQuery(route.query, {
      viewMode: viewMode.value,
      characterId: selectedCharacterId.value,
      search: searchQuery.value,
      solarSystem: selectedSolarSystem.value,
      location: selectedLocation.value,
      division: selectedDivision.value,
    }),
  })
}, { immediate: true })

watch([selectedCharacterId, viewMode], (_current, previous) => {
  const previousView = previous?.[1]
  const isFirstOwnerResolution = (previous?.[0] ?? null) === null && (previousView === undefined || previousView === viewMode.value)
  abortPendingAssetsRequest()
  clearLocationSelection()
  assets.value = []
  nextRefreshAt.value = null
  hasLoadedCurrentOwner.value = false
  isReloading.value = false
  isLoading.value = false
  expandedContainers.value = new Set()
  collapsedLocations.value = new Set()
  if (!isFirstOwnerResolution) {
    selectedSolarSystem.value = ''
    selectedLocation.value = ''
    selectedDivision.value = ''
    searchQuery.value = ''
  }
  if (viewMode.value === 'corporation') {
    fetchVisibility()
  } else {
    clearVisibility()
  }
  fetchAssets()
}, { immediate: true })
</script>

<template>
  <MainLayout>
    <PatrimoineModuleTabs active="assets" />
    <div class="space-y-6">
      <div class="space-y-6">
        <!-- Action bar -->
        <div class="flex items-center justify-between">
          <div>
            <p class="text-slate-400">{{ t('assets.subtitle') }}</p>
            <p v-if="freshnessLabel" data-testid="assets-freshness" class="text-xs text-slate-500">{{ freshnessLabel }}</p>
          </div>
          <button
            @click="refreshAssets"
            :disabled="isRefreshing || isLoading || refreshBlockedUntil !== null"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <svg :class="['w-4 h-4', isRefreshing && 'animate-spin']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            {{ refreshButtonLabel }}
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
          v-if="isCorpView && visibility"
          ref="visibilityPanel"
          :visibility="visibility"
          @save="onSaveVisibility"
        />

        <!-- Shared divisions info (non-director, read-only) -->
        <div v-if="hasSharedDivisions" class="bg-slate-900 rounded-lg border border-slate-800 px-4 py-3">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-cyan-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="text-sm font-medium text-slate-200">{{ t('assets.visibility.sharedDivisions') }}</span>
                <div class="flex flex-wrap gap-1.5">
                  <span
                    v-for="name in sharedDivisionNames"
                    :key="name"
                    class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-cyan-500/10 text-cyan-400 border border-cyan-500/20"
                  >
                    {{ name }}
                  </span>
                </div>
              </div>
              <p v-if="visibility?.updatedAt" class="text-xs text-slate-500 mt-1">
                {{ t('assets.visibility.configuredBy', { name: pilotName(visibility.configuredByName) }) }}
                <span> &mdash; {{ formatDate(visibility.updatedAt) }}</span>
              </p>
            </div>
          </div>
        </div>

        <!-- Error message -->
        <ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
        <p v-if="refreshNotice" data-testid="assets-refresh-notice" class="text-sm text-cyan-300">{{ refreshNotice }}</p>

        <!-- No divisions shared (non-director, no config) -->
        <div v-if="noDivisionsShared" class="text-center py-20">
          <svg class="w-16 h-16 mx-auto text-slate-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
          <p class="text-slate-500">{{ t('assets.visibility.noDivisionsShared') }}</p>
        </div>

        <!-- Filters, stats, and assets list (hidden when no divisions shared) -->
        <template v-if="!noDivisionsShared">
          <!-- Filters -->
          <AssetsFilterBar
            :view-mode="viewMode"
            :group-by="groupBy"
            :characters="characters"
            :selected-character-id="selectedCharacterId"
            :search-query="searchQuery"
            :solar-systems="solarSystems"
            :locations="locations"
            :selected-solar-system="selectedSolarSystem"
            :selected-location="selectedLocation"
            :divisions="divisions"
            :selected-division="selectedDivision"
            :all-divisions-row-count="assets.length"
            @update:view-mode="viewMode = $event"
            @update:group-by="groupBy = $event"
            @update:selected-character-id="selectedCharacterId = $event"
            @update:search-query="searchQuery = $event"
            @update:selected-solar-system="selectedSolarSystem = $event"
            @update:selected-location="selectedLocation = $event"
            @update:selected-division="selectedDivision = $event"
            @clear-filters="clearFilters"
          />

          <AssetSafetyBlock
            v-if="assetSafetyLocations.length > 0"
            :locations="assetSafetyLocations"
            @show-content="showAssetSafetyContent"
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

          <div
            v-if="isReloading"
            data-testid="assets-reloading"
            role="progressbar"
            :aria-label="t('assets.loading')"
            class="h-0.5 w-full overflow-hidden rounded-full bg-slate-800"
          >
            <div class="h-full w-1/3 animate-pulse bg-cyan-500"></div>
          </div>

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
              <svg class="w-16 h-16 mx-auto text-slate-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
              <p class="text-slate-500 mb-4">{{ t('assets.noAssets') }}</p>
              <button
                @click="refreshAssets"
                :disabled="isRefreshing || refreshBlockedUntil !== null"
                class="text-cyan-400 hover:underline text-sm"
              >
                {{ t('assets.refreshFromEve') }}
              </button>
            </template>
          </div>

          <!-- Assets by type -->
          <AssetTypeView v-else-if="isTypeView" :groups="typeGroups" />

          <!-- Assets by location -->
          <div v-else class="relative rounded-xl space-y-4" :class="{ 'hud-updated': assetsUpdated }">
            <div class="flex items-center justify-end gap-2">
              <label for="assets-location-sort" class="text-xs text-slate-500">{{ t('assets.sort.label') }}</label>
              <select
                id="assets-location-sort"
                v-model="locationSort"
                data-testid="location-sort"
                class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200"
              >
                <option value="items">{{ t('assets.sort.items') }}</option>
                <option value="name">{{ t('assets.sort.name') }}</option>
              </select>
            </div>
            <AssetLocationGroup
              v-for="location in assetsByLocation"
              :key="location.locationName"
              :location="location"
              :expanded-containers="expandedContainers"
              :collapsed-locations="collapsedLocations"
              :container-contents="visibleContainerContents"
              :fit-contents="containerContents"
              :max-quantity="largestLocationQuantity"
              :selected="selectedLocationNames.has(location.locationName)"
              @toggle-container="toggleContainer"
              @toggle-location="toggleLocation"
              @toggle-selection="toggleLocationSelection"
              @item-name-resolved="applyResolvedItemName"
            />
            <AssetSelectionBar
              v-if="selectedLocations.length > 0"
              :location-count="selectedLocations.length"
              :summary="selectionSummary"
              @clear="clearLocationSelection"
            />
          </div>

          <!-- No results -->
          <div v-if="!isLoading && assets.length > 0 && (isTypeView ? typeGroups.length === 0 : filteredAssets.length === 0)" class="text-center py-12">
            <p class="text-slate-500">{{ t('assets.noMatchingItems') }}</p>
          </div>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

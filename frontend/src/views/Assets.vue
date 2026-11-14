<script setup lang="ts">
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { readAssetFilterQuery, readLastCharacterId, rememberLastCharacterId, writeAssetFilterQuery } from '@/utils/assetFilterQuery'
import type { AssetLocationSort } from '@/utils/assetFilterQuery'
import { isUnresolvedLocationName, isUnresolvedStructureName } from '@/utils/assetLocation'
import { summarizeAssetSafetyLocations, summarizeAssetSafetyVolumes } from '@/utils/assetSafety'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { usePilotName } from '@/composables/usePilotName'
import { ApiError, apiRequest, carriesServerMessage } from '@/services/api'
import { SYNC_EVENT_TIMEOUT_MS } from '@/constants/syncConstants'
import { ASSET_SEARCH_DEBOUNCE_MS, ASSET_SEARCH_MIN_LENGTH, AUTO_EXPAND_MAX_LOCATIONS, COLLAPSE_LOCATIONS_ROW_THRESHOLD, FRESHNESS_TICK_MS, SEARCH_REVEAL_CONTAINER_LIMIT } from '@/constants/assetsConstants'
import { useFormatters } from '@/composables/useFormatters'
import { useTypeNames } from '@/composables/useTypeNames'
import { useAssetChanges } from '@/composables/useAssetChanges'
import { useAssetBrowse, type AssetSearchState } from '@/composables/useAssetBrowse'
import { normalizeSearchText } from '@/utils/commandPaletteSearch'
import { filterTypeGroups, groupAssetsByType, summarizeTypeGroupsVolume, typeGroupLocationNames } from '@/utils/assetsByType'
import { summarizeSelection, summarizeTypeSelection } from '@/utils/assetSelection'
import { useCorporationAssetVisibility, type CorporationVisibilityPayload } from '@/composables/useCorporationAssetVisibility'
import { usePinnedContainers } from '@/composables/usePinnedContainers'
import { usePackagedVolumes } from '@/composables/usePackagedVolumes'
import { summarizeVolume, type VolumeSummary } from '@/utils/assetVolume'
import { locateContainer, projectPinnedFirst } from '@/utils/pinnedContainers'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'
import AssetsStatsCards from '@/components/assets/AssetsStatsCards.vue'
import AssetSafetyBlock from '@/components/assets/AssetSafetyBlock.vue'
import AssetChangesPanel from '@/components/assets/AssetChangesPanel.vue'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import AssetTypeView from '@/components/assets/AssetTypeView.vue'
import AssetSelectionBar from '@/components/assets/AssetSelectionBar.vue'
import AssetDistributionChart from '@/components/assets/AssetDistributionChart.vue'
import CorpVisibilityPanel from '@/components/assets/CorpVisibilityPanel.vue'
import PinnedContainersPanel from '@/components/assets/PinnedContainersPanel.vue'
import PatrimoineModuleTabs from '@/components/patrimoine/PatrimoineModuleTabs.vue'
import type { Asset, AssetLocationSummaryRow } from '@/types'

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
  volume: VolumeSummary | null
  contentsLoaded: boolean
  isLoadingContents: boolean
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
const isLoading = ref(false)
const isReloading = ref(false)
const hasLoadedCurrentOwner = ref(false)
let fetchSequence = 0
let loadedDivision = ''
let searchTimeoutId: ReturnType<typeof setTimeout> | null = null
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
const selectedTypeIds = ref<Set<number>>(new Set())
const locationSort = ref<AssetLocationSort>(urlFilters.locationSort)
const freshnessNow = ref(new Date())

const { visibility, visibilityPanel, isDirector, saveError: visibilitySaveError, fetchVisibility, saveVisibility, clearVisibility } = useCorporationAssetVisibility()

const isCorpView = computed(() => viewMode.value === 'corporation')
const isTypeView = computed(() => !isCorpView.value && groupBy.value === 'type')

const ownerPath = computed(() => {
  if (isCorpView.value) return '/me/corporation/assets'
  return selectedCharacterId.value ? `/me/characters/${selectedCharacterId.value}/assets` : null
})

const {
  summary: fullSummary,
  divisionSummary,
  rootRows,
  childRows,
  locationOfItem,
  loadingLocationIds,
  fullListLoaded,
  search: serverSearch,
  loadSummary,
  loadLocation,
  loadContainer,
  loadFullList,
  runSearch,
  reloadLoaded,
  reset: resetBrowse,
  replaceRow,
} = useAssetBrowse(ownerPath)

const summary = computed(() => (selectedDivision.value !== '' && divisionSummary.value !== null ? divisionSummary.value : fullSummary.value))
const summaryLocations = computed(() => summary.value?.locations ?? [])

const { catalog: packagedVolumeCatalog, unavailable: packagedVolumesUnavailable, fetchPackagedVolumes } = usePackagedVolumes()
const { entries: pinnedEntries, pinnedItemIds, projectPinnedItemIds, fetchPinned, pin: pinContainer, unpin: unpinContainer, clearPinned } = usePinnedContainers()

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

const solarSystems = computed(() => {
  const systems = new Set<string>()
  for (const location of fullSummary.value?.locations ?? []) {
    if (location.solarSystemName) {
      systems.add(location.solarSystemName)
    }
  }
  return Array.from(systems).sort()
})

const divisions = computed(() => {
  const counts = new Map<string, number>()
  for (const location of fullSummary.value?.locations ?? []) {
    for (const division of location.divisions) {
      counts.set(division.name, (counts.get(division.name) ?? 0) + division.count)
    }
  }
  return Array.from(counts, ([name, rowCount]) => ({ name, rowCount })).sort((a, b) => a.name.localeCompare(b.name))
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

const locations = computed(() => {
  const locs = new Map<string, { solarSystemName: string | null }>()
  for (const location of fullSummary.value?.locations ?? []) {
    if (!locs.has(location.locationName)) {
      locs.set(location.locationName, { solarSystemName: location.solarSystemName })
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

const poolAssets = computed(() => [
  ...Array.from(rootRows.value.values()).flat(),
  ...Array.from(childRows.value.values()).flat(),
])

const assetsByItemId = computed(() => new Map(poolAssets.value.map(a => [a.itemId, a])))

function applyResolvedItemName(itemId: number, itemName: string): void {
  replaceRow(itemId, { itemName, canResolveItemName: false })
}

const normalizedSearchQuery = computed(() => normalizeSearchText(searchQuery.value))

function containsQuery(text: string, query: string): boolean {
  return normalizeSearchText(text).includes(query)
}

function matchesLocationRow(location: AssetLocationSummaryRow, query: string): boolean {
  return containsQuery(location.locationName, query) ||
    (location.solarSystemName !== null && containsQuery(location.solarSystemName, query))
}

const activeSearch = computed<AssetSearchState | null>(() =>
  searchQuery.value.trim().length >= ASSET_SEARCH_MIN_LENGTH ? serverSearch.value : null,
)

const searchIsTruncated = computed(() => activeSearch.value?.truncated === true)

function matchedThroughSearch(rows: Asset[], state: AssetSearchState): Asset[] {
  return rows.filter(row => state.hitItemIds.has(row.itemId) || state.pathItemIds.has(row.itemId))
}

function showsEverythingInContainer(containerId: number, state: AssetSearchState): boolean {
  const locationId = locationOfItem.value.get(containerId)
  return state.hitItemIds.has(containerId) || (locationId !== undefined && state.locationIds.has(locationId))
}

const visibleContainerContents = computed(() => {
  const state = activeSearch.value
  if (state === null || isTypeView.value) {
    return childRows.value
  }
  const visible = new Map<number, Asset[]>()
  for (const [containerId, rows] of childRows.value) {
    visible.set(containerId, showsEverythingInContainer(containerId, state) ? rows : matchedThroughSearch(rows, state))
  }
  return visible
})

function getContentsOfItem(itemId: number): Asset[] {
  return visibleContainerContents.value.get(itemId) ?? []
}

async function openContainer(itemId: number) {
  try {
    await loadContainer(itemId)
  } catch (e) {
    error.value = t('assets.loadFailed')
    console.error(e)
  }
}

function toggleContainer(itemId: number) {
  if (expandedContainers.value.has(itemId)) {
    expandedContainers.value.delete(itemId)
  } else {
    expandedContainers.value.add(itemId)
    void openContainer(itemId)
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

function toggleTypeSelection(typeId: number) {
  const next = new Set(selectedTypeIds.value)
  if (!next.delete(typeId)) {
    next.add(typeId)
  }
  selectedTypeIds.value = next
}

function clearTypeSelection() {
  selectedTypeIds.value = new Set()
}

async function loadExpandedLocations() {
  const pending = summaryLocations.value.filter(location => !collapsedLocations.value.has(location.locationName))
  try {
    await Promise.all(pending.map(location => loadLocation(location.locationId)))
  } catch (e) {
    error.value = t('assets.loadFailed')
    console.error(e)
  }
}

function toggleLocation(locationName: string) {
  if (collapsedLocations.value.has(locationName)) {
    collapsedLocations.value.delete(locationName)
    void loadExpandedLocations()
  } else {
    collapsedLocations.value.add(locationName)
  }
  collapsedLocations.value = new Set(collapsedLocations.value)
}

function compareDivisions(a: DivisionCount, b: DivisionCount): number {
  return b.count - a.count || a.name.localeCompare(b.name)
}

function compareLocations(a: LocationSummary, b: LocationSummary): number {
  if (locationSort.value === 'volume' && a.volume !== null && b.volume !== null) {
    return b.volume.cubicMeters - a.volume.cubicMeters || a.locationName.localeCompare(b.locationName)
  }
  if (locationSort.value !== 'name') {
    return b.totalQuantity - a.totalQuantity || a.locationName.localeCompare(b.locationName)
  }
  return a.locationName.localeCompare(b.locationName)
}

const visibleLocationRows = computed(() => {
  let rows = summaryLocations.value
  if (selectedSolarSystem.value) {
    rows = rows.filter(row => row.solarSystemName === selectedSolarSystem.value)
  }
  if (selectedLocation.value) {
    rows = rows.filter(row => row.locationName === selectedLocation.value)
  }
  const state = activeSearch.value
  if (state !== null) {
    return rows.filter(row => state.locationIds.has(row.locationId) || state.rootLocationIds.has(row.locationId))
  }
  const query = normalizedSearchQuery.value
  if (query !== '' && !isTypeView.value) {
    return rows.filter(row => matchesLocationRow(row, query))
  }
  return rows
})

function rowsOfLocation(row: AssetLocationSummaryRow): Asset[] {
  let rows = rootRows.value.get(row.locationId) ?? []
  if (selectedDivision.value) {
    rows = rows.filter(isInSelectedDivision)
  }
  const state = activeSearch.value
  if (state !== null && !state.locationIds.has(row.locationId)) {
    rows = matchedThroughSearch(rows, state)
  }
  return projectPinnedFirst(rows, projectPinnedItemIds.value)
}

const assetsByLocation = computed<LocationSummary[]>(() => {
  const catalog = fullListLoaded.value ? packagedVolumeCatalog.value : null
  return visibleLocationRows.value.map((row) => {
    const locationAssets = rowsOfLocation(row)
    return {
      locationId: row.locationId,
      locationType: row.locationType,
      locationName: row.locationName,
      solarSystemName: row.solarSystemName,
      assets: locationAssets,
      totalQuantity: row.itemCount,
      shipCount: row.shipCount,
      divisions: selectedDivision.value ? [] : [...row.divisions].sort(compareDivisions),
      volume: catalog === null ? null : summarizeVolume(locationAssets, getContentsOfItem, catalog),
      contentsLoaded: rootRows.value.has(row.locationId),
      isLoadingContents: loadingLocationIds.value.has(row.locationId),
    }
  }).sort(compareLocations)
})

const typeGroups = computed(() => {
  if (!isTypeView.value || !fullListLoaded.value) {
    return []
  }
  return filterTypeGroups(
    groupAssetsByType(poolAssets.value, asset => typeNameLabel(asset.typeName, asset.typeId)),
    { solarSystem: selectedSolarSystem.value, location: selectedLocation.value, searchQuery: searchQuery.value },
  )
})

const selectedLocations = computed(() =>
  assetsByLocation.value.filter(location => selectedLocationNames.value.has(location.locationName)),
)

const selectionSummary = computed(() =>
  fullListLoaded.value ? summarizeSelection(selectedLocations.value, visibleContainerContents.value) : null,
)

const selectionVolume = computed(() =>
  !fullListLoaded.value || packagedVolumeCatalog.value === null
    ? null
    : summarizeVolume(selectedLocations.value.flatMap(location => location.assets), getContentsOfItem, packagedVolumeCatalog.value),
)

const selectedTypeGroups = computed(() =>
  typeGroups.value.filter(group => selectedTypeIds.value.has(group.typeId)),
)

const unnamedTypeIds = computed(() =>
  new Set(poolAssets.value.filter(asset => asset.typeName === null).map(asset => asset.typeId)),
)

const typeSelectionSummary = computed(() => summarizeTypeSelection(selectedTypeGroups.value, unnamedTypeIds.value))

const typeSelectionVolume = computed(() =>
  packagedVolumeCatalog.value === null ? null : summarizeTypeGroupsVolume(selectedTypeGroups.value, packagedVolumeCatalog.value),
)

const typeSelectionLocationNames = computed(() => typeGroupLocationNames(selectedTypeGroups.value))

const largestLocationQuantity = computed(() =>
  assetsByLocation.value.reduce((largest, location) => Math.max(largest, location.totalQuantity), 0),
)

const lastSyncedAt = computed(() => fullSummary.value?.lastSyncedAt ?? null)

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
onMounted(() => {
  void fetchPackagedVolumes()
})
onUnmounted(stopFreshnessClock)

async function ensureFullList() {
  try {
    await loadFullList()
  } catch (e) {
    error.value = t('assets.loadFailed')
    console.error(e)
  }
}

async function openLocationRow(row: AssetLocationSummaryRow) {
  const reopened = new Set(collapsedLocations.value)
  reopened.delete(row.locationName)
  collapsedLocations.value = reopened
  await loadLocation(row.locationId)
}

async function openHitContainers(locationId: number, state: AssetSearchState, opened: Set<number>) {
  const budgetLeft = () => SEARCH_REVEAL_CONTAINER_LIMIT - opened.size
  for (const path of state.pathsByLocation.get(locationId) ?? []) {
    for (const containerId of path) {
      if (opened.has(containerId)) {
        continue
      }
      if (budgetLeft() <= 0) {
        return
      }
      opened.add(containerId)
      await loadContainer(containerId)
    }
  }
}

async function revealSearchMatches() {
  const state = activeSearch.value
  if (state === null) {
    return
  }
  const throughContent = summaryLocations.value
    .filter(row => state.rootLocationIds.has(row.locationId) && !state.locationIds.has(row.locationId))
    .slice(0, AUTO_EXPAND_MAX_LOCATIONS)
  const openedContainers = new Set<number>()
  try {
    await Promise.all(throughContent.map(openLocationRow))
    for (const row of throughContent) {
      await openHitContainers(row.locationId, state, openedContainers)
    }
  } catch (e) {
    error.value = t('assets.loadFailed')
    console.error(e)
    return
  }
  expandedContainers.value = new Set([...expandedContainers.value, ...openedContainers])
}

async function searchOnServer(query: string) {
  try {
    await runSearch(query)
    await revealSearchMatches()
  } catch (e) {
    error.value = t('assets.loadFailed')
    console.error(e)
  }
}

function stopSearchTimeout() {
  if (searchTimeoutId === null) return
  clearTimeout(searchTimeoutId)
  searchTimeoutId = null
}

watch(searchQuery, (query) => {
  stopSearchTimeout()
  if (query.trim().length < ASSET_SEARCH_MIN_LENGTH) {
    void searchOnServer(query)
    return
  }
  searchTimeoutId = setTimeout(() => {
    searchTimeoutId = null
    void searchOnServer(query)
  }, ASSET_SEARCH_DEBOUNCE_MS)
})

onUnmounted(stopSearchTimeout)

const assetSafetyLocations = computed(() => summarizeAssetSafetyLocations(summaryLocations.value))
const assetSafetyNeedsVolume = computed(() => assetSafetyLocations.value.length > 0 && packagedVolumeCatalog.value !== null)

const assetSafetyVolumes = computed(() => {
  const catalog = packagedVolumeCatalog.value
  if (!fullListLoaded.value || catalog === null) {
    return null
  }
  return summarizeAssetSafetyVolumes(
    summaryLocations.value,
    locationId => {
      const rows = rootRows.value.get(locationId) ?? []
      return selectedDivision.value ? rows.filter(isInSelectedDivision) : rows
    },
    itemId => childRows.value.get(itemId) ?? [],
    catalog,
  )
})

watch([isTypeView, selectedLocationNames, locationSort, assetSafetyNeedsVolume], () => {
  const needsWholeList = isTypeView.value || selectedLocationNames.value.size > 0 || locationSort.value === 'volume' || assetSafetyNeedsVolume.value
  if (needsWholeList) {
    void ensureFullList()
  }
}, { immediate: true })

// Reset location filter when solar system changes
watch(selectedSolarSystem, () => {
  selectedLocation.value = ''
})

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
  await loadExpandedLocations()
}

// Stats
const totalItems = computed(() => summary.value?.itemCount ?? 0)
const totalTypes = computed(() => summary.value?.typeCount ?? 0)
const totalLocations = computed(() => summary.value?.locationCount ?? 0)
const totalSystems = computed(() => summary.value?.systemCount ?? 0)
const hasNoAssets = computed(() => (fullSummary.value?.locations.length ?? 0) === 0)

// Fetch assets
function dropFiltersMissingFromSummary() {
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

function applyDefaultOpenState() {
  const rows = fullSummary.value?.locations ?? []
  const itemCount = fullSummary.value?.itemCount ?? 0
  if (rows.length <= AUTO_EXPAND_MAX_LOCATIONS && itemCount <= COLLAPSE_LOCATIONS_ROW_THRESHOLD) {
    return
  }
  collapsedLocations.value = new Set(rows.map(row => row.locationName))
}

async function fetchAssets() {
  if (viewMode.value === 'character' && !selectedCharacterId.value) return

  const sequence = ++fetchSequence
  const isReload = hasLoadedCurrentOwner.value
  isLoading.value = !isReload
  isReloading.value = isReload
  error.value = ''

  const isCorporationRequest = viewMode.value === 'corporation'
  if (isCorporationRequest) {
    void fetchPinned()
  }

  try {
    loadedDivision = selectedDivision.value
    await loadSummary(loadedDivision)
    if (sequence !== fetchSequence) return
    nextRefreshAt.value = isCorporationRequest ? (fullSummary.value?.nextRefreshAt ?? null) : null
    hasLoadedCurrentOwner.value = true
    freshnessNow.value = new Date()
    dropFiltersMissingFromSummary()
    if (isReload) {
      await reloadLoaded()
    } else {
      applyDefaultOpenState()
    }
    if (sequence !== fetchSequence) return
    await loadExpandedLocations()
    if (searchQuery.value.trim().length >= ASSET_SEARCH_MIN_LENGTH) {
      await searchOnServer(searchQuery.value)
    }
  } catch (e) {
    if (sequence !== fetchSequence) return
    error.value = t('assets.loadFailed')
    console.error(e)
  } finally {
    if (sequence === fetchSequence) {
      isLoading.value = false
      isReloading.value = false
    }
  }
}

watch(selectedDivision, async (division) => {
  if (!hasLoadedCurrentOwner.value || division === loadedDivision) {
    return
  }
  loadedDivision = division
  try {
    await loadSummary(division)
  } catch (e) {
    error.value = t('assets.loadFailed')
    console.error(e)
  }
})

async function onTogglePin(itemId: number) {
  try {
    if (pinnedItemIds.value.has(itemId)) {
      await unpinContainer(itemId)
    } else {
      await pinContainer(itemId)
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('assets.loadFailed')
  }
}

async function openPinnedContainer(itemId: number) {
  await ensureFullList()
  const located = locateContainer(itemId, assetsByItemId.value)
  if (!located) {
    return
  }
  searchQuery.value = ''
  selectedDivision.value = ''
  selectedSolarSystem.value = ''
  selectedLocation.value = ''
  await nextTick()
  const reopenedLocations = new Set(collapsedLocations.value)
  reopenedLocations.delete(located.locationName)
  collapsedLocations.value = reopenedLocations
  expandedContainers.value = new Set([...expandedContainers.value, ...located.containerItemIds])
  await nextTick()
  document.querySelector(`[data-asset-item-id="${itemId}"]`)?.scrollIntoView?.({ block: 'center' })
}

const changesEndpoint = computed(() => {
  if (isCorpView.value) return '/me/corporation/assets/changes'
  return selectedCharacterId.value ? `/me/characters/${selectedCharacterId.value}/assets/changes` : null
})
const { changes: assetChanges, hasError: assetChangesFailed, load: loadAssetChanges } = useAssetChanges(changesEndpoint)

async function onSaveVisibility(payload: CorporationVisibilityPayload) {
  if (await saveVisibility(payload)) {
    void loadAssetChanges()
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
      void loadAssetChanges()
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
          const hasRecentData = !hasNoAssets.value
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

watch([viewMode, selectedCharacterId, searchQuery, selectedSolarSystem, selectedLocation, selectedDivision, locationSort], () => {
  router.replace({
    query: writeAssetFilterQuery(route.query, {
      viewMode: viewMode.value,
      characterId: selectedCharacterId.value,
      search: searchQuery.value,
      solarSystem: selectedSolarSystem.value,
      location: selectedLocation.value,
      division: selectedDivision.value,
      locationSort: locationSort.value,
    }),
  })
}, { immediate: true })

watch([selectedCharacterId, viewMode], (_current, previous) => {
  const previousView = previous?.[1]
  const isFirstOwnerResolution = (previous?.[0] ?? null) === null && (previousView === undefined || previousView === viewMode.value)
  fetchSequence++
  resetBrowse()
  stopSearchTimeout()
  clearLocationSelection()
  clearTypeSelection()
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
    clearPinned()
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
            :all-divisions-row-count="fullSummary?.itemCount ?? 0"
            @update:view-mode="viewMode = $event"
            @update:group-by="groupBy = $event"
            @update:selected-character-id="selectedCharacterId = $event"
            @update:search-query="searchQuery = $event"
            @update:selected-solar-system="selectedSolarSystem = $event"
            @update:selected-location="selectedLocation = $event"
            @update:selected-division="selectedDivision = $event"
            @clear-filters="clearFilters"
          />

          <p v-if="searchIsTruncated" data-testid="assets-search-truncated" class="text-xs text-amber-400">{{ t('assets.searchTruncated') }}</p>

          <AssetSafetyBlock
            v-if="assetSafetyLocations.length > 0"
            :locations="assetSafetyLocations"
            :volumes="assetSafetyVolumes"
            @show-content="showAssetSafetyContent"
          />

          <AssetChangesPanel :changes="assetChanges" :error="assetChangesFailed" />

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
          <div v-else-if="hasNoAssets" class="text-center py-20">
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

          <div v-else-if="isTypeView && !fullListLoaded" data-testid="assets-type-loading" class="flex flex-col items-center justify-center py-20">
            <p class="text-slate-400">{{ t('assets.loading') }}</p>
          </div>

          <!-- Assets by type -->
          <div v-else-if="isTypeView" class="space-y-4">
            <AssetTypeView
              :groups="typeGroups"
              :packaged-volumes="packagedVolumeCatalog"
              :selected-type-ids="selectedTypeIds"
              @toggle-selection="toggleTypeSelection"
            />
            <AssetSelectionBar
              v-if="selectedTypeGroups.length > 0"
              kind="type"
              :count="selectedTypeGroups.length"
              :location-names="typeSelectionLocationNames"
              :summary="typeSelectionSummary"
              :volume="typeSelectionVolume"
              @clear="clearTypeSelection"
            />
          </div>

          <!-- Assets by location -->
          <div v-else class="relative rounded-xl space-y-4" :class="{ 'hud-updated': assetsUpdated }">
            <PinnedContainersPanel v-if="isCorpView" :entries="pinnedEntries" @open="openPinnedContainer" />
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
                <option v-if="packagedVolumeCatalog !== null" value="volume">{{ t('assets.sort.volume') }}</option>
              </select>
            </div>
            <p
              v-if="packagedVolumesUnavailable"
              data-testid="packaged-volumes-notice"
              class="text-xs text-amber-400"
            >{{ t('assets.volume.notLoaded') }}</p>
            <AssetLocationGroup
              v-for="location in assetsByLocation"
              :key="location.locationName"
              :location="location"
              :expanded-containers="expandedContainers"
              :collapsed-locations="collapsedLocations"
              :container-contents="visibleContainerContents"
              :fit-contents="childRows"
              :max-quantity="largestLocationQuantity"
              :selected="selectedLocationNames.has(location.locationName)"
              :can-pin="isCorpView"
              :pinned-item-ids="pinnedItemIds"
              :project-pinned-item-ids="projectPinnedItemIds"
              :packaged-volumes="fullListLoaded ? packagedVolumeCatalog : null"
              @toggle-pin="onTogglePin"
              @toggle-container="toggleContainer"
              @toggle-location="toggleLocation"
              @toggle-selection="toggleLocationSelection"
              @item-name-resolved="applyResolvedItemName"
            />
            <AssetSelectionBar
              v-if="selectedLocations.length > 0 && selectionSummary !== null"
              :count="selectedLocations.length"
              :location-names="selectedLocations.map(location => location.locationName)"
              :summary="selectionSummary"
              :volume="selectionVolume"
              @clear="clearLocationSelection"
            />
            <p
              v-else-if="selectedLocations.length > 0"
              data-testid="selection-loading"
              class="text-xs text-slate-500"
            >{{ t('assets.selectionLoading') }}</p>
          </div>

          <!-- No results -->
          <div v-if="!isLoading && !hasNoAssets && (isTypeView ? fullListLoaded && typeGroups.length === 0 : assetsByLocation.length === 0)" class="text-center py-12">
            <p class="text-slate-500">{{ t('assets.noMatchingItems') }}</p>
          </div>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

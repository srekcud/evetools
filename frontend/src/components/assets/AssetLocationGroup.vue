<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useTypeNames } from '@/composables/useTypeNames'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import AssetItemNameResolveControl from '@/components/assets/AssetItemNameResolveControl.vue'
import AssetShipGroups from '@/components/assets/AssetShipGroups.vue'
import AssetBlueprintBadge from '@/components/assets/AssetBlueprintBadge.vue'
import AssetVolumeBadge from '@/components/assets/AssetVolumeBadge.vue'
import { apiRequest } from '@/services/api'
import { SHIP_CATEGORY_ID } from '@/constants/assetsConstants'
import { locationIssue } from '@/utils/assetLocation'
import { isFittedFlag, isGroupableShip } from '@/utils/shipFit'
import { summarizeVolume, type PackagedVolumeCatalog, type VolumeSummary } from '@/utils/assetVolume'
import type { Asset, ItemNameAnswer, ItemNameResolution } from '@/types'

const DESTINATION_LOCATION_TYPES = ['station', 'solar_system', 'other']

const props = defineProps<{
  location: {
    locationId: number
    locationName: string
    locationType?: string
    solarSystemName: string | null
    assets: Asset[]
    totalQuantity: number
    shipCount?: number
    divisions?: { name: string; count: number }[]
    volume?: VolumeSummary | null
  }
  maxQuantity?: number
  selected?: boolean
  expandedContainers: Set<number>
  collapsedLocations: Set<string>
  containerContents: Map<number, Asset[]>
  fitContents?: Map<number, Asset[]>
  canPin?: boolean
  pinnedItemIds?: Set<number>
  projectPinnedItemIds?: Set<number>
  packagedVolumes?: PackagedVolumeCatalog | null
}>()

const emit = defineEmits<{
  'toggle-container': [itemId: number]
  'toggle-location': [locationName: string]
  'toggle-selection': [locationName: string]
  'item-name-resolved': [itemId: number, itemName: string]
  'toggle-pin': [itemId: number]
}>()

const { t } = useI18n()
const issue = computed(() => locationIssue(props.location.locationName))
const canSetDestination = computed(() => props.location.locationType !== undefined && DESTINATION_LOCATION_TYPES.includes(props.location.locationType))
const resolutionByAssetId = ref<Record<string, ItemNameResolution>>({})

const { getTypeIconUrl, onImageError } = useEveImages()
const { typeNameLabel } = useTypeNames()

const groupedShips = computed(() => props.location.assets.filter(isGroupableShip))
const plainAssets = computed(() => props.location.assets.filter(asset => !isGroupableShip(asset)))

function fullContentsOf(itemId: number): Asset[] {
  return (props.fitContents ?? props.containerContents).get(itemId) ?? []
}

function containerVolume(asset: Asset): VolumeSummary | null {
  return props.packagedVolumes && isContainer(asset) ? summarizeVolume([asset], fullContentsOf, props.packagedVolumes) : null
}

function isAssembledShip(asset: Asset): boolean {
  return asset.categoryId === SHIP_CATEGORY_ID && asset.isSingleton === true
}

function isContainer(asset: Asset): boolean {
  if (isAssembledShip(asset)) {
    return getContainerContents(asset).length > 0
  }
  return props.containerContents.has(asset.itemId)
}

function containerToggleAttributes(asset: Asset): Record<string, unknown> {
  if (!isContainer(asset)) {
    return {}
  }
  return {
    role: 'button',
    tabindex: 0,
    'aria-expanded': props.expandedContainers.has(asset.itemId),
    onClick: () => emit('toggle-container', asset.itemId),
    onKeydown: (event: KeyboardEvent) => {
      if (event.target === event.currentTarget && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault()
        emit('toggle-container', asset.itemId)
      }
    },
  }
}

function isPinned(asset: Asset): boolean {
  return props.pinnedItemIds?.has(asset.itemId) === true
}

function isPinnedByProject(asset: Asset): boolean {
  return props.projectPinnedItemIds?.has(asset.itemId) === true
}

function pinLabel(asset: Asset): string {
  const name = resolvedItemName(asset) ?? typeNameLabel(asset.typeName, asset.typeId)
  if (isPinnedByProject(asset)) {
    return t('assets.pinned.lockedByProject', { name })
  }
  return t(isPinned(asset) ? 'assets.pinned.unpin' : 'assets.pinned.pin', { name })
}

function togglePin(asset: Asset): void {
  if (!isPinnedByProject(asset)) {
    emit('toggle-pin', asset.itemId)
  }
}

function toggleLocation(): void {
  emit('toggle-location', props.location.locationName)
}

function getContainerContents(asset: Asset): Asset[] {
  const contents = props.containerContents.get(asset.itemId) || []
  return isAssembledShip(asset) ? contents.filter(item => !isFittedFlag(item.locationFlag)) : contents
}

const quantityBarWidth = computed(() =>
  props.maxQuantity !== undefined && props.maxQuantity > 0
    ? `${(props.location.totalQuantity / props.maxQuantity) * 100}%`
    : null,
)

function formatNumber(n: number): string {
  return n.toLocaleString()
}

function resolvedItemName(asset: Asset): string | null {
  const resolution = resolutionByAssetId.value[asset.id]
  return resolution?.status === 'named' ? resolution.itemName : asset.itemName
}

function getDisplayName(asset: Asset): string {
  const typeName = typeNameLabel(asset.typeName, asset.typeId)
  const itemName = resolvedItemName(asset)
  if (itemName) {
    return `${itemName} (${typeName})`
  }
  return typeName
}

async function resolveItemName(asset: Asset): Promise<void> {
  if (resolutionByAssetId.value[asset.id]?.status === 'loading') {
    return
  }
  resolutionByAssetId.value[asset.id] = { status: 'loading' }
  try {
    const answer = await apiRequest<ItemNameAnswer>(`/me/assets/${asset.id}/item-name`, { method: 'POST' })
    if (answer.status === 'unnamed') {
      resolutionByAssetId.value[asset.id] = { status: 'unnamed' }
    } else if (answer.itemName === null) {
      resolutionByAssetId.value[asset.id] = { status: 'error', message: t('assets.itemName.invalidAnswer') }
    } else {
      resolutionByAssetId.value[asset.id] = { status: 'named', itemName: answer.itemName }
      emit('item-name-resolved', asset.itemId, answer.itemName)
    }
  } catch (error) {
    resolutionByAssetId.value[asset.id] = {
      status: 'error',
      message: error instanceof Error ? error.message : t('assets.itemName.invalidAnswer'),
    }
  }
}
</script>

<template>
  <div data-testid="location-group" class="bg-slate-900 rounded-lg border border-slate-800 overflow-hidden">
    <!-- Location header (clickable to collapse) -->
    <div
      role="button"
      tabindex="0"
      :aria-expanded="!props.collapsedLocations.has(props.location.locationName)"
      class="px-5 py-4 bg-slate-800/50 border-b border-slate-800 flex items-center justify-between cursor-pointer hover:bg-slate-800/70 transition-colors"
      @click="toggleLocation"
      @keydown.enter.self.prevent="toggleLocation"
      @keydown.space.self.prevent="toggleLocation"
    >
      <div class="flex items-center gap-3">
        <input
          type="checkbox"
          data-testid="location-select"
          :checked="props.selected === true"
          :aria-label="t('assets.selection.selectLocation', { location: props.location.locationName })"
          class="w-4 h-4 rounded-sm accent-cyan-500 cursor-pointer"
          @click.stop="emit('toggle-selection', props.location.locationName)"
          @keydown.stop
        />
        <div class="w-10 h-10 rounded-lg bg-slate-700/50 flex items-center justify-center">
          <svg
            :class="['w-5 h-5 text-slate-400 transition-transform', props.collapsedLocations.has(props.location.locationName) && '-rotate-90']"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
          </svg>
        </div>
        <div>
          <div class="flex items-center gap-2">
            <h3 class="font-medium text-slate-200">{{ props.location.locationName }}</h3>
            <OpenInGameButton v-if="canSetDestination" type="waypoint" :targetId="props.location.locationId" />
            <span
              v-if="issue"
              data-testid="location-badge"
              :title="t(`assets.locationIssue.${issue}.tooltip`)"
              class="px-2 py-0.5 rounded-sm text-xs font-medium uppercase tracking-wide bg-amber-500/10 text-amber-400 border border-amber-500/30"
            >{{ t(`assets.locationIssue.${issue}.badge`) }}</span>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="props.location.solarSystemName && !props.location.locationName.startsWith(props.location.solarSystemName)" class="text-xs text-cyan-400">{{ props.location.solarSystemName }}</span>
            <span class="text-xs text-slate-500">{{ t('assets.itemTypesCount', { count: props.location.assets.length }, props.location.assets.length) }}</span>
            <span
              v-if="props.location.shipCount"
              data-testid="location-ship-count"
              class="text-xs text-slate-500"
            >{{ t('assets.shipsCount', { count: formatNumber(props.location.shipCount) }, props.location.shipCount) }}</span>
          </div>
          <div v-if="props.location.divisions && props.location.divisions.length > 0" data-testid="location-division-breakdown" class="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-0.5">
            <span
              v-for="division in props.location.divisions"
              :key="division.name"
              data-testid="location-division"
              class="text-xs text-slate-500"
            >{{ division.name }} <span class="font-mono text-slate-400">{{ formatNumber(division.count) }}</span></span>
          </div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1 min-w-32">
        <span class="text-sm text-cyan-400 font-mono">{{ t('assets.itemsCount', { count: formatNumber(props.location.totalQuantity) }, props.location.totalQuantity) }}</span>
        <AssetVolumeBadge v-if="props.location.volume" :volume="props.location.volume" test-id="location-volume" />
        <div v-if="quantityBarWidth" class="h-1 w-full rounded-full bg-slate-700/60 overflow-hidden">
          <div data-testid="location-quantity-bar" class="h-full rounded-full bg-cyan-500" :style="{ width: quantityBarWidth }"></div>
        </div>
      </div>
    </div>

    <!-- Items (collapsible) -->
    <div v-if="!props.collapsedLocations.has(props.location.locationName)" class="divide-y divide-slate-800">
      <AssetShipGroups :ships="groupedShips" :contents-of="fullContentsOf" :packaged-volumes="props.packagedVolumes" />
      <template v-for="asset in plainAssets" :key="asset.id">
        <!-- Regular item or container header -->
        <div
          :class="[
            'px-5 py-3 flex items-center justify-between transition-colors',
            isContainer(asset) ? 'hover:bg-slate-800/50 cursor-pointer' : 'hover:bg-slate-800/30'
          ]"
          :data-asset-item-id="asset.itemId"
          v-bind="containerToggleAttributes(asset)"
        >
          <div class="flex items-center gap-3">
            <img
              :src="getTypeIconUrl(asset.typeId, 32, asset.categoryId ?? undefined)"
              :alt="typeNameLabel(asset.typeName, asset.typeId)"
              class="w-8 h-8 rounded-sm"
              loading="lazy"
              @error="onImageError"
            />
            <div class="flex items-center gap-2">
              <span class="text-slate-200">{{ getDisplayName(asset) }}</span>
              <OpenInGameButton type="market" :targetId="asset.typeId" />
              <OpenInGameButton v-if="isContainer(asset)" type="info" :targetId="asset.itemId" />
              <AssetItemNameResolveControl :asset="asset" :resolution="resolutionByAssetId[asset.id]" @resolve="resolveItemName" />
              <AssetBlueprintBadge v-if="asset.blueprint" :blueprint="asset.blueprint" />
              <!-- Container indicator -->
              <span v-if="isContainer(asset)" class="flex items-center gap-1 text-xs text-amber-400">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                {{ getContainerContents(asset).length }} items
                <svg
                  :class="['w-3 h-3 transition-transform', props.expandedContainers.has(asset.itemId) && 'rotate-180']"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </span>
              <AssetVolumeBadge v-if="containerVolume(asset)" :volume="containerVolume(asset)!" test-id="container-volume" />
              <button
                v-if="props.canPin && isContainer(asset)"
                type="button"
                data-testid="container-pin"
                :aria-label="pinLabel(asset)"
                :aria-pressed="isPinned(asset)"
                :title="pinLabel(asset)"
                :disabled="isPinnedByProject(asset)"
                :class="['p-0.5 rounded-sm transition-colors', isPinned(asset) ? 'text-amber-400' : 'text-slate-500 hover:text-amber-400', isPinnedByProject(asset) && 'cursor-not-allowed']"
                @click.stop="togglePin(asset)"
                @keydown.stop
              >
                <svg class="w-4 h-4" :fill="isPinned(asset) ? 'currentColor' : 'none'" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                </svg>
              </button>
              <span v-if="asset.divisionName" data-testid="asset-division" class="text-xs text-slate-500">{{ t('assets.divisionName', { name: asset.divisionName }) }}</span>
              <span v-else-if="!isContainer(asset) && asset.locationFlag" class="text-xs text-slate-500">({{ asset.locationFlag }})</span>
            </div>
          </div>
          <span class="text-slate-400 font-mono text-sm">x{{ formatNumber(asset.quantity) }}</span>
        </div>

        <!-- Container contents (when expanded) -->
        <div
          v-if="isContainer(asset) && props.expandedContainers.has(asset.itemId)"
          class="bg-slate-950/50 border-l-2 border-amber-500/30"
        >
          <div
            v-for="item in getContainerContents(asset)"
            :key="item.id"
            class="px-5 py-2 pl-12 flex items-center justify-between hover:bg-slate-800/20 transition-colors"
          >
            <div class="flex items-center gap-3">
              <img
                :src="getTypeIconUrl(item.typeId, 32, item.categoryId ?? undefined)"
                :alt="typeNameLabel(item.typeName, item.typeId)"
                class="w-6 h-6 rounded-sm"
                loading="lazy"
                @error="onImageError"
              />
              <span class="text-slate-300 text-sm">{{ getDisplayName(item) }}</span>
              <OpenInGameButton type="market" :targetId="item.typeId" />
              <AssetItemNameResolveControl :asset="item" :resolution="resolutionByAssetId[item.id]" @resolve="resolveItemName" />
              <AssetBlueprintBadge v-if="item.blueprint" :blueprint="item.blueprint" />
            </div>
            <span class="text-slate-500 font-mono text-sm">x{{ formatNumber(item.quantity) }}</span>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useTypeNames } from '@/composables/useTypeNames'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import AssetItemNameResolveControl from '@/components/assets/AssetItemNameResolveControl.vue'
import { apiRequest } from '@/services/api'
import type { Asset, ItemNameAnswer, ItemNameResolution } from '@/types'

const props = defineProps<{
  location: {
    locationId: number
    locationName: string
    solarSystemName: string | null
    assets: Asset[]
    totalQuantity: number
  }
  expandedContainers: Set<number>
  collapsedLocations: Set<string>
  containerContents: Map<number, Asset[]>
}>()

const emit = defineEmits<{
  'toggle-container': [itemId: number]
  'toggle-location': [locationName: string]
  'item-name-resolved': [itemId: number, itemName: string]
}>()

const { t } = useI18n()
const resolutionByAssetId = ref<Record<string, ItemNameResolution>>({})

const { getTypeIconUrl, onImageError } = useEveImages()
const { typeNameLabel } = useTypeNames()

function isContainer(asset: Asset): boolean {
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

function toggleLocation(): void {
  emit('toggle-location', props.location.locationName)
}

function getContainerContents(asset: Asset): Asset[] {
  return props.containerContents.get(asset.itemId) || []
}

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
  <div class="bg-slate-900 rounded-lg border border-slate-800 overflow-hidden">
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
          <h3 class="font-medium text-slate-200">{{ props.location.locationName }}</h3>
          <div class="flex items-center gap-2">
            <span v-if="props.location.solarSystemName && !props.location.locationName.startsWith(props.location.solarSystemName)" class="text-xs text-cyan-400">{{ props.location.solarSystemName }}</span>
            <span class="text-xs text-slate-500">{{ t('assets.itemTypesCount', { count: props.location.assets.length }, props.location.assets.length) }}</span>
          </div>
        </div>
      </div>
      <span class="text-sm text-cyan-400 font-mono">{{ t('assets.itemsCount', { count: formatNumber(props.location.totalQuantity) }, props.location.totalQuantity) }}</span>
    </div>

    <!-- Items (collapsible) -->
    <div v-if="!props.collapsedLocations.has(props.location.locationName)" class="divide-y divide-slate-800">
      <template v-for="asset in props.location.assets" :key="asset.id">
        <!-- Regular item or container header -->
        <div
          :class="[
            'px-5 py-3 flex items-center justify-between transition-colors',
            isContainer(asset) ? 'hover:bg-slate-800/50 cursor-pointer' : 'hover:bg-slate-800/30'
          ]"
          v-bind="containerToggleAttributes(asset)"
        >
          <div class="flex items-center gap-3">
            <img
              :src="getTypeIconUrl(asset.typeId, 32, asset.categoryId ?? undefined)"
              :alt="typeNameLabel(asset.typeName, asset.typeId)"
              class="w-8 h-8 rounded-sm"
              @error="onImageError"
            />
            <div class="flex items-center gap-2">
              <span class="text-slate-200">{{ getDisplayName(asset) }}</span>
              <OpenInGameButton type="market" :targetId="asset.typeId" />
              <AssetItemNameResolveControl :asset="asset" :resolution="resolutionByAssetId[asset.id]" @resolve="resolveItemName" />
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
              <span v-else-if="asset.locationFlag" class="text-xs text-slate-500">({{ asset.locationFlag }})</span>
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
                @error="onImageError"
              />
              <span class="text-slate-300 text-sm">{{ getDisplayName(item) }}</span>
              <OpenInGameButton type="market" :targetId="item.typeId" />
              <AssetItemNameResolveControl :asset="item" :resolution="resolutionByAssetId[item.id]" @resolve="resolveItemName" />
            </div>
            <span class="text-slate-500 font-mono text-sm">x{{ formatNumber(item.quantity) }}</span>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

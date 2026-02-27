<script setup lang="ts">
import { useEveImages } from '@/composables/useEveImages'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import type { Asset } from '@/types'

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
}>()

const { getTypeIconUrl, onImageError } = useEveImages()

function isContainer(asset: Asset): boolean {
  return props.containerContents.has(asset.itemId)
}

function getContainerContents(asset: Asset): Asset[] {
  return props.containerContents.get(asset.itemId) || []
}

function formatNumber(n: number): string {
  return n.toLocaleString()
}

function getDisplayName(asset: Asset): string {
  if (asset.itemName) {
    return `${asset.itemName} (${asset.typeName})`
  }
  return asset.typeName
}
</script>

<template>
  <div class="bg-slate-900 rounded-lg border border-slate-800 overflow-hidden">
    <!-- Location header (clickable to collapse) -->
    <div
      class="px-5 py-4 bg-slate-800/50 border-b border-slate-800 flex items-center justify-between cursor-pointer hover:bg-slate-800/70 transition-colors"
      @click="emit('toggle-location', props.location.locationName)"
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
            <span class="text-xs text-slate-500">{{ props.location.assets.length }} types d'items</span>
          </div>
        </div>
      </div>
      <span class="text-sm text-cyan-400 font-mono">{{ formatNumber(props.location.totalQuantity) }} items</span>
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
          @click="isContainer(asset) && emit('toggle-container', asset.itemId)"
        >
          <div class="flex items-center gap-3">
            <img
              :src="getTypeIconUrl(asset.typeId, 32, asset.categoryId ?? undefined)"
              :alt="asset.typeName"
              class="w-8 h-8 rounded-sm"
              @error="onImageError"
            />
            <div class="flex items-center gap-2">
              <span class="text-slate-200">{{ getDisplayName(asset) }}</span>
              <OpenInGameButton type="market" :targetId="asset.typeId" />
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
                :alt="item.typeName"
                class="w-6 h-6 rounded-sm"
                @error="onImageError"
              />
              <span class="text-slate-300 text-sm">{{ getDisplayName(item) }}</span>
              <OpenInGameButton type="market" :targetId="item.typeId" />
            </div>
            <span class="text-slate-500 font-mono text-sm">x{{ formatNumber(item.quantity) }}</span>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

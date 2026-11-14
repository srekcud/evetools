<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import AssetVolumeBadge from '@/components/assets/AssetVolumeBadge.vue'
import { sortTypeGroups, typeGroupVolume, type SortDirection, type TypeGroup, type TypeSortKey } from '@/utils/assetsByType'
import type { PackagedVolumeCatalog } from '@/utils/assetVolume'

const props = withDefaults(defineProps<{
  groups: TypeGroup[]
  packagedVolumes?: PackagedVolumeCatalog | null
  selectedTypeIds?: ReadonlySet<number>
}>(), {
  packagedVolumes: null,
  selectedTypeIds: () => new Set<number>(),
})

const emit = defineEmits<{
  'toggle-selection': [typeId: number]
}>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()

const DEFAULT_DIRECTION: Record<TypeSortKey, SortDirection> = { quantity: 'desc', name: 'asc', volume: 'desc' }

const sortKey = ref<TypeSortKey>('quantity')
const sortDirection = ref<SortDirection>(DEFAULT_DIRECTION.quantity)
const expandedTypeIds = ref<Set<number>>(new Set())

const sortedGroups = computed(() => sortTypeGroups(props.groups, sortKey.value, sortDirection.value, props.packagedVolumes))

function volumeOf(group: TypeGroup) {
  return props.packagedVolumes === null ? null : typeGroupVolume(group, props.packagedVolumes)
}

function chooseSort(key: TypeSortKey): void {
  if (sortKey.value === key) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    return
  }
  sortKey.value = key
  sortDirection.value = DEFAULT_DIRECTION[key]
}

function sortIndicator(key: TypeSortKey): string {
  if (sortKey.value !== key) return ''
  return sortDirection.value === 'asc' ? '▲' : '▼'
}

function toggleType(typeId: number): void {
  const next = new Set(expandedTypeIds.value)
  if (next.has(typeId)) {
    next.delete(typeId)
  } else {
    next.add(typeId)
  }
  expandedTypeIds.value = next
}

function formatNumber(value: number): string {
  return value.toLocaleString()
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <div class="px-5 py-3 flex items-center justify-between gap-4 bg-slate-800/50 border-b border-slate-800 text-xs uppercase tracking-wider text-slate-400">
      <button
        type="button"
        class="hover:text-slate-200 transition-colors"
        :aria-pressed="sortKey === 'name'"
        @click="chooseSort('name')"
      >
        {{ t('assets.byType.sortName') }} {{ sortIndicator('name') }}
      </button>
      <button
        v-if="packagedVolumes !== null"
        type="button"
        class="hover:text-slate-200 transition-colors"
        :aria-pressed="sortKey === 'volume'"
        @click="chooseSort('volume')"
      >
        {{ t('assets.byType.sortVolume') }} {{ sortIndicator('volume') }}
      </button>
      <button
        type="button"
        class="hover:text-slate-200 transition-colors"
        :aria-pressed="sortKey === 'quantity'"
        @click="chooseSort('quantity')"
      >
        {{ t('assets.byType.sortQuantity') }} {{ sortIndicator('quantity') }}
      </button>
    </div>

    <div class="divide-y divide-slate-800">
      <div v-for="group in sortedGroups" :key="group.typeId">
        <div data-testid="asset-type-row" class="px-5 py-3 flex items-center justify-between gap-4 hover:bg-slate-800/30 transition-colors">
          <div class="flex items-center gap-3 min-w-0">
            <input
              type="checkbox"
              data-testid="type-select"
              :checked="props.selectedTypeIds.has(group.typeId)"
              :aria-label="t('assets.selection.selectType', { type: group.typeName })"
              class="w-4 h-4 rounded-sm accent-cyan-500 cursor-pointer shrink-0"
              @click="emit('toggle-selection', group.typeId)"
            />
            <button
              type="button"
              class="flex items-center gap-3 min-w-0 text-left"
              :aria-expanded="expandedTypeIds.has(group.typeId)"
              :aria-label="t('assets.byType.toggleLocations', { type: group.typeName })"
              @click="toggleType(group.typeId)"
            >
              <img
                :src="getTypeIconUrl(group.typeId, 32, group.categoryId ?? undefined)"
                :alt="group.typeName"
                class="w-8 h-8 rounded-sm shrink-0"
                loading="lazy"
                @error="onImageError"
              />
              <span class="text-slate-200 truncate">{{ group.typeName }}</span>
              <span class="flex items-center gap-1 text-xs text-amber-400 shrink-0">
                {{ t('assets.byType.locationsCount', { count: group.locations.length }, group.locations.length) }}
                <svg
                  :class="['w-3 h-3 transition-transform', expandedTypeIds.has(group.typeId) && 'rotate-180']"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </span>
            </button>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <AssetVolumeBadge v-if="volumeOf(group)" :volume="volumeOf(group)!" test-id="type-volume" />
            <OpenInGameButton type="market" :targetId="group.typeId" />
            <span class="font-mono text-slate-300">{{ formatNumber(group.totalQuantity) }}</span>
          </div>
        </div>

        <ul
          v-if="expandedTypeIds.has(group.typeId)"
          data-testid="asset-type-locations"
          :aria-label="t('assets.byType.locationsListLabel')"
          class="bg-slate-950/40 divide-y divide-slate-800/60"
        >
          <li
            v-for="location in group.locations"
            :key="location.locationName"
            class="pl-16 pr-5 py-2 flex items-center justify-between gap-4 text-sm"
          >
            <span class="text-slate-400 truncate">
              {{ location.locationName }}
              <span v-if="location.solarSystemName" class="text-slate-500">· {{ location.solarSystemName }}</span>
            </span>
            <span class="font-mono text-slate-300 shrink-0">{{ formatNumber(location.quantity) }}</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

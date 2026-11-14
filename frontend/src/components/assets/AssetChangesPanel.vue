<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { AssetChange, AssetChanges } from '@/types'

const props = defineProps<{
  changes: AssetChanges | null
  error: boolean
}>()

const { t } = useI18n()
const { formatDateTime } = useFormatters()

const MINUS_SIGN = '−'

const isExpanded = ref(false)

interface ChangeLocation {
  locationId: number
  locationName: string
  lines: AssetChange[]
}

const isRecorded = computed(() => props.changes !== null && props.changes.syncedAt !== null)

const addedUnits = computed(() => sumOf(change => change.quantityDelta > 0 ? change.quantityDelta : 0))
const removedUnits = computed(() => sumOf(change => change.quantityDelta < 0 ? -change.quantityDelta : 0))

const locations = computed<ChangeLocation[]>(() => {
  const byLocation = new Map<number, ChangeLocation>()
  for (const change of props.changes?.changes ?? []) {
    const location = byLocation.get(change.locationId) ?? { locationId: change.locationId, locationName: change.locationName, lines: [] }
    location.lines.push(change)
    byLocation.set(change.locationId, location)
  }
  return [...byLocation.values()]
})

const interval = computed(() => {
  const changes = props.changes
  if (changes === null || changes.previousSyncedAt === null || changes.syncedAt === null) return ''
  return t('assets.changes.interval', { from: formatDateTime(changes.previousSyncedAt), to: formatDateTime(changes.syncedAt) })
})

function sumOf(units: (change: AssetChange) => number): number {
  return (props.changes?.changes ?? []).reduce((total, change) => total + units(change), 0)
}

function signedQuantity(delta: number): string {
  const magnitude = Math.abs(delta).toLocaleString()
  return delta > 0 ? `+${magnitude}` : `${MINUS_SIGN}${magnitude}`
}

function typeLabel(change: AssetChange): string {
  return change.typeName ?? `#${change.typeId}`
}
</script>

<template>
  <p v-if="error" data-testid="asset-changes-error" class="mb-4 text-sm text-red-400">{{ t('assets.changes.loadFailed') }}</p>
  <section
    v-else-if="isRecorded"
    data-testid="asset-changes-panel"
    class="mb-6 rounded-lg border border-slate-700 bg-slate-900/50"
  >
    <button
      type="button"
      data-testid="asset-changes-toggle"
      class="flex w-full flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 text-left"
      :aria-expanded="isExpanded"
      @click="isExpanded = !isExpanded"
    >
      <span class="font-semibold text-slate-200">{{ t('assets.changes.title') }}</span>
      <span class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
        <span data-testid="asset-changes-interval" class="text-slate-500">{{ interval }}</span>
        <span data-testid="asset-changes-added" class="font-mono text-emerald-400">{{ t('assets.changes.added', { count: addedUnits.toLocaleString() }) }}</span>
        <span data-testid="asset-changes-removed" class="font-mono text-red-400">{{ t('assets.changes.removed', { count: removedUnits.toLocaleString() }) }}</span>
      </span>
    </button>
    <p v-if="locations.length === 0" data-testid="asset-changes-empty" class="border-t border-slate-800 px-4 py-3 text-sm text-slate-400">{{ t('assets.changes.empty') }}</p>
    <div v-else-if="isExpanded" class="border-t border-slate-800 px-4 py-3">
      <div
        v-for="location in locations"
        :key="location.locationId"
        data-testid="asset-changes-location"
        class="mb-3 last:mb-0"
      >
        <p class="mb-1 text-sm font-medium text-cyan-400">{{ location.locationName }}</p>
        <ul class="divide-y divide-slate-800">
          <li
            v-for="line in location.lines"
            :key="`${line.typeId}-${line.divisionName ?? ''}`"
            data-testid="asset-changes-line"
            class="flex items-center gap-3 py-1 text-sm"
          >
            <span
              data-testid="asset-changes-delta"
              class="w-20 shrink-0 text-right font-mono"
              :class="line.quantityDelta > 0 ? 'text-emerald-400' : 'text-red-400'"
            >{{ signedQuantity(line.quantityDelta) }}</span>
            <span class="text-slate-200">{{ typeLabel(line) }}</span>
            <span v-if="line.divisionName" class="text-xs text-slate-500">{{ line.divisionName }}</span>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>

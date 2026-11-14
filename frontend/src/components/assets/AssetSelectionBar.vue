<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AssetCourierContractPanel from '@/components/assets/AssetCourierContractPanel.vue'
import AssetVolumeBadge from '@/components/assets/AssetVolumeBadge.vue'
import MultibuyBridgeActions from '@/components/appraisal/MultibuyBridgeActions.vue'
import type { SelectionSummary } from '@/utils/assetSelection'
import type { VolumeSummary } from '@/utils/assetVolume'

const props = defineProps<{
  locationCount: number
  locationNames: string[]
  summary: SelectionSummary
  volume: VolumeSummary | null
}>()

defineEmits<{
  clear: []
}>()

const { t } = useI18n()

const courierOpen = ref(false)
const canDraftCourierContract = computed(() => props.volume !== null && props.volume.cubicMeters > 0)

function formatNumber(n: number): string {
  return n.toLocaleString()
}
</script>

<template>
  <div class="sticky bottom-4 z-10 flex flex-col gap-2">
  <AssetCourierContractPanel
    v-if="courierOpen && volume !== null"
    :location-names="locationNames"
    :volume="volume"
    @close="courierOpen = false"
  />
  <div
    data-testid="asset-selection-bar"
    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-cyan-500/30 bg-slate-900/95 px-5 py-3 shadow-lg backdrop-blur"
  >
    <div class="flex flex-col gap-0.5">
      <div class="flex items-center gap-3 text-sm">
        <span data-testid="asset-selection-count" class="text-cyan-300 font-medium">
          {{ t('assets.selection.summary', { count: formatNumber(locationCount) }, locationCount) }}
        </span>
        <span class="text-slate-400 font-mono">{{ t('assets.itemsCount', { count: formatNumber(summary.itemCount) }, summary.itemCount) }}</span>
        <AssetVolumeBadge v-if="volume !== null" :volume="volume" test-id="asset-selection-volume" />
      </div>
      <span
        v-if="summary.unknownTypeCount > 0"
        data-testid="asset-selection-unknown"
        class="text-xs text-amber-400"
      >{{ t('assets.selection.unknownTypes', { count: formatNumber(summary.unknownTypeCount) }, summary.unknownTypeCount) }}</span>
    </div>
    <div class="flex items-center gap-2">
      <MultibuyBridgeActions :lines="summary.lines" />
      <button
        v-if="volume !== null"
        type="button"
        data-testid="courier-contract-open"
        :disabled="!canDraftCourierContract"
        class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg transition-colors border border-slate-700 disabled:opacity-40 disabled:cursor-not-allowed"
        @click="courierOpen = !courierOpen"
      >{{ t('assets.courier.open') }}</button>
      <button
        type="button"
        data-testid="asset-selection-clear"
        class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg transition-colors border border-slate-700"
        @click="$emit('clear')"
      >{{ t('assets.selection.clear') }}</button>
    </div>
  </div>
  </div>
</template>

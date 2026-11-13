<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import MultibuyBridgeActions from '@/components/appraisal/MultibuyBridgeActions.vue'
import type { SelectionSummary } from '@/utils/assetSelection'

defineProps<{
  locationCount: number
  summary: SelectionSummary
}>()

defineEmits<{
  clear: []
}>()

const { t } = useI18n()

function formatNumber(n: number): string {
  return n.toLocaleString()
}
</script>

<template>
  <div
    data-testid="asset-selection-bar"
    class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-cyan-500/30 bg-slate-900/95 px-5 py-3 shadow-lg backdrop-blur"
  >
    <div class="flex flex-col gap-0.5">
      <div class="flex items-center gap-3 text-sm">
        <span data-testid="asset-selection-count" class="text-cyan-300 font-medium">
          {{ t('assets.selection.summary', { count: formatNumber(locationCount) }, locationCount) }}
        </span>
        <span class="text-slate-400 font-mono">{{ t('assets.itemsCount', { count: formatNumber(summary.itemCount) }, summary.itemCount) }}</span>
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
        type="button"
        data-testid="asset-selection-clear"
        class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg transition-colors border border-slate-700"
        @click="$emit('clear')"
      >{{ t('assets.selection.clear') }}</button>
    </div>
  </div>
</template>

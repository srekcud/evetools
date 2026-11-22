<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { PivotStockStation } from '@/stores/industry/types'

defineProps<{
  stations: PivotStockStation[]
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
</script>

<template>
  <div class="rounded-md border border-slate-700/60 bg-slate-900/60 px-3 py-2">
    <div class="mb-1 text-xs uppercase tracking-wider text-slate-500">{{ t('industry.scanner.pivotAdvisor.stockByStation.heading') }}</div>
    <ul class="space-y-1">
      <li
        v-for="station in stations"
        :key="station.locationId"
        class="flex items-center justify-between gap-3 text-xs"
      >
        <span class="min-w-0 truncate text-slate-300">
          {{ station.locationName }}
          <span v-if="station.systemName != null" class="text-slate-500">({{ station.systemName }})</span>
        </span>
        <span class="shrink-0 font-mono text-cyan-400">{{ formatNumber(station.quantity, 0) }}</span>
      </li>
    </ul>
  </div>
</template>

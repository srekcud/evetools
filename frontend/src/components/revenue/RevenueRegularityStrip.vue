<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { RevenueDailyAmount, RevenueGridPeriod } from '@/types/revenueGrid'
import { UNKNOWN_HATCH, buildRegularityStrip, hexWithAlpha, stripCellIntensity } from '@/utils/revenueGrid'

const props = defineProps<{
  daily: RevenueDailyAmount[] | null
  period: RevenueGridPeriod
  color: string
}>()

const { t } = useI18n()
const { formatIskFull } = useFormatters()

const EMPTY_CELL_COLOR = 'rgba(51,65,85,.35)'
const DAILY_CELL_WIDTH_PX = 4
const NARROW_CELL_WIDTH_PX = 2
const NARROW_CELL_THRESHOLD = 30

const strip = computed(() => (props.daily === null ? null : buildRegularityStrip(props.daily, props.period.to, props.period.days)))
const cellWidth = computed(() => (strip.value !== null && strip.value.cells.length > NARROW_CELL_THRESHOLD ? NARROW_CELL_WIDTH_PX : DAILY_CELL_WIDTH_PX))
</script>

<template>
  <div class="flex flex-col items-end gap-1" data-testid="regularity-strip">
    <template v-if="strip">
      <div class="flex gap-px" role="img" :aria-label="t('revenue.grid.regularity')">
        <span
          v-for="cell in strip.cells"
          :key="cell.date"
          class="inline-block h-3"
          :style="{
            width: `${cellWidth}px`,
            background: cell.amount === 0 ? EMPTY_CELL_COLOR : hexWithAlpha(color, stripCellIntensity(cell.amount, strip.cells)),
          }"
          :title="`${cell.date} : ${formatIskFull(cell.amount)}`"
        ></span>
      </div>
      <span class="font-mono text-xs text-slate-500">
        {{ t('revenue.grid.stripCount', { active: strip.activeCount, total: strip.cells.length, unit: t(`revenue.grid.stripUnit.${strip.unit}`) }) }}
      </span>
    </template>
    <template v-else>
      <span class="inline-block h-3 w-24" :style="{ background: UNKNOWN_HATCH }" :title="t('revenue.grid.regularityUnknown')"></span>
      <span class="font-mono text-xs text-slate-500">—</span>
    </template>
  </div>
</template>

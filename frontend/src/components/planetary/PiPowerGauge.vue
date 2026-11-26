<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

type GaugeState = 'normal' | 'warning' | 'over' | 'unknown'

const WARNING_PERCENT = 85
const FULL_PERCENT = 100

const props = defineProps<{
  kind: 'cpu' | 'pg'
  used: number | null | undefined
  max: number | null | undefined
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()

const figures = computed(() => {
  if (props.used === null || props.used === undefined || props.max === null || props.max === undefined || props.max <= 0) return null
  return { used: props.used, max: props.max }
})

const state = computed<GaugeState>(() => {
  if (figures.value === null) return 'unknown'
  const { used, max } = figures.value
  if (used > max) return 'over'
  if (used * FULL_PERCENT >= max * WARNING_PERCENT) return 'warning'
  return 'normal'
})

const percent = computed(() => (figures.value === null ? 0 : Math.round((figures.value.used / figures.value.max) * FULL_PERCENT)))
const barWidth = computed(() => `${Math.min(percent.value, FULL_PERCENT)}%`)
const free = computed(() => (figures.value === null ? 0 : figures.value.max - figures.value.used))

const STATE_TEXT: Record<GaugeState, string> = {
  normal: 'text-cyan-400',
  warning: 'text-amber-400',
  over: 'text-red-400',
  unknown: 'text-slate-500',
}

const STATE_BAR: Record<GaugeState, string> = {
  normal: 'bg-cyan-400',
  warning: 'bg-amber-400',
  over: 'bg-red-500',
  unknown: 'bg-slate-700',
}
</script>

<template>
  <div
    :data-testid="`power-gauge-${kind}`"
    :data-state="state"
    :title="t('pi.power.linksNotCounted')"
    class="min-w-0"
  >
    <div class="flex items-baseline justify-between gap-2 text-xs">
      <span class="font-semibold uppercase tracking-wider text-slate-400">{{ t(`pi.power.${kind}`) }}</span>
      <span v-if="figures === null" class="text-slate-500 italic">{{ t('pi.power.unknown') }}</span>
      <span v-else :class="['font-mono-tech truncate', STATE_TEXT[state]]">{{ formatNumber(figures.used, 0) }} / {{ formatNumber(figures.max, 0) }}</span>
    </div>
    <template v-if="figures !== null">
      <div class="mt-1 h-1.5 w-full rounded-xs bg-slate-700 overflow-hidden">
        <div
          :data-testid="`power-gauge-${kind}-bar`"
          :class="['h-full rounded-xs', STATE_BAR[state]]"
          :style="{ width: barWidth }"
        ></div>
      </div>
      <div class="mt-0.5 flex items-baseline justify-between gap-2 text-[11px] font-mono-tech">
        <span :class="STATE_TEXT[state]">{{ t('pi.power.free', { free: formatNumber(free, 0) }) }}</span>
        <span :class="STATE_TEXT[state]">{{ percent }}%</span>
      </div>
    </template>
  </div>
</template>

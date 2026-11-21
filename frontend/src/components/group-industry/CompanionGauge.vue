<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

const FULL_PERCENT = 100
const STROKE_WIDTH = 4
const PERCENT_DECIMALS = 0

const props = defineProps<{
  percent: number
  diameter: number
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()

const radius = computed(() => (props.diameter - STROKE_WIDTH) / 2)
const circumference = computed(() => 2 * Math.PI * radius.value)
const clampedPercent = computed(() => Math.min(FULL_PERCENT, Math.max(0, props.percent)))
const dashOffset = computed(() => circumference.value * (1 - clampedPercent.value / FULL_PERCENT))
const label = computed(() => t('groupIndustry.companion.bomFulfillment', { percent: formatNumber(props.percent, PERCENT_DECIMALS) }))
</script>

<template>
  <div class="relative flex-shrink-0" :style="{ width: `${diameter}px`, height: `${diameter}px` }" :title="label">
    <svg
      data-testid="companion-bom-gauge"
      :data-percent="clampedPercent"
      :width="diameter"
      :height="diameter"
      :viewBox="`0 0 ${diameter} ${diameter}`"
      class="-rotate-90"
      role="img"
      :aria-label="label"
    >
      <circle :cx="diameter / 2" :cy="diameter / 2" :r="radius" fill="none" stroke="#1c2633" :stroke-width="STROKE_WIDTH" />
      <circle
        :cx="diameter / 2"
        :cy="diameter / 2"
        :r="radius"
        fill="none"
        stroke="#00d4ff"
        :stroke-width="STROKE_WIDTH"
        :stroke-dasharray="circumference"
        :stroke-dashoffset="dashOffset"
        style="filter: drop-shadow(0 0 3px #00d4ff);"
      />
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center leading-none">
      <span data-testid="companion-bom-percent" class="font-mono text-xs text-cyan-300" style="font-variant-numeric: tabular-nums;">{{ formatNumber(percent, PERCENT_DECIMALS) }}%</span>
    </div>
  </div>
</template>

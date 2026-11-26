<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ExtractorYieldCurve } from '@/stores/planetary'

const props = defineProps<{
  curve: ExtractorYieldCurve | null
  installTime: string | null
  expiryTime: string | null
}>()

const VIEW_WIDTH = 320
const VIEW_HEIGHT = 110
const PADDING_X = 8
const PADDING_Y = 10
const SECONDS_PER_HOUR = 3_600
const MS_PER_SECOND = 1_000
const PERCENT = 100

const { t } = useI18n()
const { formatNumber } = useFormatters()

const plotWidth = VIEW_WIDTH - 2 * PADDING_X
const plotHeight = VIEW_HEIGHT - 2 * PADDING_Y
const baseline = VIEW_HEIGHT - PADDING_Y

function rounded(value: number): number {
  return Math.round(value * PERCENT) / PERCENT
}

const hourlyRates = computed(() => {
  const curve = props.curve
  if (curve === null) return []
  return curve.yields.map(cycleYield => cycleYield * SECONDS_PER_HOUR / curve.cycleSeconds)
})

const initialRate = computed(() => hourlyRates.value[0] ?? null)

const durationSeconds = computed(() => {
  const curve = props.curve
  if (curve === null) return 0
  if (props.installTime !== null && props.expiryTime !== null) {
    const seconds = (Date.parse(props.expiryTime) - Date.parse(props.installTime)) / MS_PER_SECOND
    if (seconds > 0) return seconds
  }
  return curve.yields.length * curve.cycleSeconds
})

const rateCeiling = computed(() => {
  const ceiling = Math.max(0, ...hourlyRates.value)
  return ceiling > 0 ? ceiling : 1
})

function xAt(seconds: number): number {
  return rounded(PADDING_X + seconds / durationSeconds.value * plotWidth)
}

function yAt(rate: number): number {
  return rounded(baseline - rate / rateCeiling.value * plotHeight)
}

const linePoints = computed(() => {
  const curve = props.curve
  if (curve === null) return ''
  return hourlyRates.value
    .map((rate, index) => `${xAt((index + 0.5) * curve.cycleSeconds)},${yAt(rate)}`)
    .join(' ')
})

const constantRateY = computed(() => (initialRate.value === null ? null : yAt(initialRate.value)))

const nowX = computed(() => {
  if (props.installTime === null) return null
  const elapsedSeconds = (Date.now() - Date.parse(props.installTime)) / MS_PER_SECOND
  if (elapsedSeconds < 0 || elapsedSeconds > durationSeconds.value) return null
  return xAt(elapsedSeconds)
})

const constantRateGap = computed(() => {
  const curve = props.curve
  if (curve === null || initialRate.value === null || curve.averagePerHour <= 0) return null
  const percent = Math.round((initialRate.value / curve.averagePerHour - 1) * PERCENT)
  return `${percent > 0 ? '+' : ''}${percent}%`
})
</script>

<template>
  <div data-testid="yield-curve" class="mt-2 rounded-sm border border-slate-700/60 bg-slate-900/60 px-2 py-1.5">
    <div class="mb-1 flex items-center justify-between gap-2 text-xs uppercase tracking-wider text-slate-500">
      <span class="truncate">{{ t('pi.curve.yield.title') }}</span>
      <span v-if="curve !== null" class="whitespace-nowrap font-mono-tech text-slate-500">{{ t('pi.curve.yield.unit') }}</span>
    </div>

    <p v-if="curve === null" data-testid="yield-curve-unknown" class="text-xs italic text-slate-500">
      {{ t('pi.curve.yield.unknown') }}
    </p>

    <template v-else>
      <svg
        :viewBox="`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`"
        class="h-auto w-full"
        role="img"
        :aria-label="t('pi.curve.yield.ariaLabel')"
      >
        <line :x1="PADDING_X" :y1="baseline" :x2="VIEW_WIDTH - PADDING_X" :y2="baseline" class="text-slate-700" stroke="currentColor" stroke-width="1" />
        <line
          v-if="constantRateY !== null"
          data-testid="yield-curve-constant"
          :x1="xAt(0)"
          :y1="constantRateY"
          :x2="xAt(durationSeconds)"
          :y2="constantRateY"
          class="text-slate-400"
          stroke="currentColor"
          stroke-width="1"
          stroke-dasharray="4 3"
        />
        <polyline
          data-testid="yield-curve-line"
          :points="linePoints"
          class="text-cyan-400"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linejoin="round"
        />
        <line
          v-if="nowX !== null"
          data-testid="yield-curve-now"
          :x1="nowX"
          :y1="PADDING_Y"
          :x2="nowX"
          :y2="baseline"
          class="text-emerald-400"
          stroke="currentColor"
          stroke-width="1.5"
        />
      </svg>

      <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-400">
        <span class="inline-flex items-center gap-1 text-slate-500">
          <span class="inline-block h-0 w-3 border-t border-dashed border-slate-400"></span>
          {{ t('pi.curve.yield.constantRate') }}
        </span>
        <span v-if="nowX !== null" class="inline-flex items-center gap-1 text-emerald-400">
          <span class="inline-block h-2.5 w-0.5 bg-emerald-400"></span>
          {{ t('pi.curve.now') }}
        </span>
      </div>

      <p data-testid="yield-curve-summary" class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 font-mono-tech text-xs text-slate-300">
        <span>{{ t('pi.curve.yield.total', { quantity: formatNumber(curve.totalQuantity, 0) }) }}</span>
        <span>{{ t('pi.curve.yield.average', { quantity: formatNumber(curve.averagePerHour, 0) }) }}</span>
        <span v-if="constantRateGap !== null">{{ t('pi.curve.yield.constantGap', { gap: constantRateGap }) }}</span>
      </p>

      <p v-if="curve.suggestedDays !== null" data-testid="yield-curve-suggestion" class="mt-1 text-xs text-cyan-400">
        {{ t('pi.curve.yield.suggestion', { days: curve.suggestedDays }) }}
      </p>
    </template>
  </div>
</template>

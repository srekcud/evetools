<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { PlanetaryInputNeedsLaunchpad } from '@/stores/planetary'

interface StockProjection {
  startMs: number
  endMs: number
  usedVolume: number
  capacity: number
  emptyAtMs: number | null
}

const props = defineProps<{
  launchpad: PlanetaryInputNeedsLaunchpad
}>()

const VIEW_WIDTH = 320
const VIEW_HEIGHT = 110
const PADDING_X = 8
const PADDING_Y = 10
const MS_PER_DAY = 86_400_000
const FLAT_HORIZON_DAYS = 2
const COORDINATE_PRECISION = 100

const { t } = useI18n()
const { formatNumber, formatDateTime, formatDuration } = useFormatters()

const plotWidth = VIEW_WIDTH - 2 * PADDING_X
const plotHeight = VIEW_HEIGHT - 2 * PADDING_Y
const baseline = VIEW_HEIGHT - PADDING_Y

function rounded(value: number): number {
  return Math.round(value * COORDINATE_PRECISION) / COORDINATE_PRECISION
}

const projection = computed<StockProjection | null>(() => {
  const { capacityM3, usedVolumeM3, dailyVolumeM3, lastViewAt } = props.launchpad
  if (capacityM3 == null || usedVolumeM3 == null || dailyVolumeM3 == null || lastViewAt === undefined) return null

  const startMs = Date.parse(lastViewAt)
  const nowMs = Date.now()
  const emptyAtMs = dailyVolumeM3 > 0 ? startMs + usedVolumeM3 / dailyVolumeM3 * MS_PER_DAY : null
  const horizonMs = emptyAtMs ?? Math.max(nowMs, startMs) + FLAT_HORIZON_DAYS * MS_PER_DAY
  const endMs = Math.max(horizonMs, nowMs)

  return {
    startMs,
    endMs: endMs > startMs ? endMs : startMs + MS_PER_DAY,
    usedVolume: usedVolumeM3,
    capacity: capacityM3,
    emptyAtMs,
  }
})

const volumeCeiling = computed(() => {
  if (projection.value === null) return 1
  const ceiling = Math.max(projection.value.capacity, projection.value.usedVolume)
  return ceiling > 0 ? ceiling : 1
})

function xAt(timestampMs: number): number {
  const current = projection.value
  if (current === null) return PADDING_X
  return rounded(PADDING_X + (timestampMs - current.startMs) / (current.endMs - current.startMs) * plotWidth)
}

function yAt(volume: number): number {
  return rounded(baseline - volume / volumeCeiling.value * plotHeight)
}

const lineEnd = computed(() => {
  const current = projection.value
  if (current === null) return null
  if (current.emptyAtMs === null) return { x: xAt(current.endMs), y: yAt(current.usedVolume) }
  return { x: xAt(current.emptyAtMs), y: yAt(0) }
})

const linePoints = computed(() => {
  const current = projection.value
  const end = lineEnd.value
  if (current === null || end === null) return ''
  return `${xAt(current.startMs)},${yAt(current.usedVolume)} ${end.x},${end.y}`
})

const nowX = computed(() => {
  const current = projection.value
  if (current === null) return null
  const nowMs = Date.now()
  return nowMs < current.startMs || nowMs > current.endMs ? null : xAt(nowMs)
})

const emptyAtIso = computed(() => {
  const emptyAtMs = projection.value?.emptyAtMs
  return emptyAtMs == null ? null : new Date(emptyAtMs).toISOString()
})

const ageLabel = computed(() => {
  const age = props.launchpad.dataAgeSeconds
  return age === undefined ? null : formatDuration(age)
})
</script>

<template>
  <div data-testid="launchpad-curve" class="mt-2 rounded-sm border border-slate-700/60 bg-slate-900/60 px-2 py-1.5">
    <div class="mb-1 flex items-center justify-between gap-2 text-[11px] uppercase tracking-wider text-slate-500">
      <span class="truncate">{{ t('pi.curve.launchpad.title', { pinId: launchpad.pinId }) }}</span>
      <span
        v-if="ageLabel !== null"
        data-testid="launchpad-curve-age"
        :class="['whitespace-nowrap font-mono-tech normal-case', launchpad.dataStale === true ? 'text-amber-400' : 'text-slate-500']"
      >
        {{ t('pi.curve.launchpad.age', { age: ageLabel }) }}
      </span>
    </div>

    <p v-if="projection === null" data-testid="launchpad-curve-unknown" class="text-xs italic text-slate-500">
      {{ t('pi.curve.launchpad.unknown') }}
    </p>

    <template v-else>
      <svg
        :viewBox="`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`"
        class="h-auto w-full"
        role="img"
        :aria-label="t('pi.curve.launchpad.ariaLabel')"
      >
        <line :x1="PADDING_X" :y1="baseline" :x2="VIEW_WIDTH - PADDING_X" :y2="baseline" class="text-slate-700" stroke="currentColor" stroke-width="1" />
        <line
          data-testid="launchpad-curve-capacity"
          :x1="PADDING_X"
          :y1="yAt(projection.capacity)"
          :x2="VIEW_WIDTH - PADDING_X"
          :y2="yAt(projection.capacity)"
          class="text-slate-500"
          stroke="currentColor"
          stroke-width="1"
          stroke-dasharray="2 3"
        />
        <line
          data-testid="launchpad-curve-last-view"
          :x1="xAt(projection.startMs)"
          :y1="PADDING_Y"
          :x2="xAt(projection.startMs)"
          :y2="baseline"
          class="text-slate-500"
          stroke="currentColor"
          stroke-width="1"
        />
        <polyline
          data-testid="launchpad-curve-line"
          :points="linePoints"
          class="text-cyan-400"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-dasharray="5 3"
        />
        <line
          v-if="nowX !== null"
          data-testid="launchpad-curve-now"
          :x1="nowX"
          :y1="PADDING_Y"
          :x2="nowX"
          :y2="baseline"
          class="text-emerald-400"
          stroke="currentColor"
          stroke-width="1.5"
        />
        <circle
          v-if="emptyAtIso !== null && lineEnd !== null"
          data-testid="launchpad-curve-empty-point"
          :data-empty-at="emptyAtIso"
          :cx="lineEnd.x"
          :cy="lineEnd.y"
          r="4"
          class="text-red-500"
          fill="currentColor"
        />
      </svg>

      <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-slate-400">
        <span class="inline-flex items-center gap-1 text-slate-500">
          <span class="inline-block h-2.5 w-px bg-slate-500"></span>
          {{ t('pi.curve.launchpad.lastView') }}
        </span>
        <span v-if="nowX !== null" class="inline-flex items-center gap-1 text-emerald-400">
          <span class="inline-block h-2.5 w-0.5 bg-emerald-400"></span>
          {{ t('pi.curve.now') }}
        </span>
        <span class="inline-flex items-center gap-1 text-slate-500">
          <span class="inline-block h-0 w-3 border-t border-dotted border-slate-500"></span>
          {{ t('pi.curve.launchpad.capacity', { capacity: formatNumber(projection.capacity, 0) }) }}
        </span>
      </div>

      <p class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 font-mono-tech text-[11px] text-slate-300">
        <span>{{ t('pi.curve.launchpad.used', { volume: formatNumber(projection.usedVolume, 0) }) }}</span>
        <span v-if="emptyAtIso !== null" class="text-red-400">{{ t('pi.curve.launchpad.emptyAt', { date: formatDateTime(emptyAtIso) }) }}</span>
        <span v-else data-testid="launchpad-curve-flat" class="text-slate-400">{{ t('pi.curve.launchpad.notConsumed') }}</span>
      </p>
    </template>
  </div>
</template>

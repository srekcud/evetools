<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningSiteScan } from '@/stores/miningSite'
import { curveSeriesOf, CURVE_AXIS_MAX_MINUTES, type CurveSeries, type CurveUnit } from '@/views/MiningSite/miningSiteEstimate'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'

const props = defineProps<{ scans: MiningSiteScan[]; typeNames: Record<number, string> }>()

const WIDTH = 740
const HEIGHT = 260
const MARGIN = { left: 56, right: 14, top: 14, bottom: 28 }
const AXIS_STEP_MINUTES = 15
const MIN_AXIS_MINUTES = 30
const MILLISECONDS_PER_MINUTE = 60_000
const Y_HEADROOM = 1.08
const Y_TICK_TARGET = 4
const NOT_MINED_COLOR = '#64748b'
const TYPE_COLORS = ['#22d3ee', '#a78bfa', '#34d399', '#fb7185', '#38bdf8', '#a3e635', '#e879f9', '#fb923c'] as const

const { t, locale } = useI18n()

const requestedUnit = ref<CurveUnit>('units')

const unitsSeries = computed(() => curveSeriesOf(props.scans, 'units'))
const m3Available = computed(() => unitsSeries.value.m3Available)
const series = computed<CurveSeries>(() => curveSeriesOf(props.scans, requestedUnit.value))
const shownUnit = computed(() => series.value.unit)

const firstScanAt = computed(() => [...props.scans].sort((first, second) => first.number - second.number)[0]?.scannedAt ?? null)

function niceStep(rawStep: number): number {
  const magnitude = 10 ** Math.floor(Math.log10(rawStep))
  const fraction = rawStep / magnitude
  const niceFraction = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10
  return niceFraction * magnitude
}

const maxMinutes = computed(() => {
  const reached = series.value.types.flatMap(type => [
    ...type.points.map(point => point.minutes),
    ...(type.trendLine ?? []).map(point => point.minutes),
    ...(type.recentSegment ?? []).map(point => point.minutes),
    ...(type.depletionMarker === null ? [] : [type.depletionMarker.minutes]),
  ])
  const needed = Math.max(MIN_AXIS_MINUTES, ...reached)
  return Math.min(CURVE_AXIS_MAX_MINUTES, Math.ceil(needed / AXIS_STEP_MINUTES) * AXIS_STEP_MINUTES)
})

const maxValue = computed(() => {
  const reached = series.value.types.flatMap(type => [
    ...type.points.map(point => point.value),
    ...(type.trendLine ?? []).map(point => point.value),
  ])
  const highest = Math.max(1, ...reached) * Y_HEADROOM
  const step = niceStep(highest / Y_TICK_TARGET)
  return Math.ceil(highest / step) * step
})

const valueStep = computed(() => niceStep(maxValue.value / Y_TICK_TARGET))
const plotWidth = WIDTH - MARGIN.left - MARGIN.right
const plotHeight = HEIGHT - MARGIN.top - MARGIN.bottom

function x(minutes: number): number {
  return MARGIN.left + (minutes / maxMinutes.value) * plotWidth
}

function y(value: number): number {
  return MARGIN.top + plotHeight - (value / maxValue.value) * plotHeight
}

const valueTicks = computed(() => {
  const ticks: number[] = []
  for (let tick = 0; tick <= maxValue.value; tick += valueStep.value) ticks.push(tick)
  return ticks
})

const timeTicks = computed(() => {
  const ticks: { minutes: number; label: string }[] = []
  const start = firstScanAt.value
  if (start === null) return ticks
  const step = maxMinutes.value <= 120 ? AXIS_STEP_MINUTES * 2 : AXIS_STEP_MINUTES * 4
  for (let minutes = 0; minutes <= maxMinutes.value; minutes += step) {
    ticks.push({ minutes, label: formatEveClock(new Date(new Date(start).getTime() + minutes * MILLISECONDS_PER_MINUTE).toISOString()) })
  }
  return ticks
})

const compactNumber = computed(() => new Intl.NumberFormat(locale.value, { notation: 'compact', maximumFractionDigits: 1 }))
const fullNumber = computed(() => new Intl.NumberFormat(locale.value, { maximumFractionDigits: 0 }))

function colorOf(index: number, notDepleting: boolean): string {
  return notDepleting ? NOT_MINED_COLOR : TYPE_COLORS[index % TYPE_COLORS.length] ?? NOT_MINED_COLOR
}

function nameOf(typeId: number): string {
  return props.typeNames[typeId] ?? t('common.unknownTypeName', { typeId })
}

const unitLabel = computed(() => (shownUnit.value === 'm3' ? 'm³' : t('miningSite.curve.unitsShort')))

const description = computed(() => t('miningSite.curve.alt', {
  count: props.scans.length,
  unit: unitLabel.value,
  types: series.value.types
    .map((type) => {
      const last = type.points[type.points.length - 1]
      return last === undefined ? nameOf(type.typeId) : `${nameOf(type.typeId)} ${fullNumber.value.format(last.value)}`
    })
    .join(', '),
}))
</script>

<template>
  <div class="eve-card p-5">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
      <h2 class="font-display text-lg font-semibold text-slate-100">{{ t('miningSite.curve.title') }}</h2>
      <div class="flex gap-1" role="group" :aria-label="t('miningSite.curve.unit')">
        <button
          type="button"
          data-testid="curve-unit-units"
          :aria-pressed="shownUnit === 'units'"
          class="rounded-sm border px-3 py-1 font-mono text-xs transition-colors"
          :class="shownUnit === 'units' ? 'border-cyan-400 bg-cyan-500/10 text-cyan-200' : 'border-slate-700 text-slate-400 hover:text-slate-200'"
          @click="requestedUnit = 'units'"
        >{{ t('miningSite.curve.units') }}</button>
        <button
          type="button"
          data-testid="curve-unit-m3"
          :disabled="!m3Available"
          :title="m3Available ? undefined : t('miningSite.curve.m3Unavailable')"
          :aria-pressed="shownUnit === 'm3'"
          class="rounded-sm border px-3 py-1 font-mono text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-50"
          :class="shownUnit === 'm3' ? 'border-cyan-400 bg-cyan-500/10 text-cyan-200' : 'border-slate-700 text-slate-400 hover:text-slate-200'"
          @click="requestedUnit = 'm3'"
        >m³</button>
      </div>
    </div>

    <div class="overflow-x-auto">
      <svg :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" class="block h-auto w-full min-w-[520px]" role="img" :aria-label="description">
        <g aria-hidden="true">
          <template v-for="tick in valueTicks" :key="`value-${tick}`">
            <line :x1="MARGIN.left" :x2="WIDTH - MARGIN.right" :y1="y(tick)" :y2="y(tick)" stroke="#334155" stroke-width="1" :stroke-dasharray="tick === 0 ? undefined : '2 4'" />
            <text :x="MARGIN.left - 6" :y="y(tick) + 4" text-anchor="end" font-size="11" fill="#94a3b8" font-family="monospace">{{ compactNumber.format(tick) }}</text>
          </template>
          <template v-for="tick in timeTicks" :key="`time-${tick.minutes}`">
            <line :x1="x(tick.minutes)" :x2="x(tick.minutes)" :y1="MARGIN.top + plotHeight" :y2="MARGIN.top + plotHeight + 4" stroke="#475569" />
            <text :x="x(tick.minutes)" :y="HEIGHT - 8" text-anchor="middle" font-size="11" fill="#94a3b8" font-family="monospace">{{ tick.label }}</text>
          </template>
          <template v-for="(type, index) in series.types" :key="type.typeId">
            <line
              v-if="type.trendLine !== null"
              :x1="x(type.trendLine[0].minutes)"
              :y1="y(type.trendLine[0].value)"
              :x2="x(type.trendLine[1].minutes)"
              :y2="y(type.trendLine[1].value)"
              :stroke="colorOf(index, type.notDepleting)"
              stroke-width="2"
              opacity="0.85"
            />
            <line
              v-if="type.recentSegment !== null"
              :x1="x(type.recentSegment[0].minutes)"
              :y1="y(type.recentSegment[0].value)"
              :x2="x(type.recentSegment[1].minutes)"
              :y2="y(type.recentSegment[1].value)"
              :stroke="colorOf(index, type.notDepleting)"
              stroke-width="1.6"
              stroke-dasharray="5 4"
            />
            <circle
              v-if="type.depletionMarker !== null"
              :cx="x(type.depletionMarker.minutes)"
              :cy="y(0)"
              r="4"
              fill="none"
              :stroke="colorOf(index, type.notDepleting)"
              stroke-width="1.5"
            />
            <circle
              v-for="point in type.points"
              :key="point.minutes"
              :cx="x(point.minutes)"
              :cy="y(point.value)"
              r="3.5"
              :fill="colorOf(index, type.notDepleting)"
              stroke="#0f172a"
              stroke-width="1"
            />
          </template>
        </g>
      </svg>
    </div>

    <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-300" data-testid="curve-legend">
      <li v-for="(type, index) in series.types" :key="type.typeId" class="flex min-w-0 items-center gap-1.5">
        <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: colorOf(index, type.notDepleting) }" aria-hidden="true"></span>
        <span class="truncate" :title="nameOf(type.typeId)">{{ nameOf(type.typeId) }}</span>
        <span v-if="type.notDepleting" class="text-slate-400">({{ t('miningSite.badge.notMined') }})</span>
      </li>
      <li class="text-slate-400">{{ t('miningSite.curve.legendTrend') }}</li>
      <li class="text-slate-400">{{ t('miningSite.curve.legendRecent') }}</li>
      <li class="text-slate-400">{{ t('miningSite.curve.legendDepleted') }}</li>
    </ul>
  </div>
</template>

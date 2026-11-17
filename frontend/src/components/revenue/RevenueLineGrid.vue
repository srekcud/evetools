<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { RevenueContributorUnit, RevenueGridColumn, RevenueGridPeriod, RevenueSeries } from '@/types/revenueGrid'
import {
  REVENUE_TONE_COLORS,
  STRIP_DAILY_MAX_DAYS,
  UNKNOWN_HATCH,
  cellTintAlpha,
  cellTintRatio,
  hexWithAlpha,
  isCountedInNet,
  netIncludesEstimate,
  sumNet,
} from '@/utils/revenueGrid'
import RevenueChange from './RevenueChange.vue'
import RevenueContributors from './RevenueContributors.vue'
import RevenueRegularityStrip from './RevenueRegularityStrip.vue'

const props = withDefaults(
  defineProps<{
    title: string
    rowLabel: string
    rowSub?: string
    netLabel?: string
    columns: RevenueGridColumn[]
    net: RevenueSeries
    period: RevenueGridPeriod
    contributorUnit?: RevenueContributorUnit
  }>(),
  { rowSub: undefined, netLabel: undefined, contributorUnit: 'characters' },
)

const { t } = useI18n()
const { formatIsk, formatIskFull } = useFormatters()

const DASHED_OUTLINE_ALPHA = 0.7
const ESTIMATE_TINT_ALPHA_FLOOR = 0.08

const netValue = computed(() => sumNet(props.columns))
const netIsEstimate = computed(() => netIncludesEstimate(props.columns))
const hasEstimate = computed(() => props.columns.some(column => column.kind === 'estimate'))
const hasExcluded = computed(() => props.columns.some(column => !isCountedInNet(column)))
const hasBadge = computed(() => props.columns.some(column => column.headerBadge !== undefined || column.cellBadge !== undefined))
const contributorsLabel = computed(() => t(`revenue.grid.contributors.${props.contributorUnit}`))
const previousLabel = computed(() => t('revenue.grid.previousPeriod', { n: props.period.days }))
const regularityUnitLabel = computed(() => t(props.period.days > STRIP_DAILY_MAX_DAYS ? 'revenue.grid.perWeek' : 'revenue.grid.perDay'))

function stateOf(column: RevenueGridColumn): 'unknown' | 'estimate' | 'cost' | 'revenue' {
  if (!isCountedInNet(column)) return 'unknown'
  if (column.kind === 'estimate') return 'estimate'
  return (column.value as number) < 0 ? 'cost' : 'revenue'
}

function colorOf(column: RevenueGridColumn): string {
  if (column.kind === 'unknown' || column.value === null) return REVENUE_TONE_COLORS.neutral
  if (column.value < 0 && column.kind === 'actual') return REVENUE_TONE_COLORS.loss
  return REVENUE_TONE_COLORS[column.tone]
}

function cellStyle(column: RevenueGridColumn): Record<string, string> {
  const state = stateOf(column)
  if (state === 'unknown') return { background: UNKNOWN_HATCH }
  const color = colorOf(column)
  const alpha = cellTintAlpha(cellTintRatio(column.value as number, props.columns))
  if (state === 'estimate') {
    return {
      background: hexWithAlpha(color, Math.max(alpha, ESTIMATE_TINT_ALPHA_FLOOR)),
      outline: `1px dashed ${hexWithAlpha(color, DASHED_OUTLINE_ALPHA)}`,
      outlineOffset: '-1px',
    }
  }
  return { background: hexWithAlpha(color, alpha) }
}

function cellTextClass(column: RevenueGridColumn): string {
  const state = stateOf(column)
  if (state === 'unknown') return 'text-slate-400'
  if (state === 'estimate') return 'text-violet-100'
  if (state === 'cost') return 'text-red-300'
  return 'text-slate-50'
}

function cellTitle(column: RevenueGridColumn): string {
  if (column.value === null) return `${column.label} : ${t('revenue.grid.unknownCell')}`
  const amount = `${column.label} : ${formatIskFull(column.value)}`
  if (column.kind === 'unknown') return `${amount} (${t('revenue.grid.excludedFromNet')})`
  if (column.kind === 'estimate') return `${amount} (${t('revenue.grid.estimate')})`
  return amount
}

function headerBarStyle(column: RevenueGridColumn): Record<string, string> {
  if (column.kind === 'unknown') return { background: UNKNOWN_HATCH }
  if (column.kind === 'estimate') return { borderTop: `2px dashed ${REVENUE_TONE_COLORS[column.tone]}` }
  return { background: colorOf(column) }
}

function stripColorOf(column: RevenueGridColumn): string {
  return column.kind === 'estimate' ? REVENUE_TONE_COLORS.planetary : colorOf(column)
}

const isCostColumn = (column: RevenueGridColumn) => column.value !== null && column.value < 0
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-4 md:p-5" data-testid="revenue-line-grid">
    <h3 class="mb-3 font-mono text-xs uppercase tracking-[0.14em] text-slate-500">{{ title }}</h3>
    <div class="overflow-x-auto">
      <table class="whitespace-nowrap border-separate border-spacing-0">
        <thead>
          <tr>
            <th></th>
            <th v-for="column in columns" :key="column.id" class="min-w-[9.5rem] px-1 pb-2 align-bottom font-normal" :data-column="column.id">
              <component
                :is="column.to ? RouterLink : 'div'"
                v-bind="column.to ? { to: column.to } : {}"
                class="relative flex flex-col gap-1"
                :class="column.to ? 'hover:text-slate-100' : ''"
              >
                <span
                  v-if="column.headerBadge !== undefined"
                  class="absolute -right-1.5 -top-1.5 z-10 grid h-5 w-5 place-items-center rounded-full bg-amber-500 font-mono text-xs leading-none text-slate-900"
                  :aria-label="t('revenue.grid.todoBadge', { n: column.headerBadge })"
                  data-testid="header-badge"
                >{{ column.headerBadge }}</span>
                <span class="font-mono text-xs uppercase tracking-[0.14em] text-slate-300">{{ column.label }}</span>
                <span class="block h-[3px] w-full" :style="headerBarStyle(column)"></span>
              </component>
            </th>
            <th class="min-w-[10rem] border-l border-slate-700/70 pb-2 pl-4 text-left align-bottom font-normal">
              <span class="font-mono text-xs uppercase tracking-[0.14em] text-slate-300">{{ netLabel ?? t('revenue.grid.net') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <th class="w-36 pr-4 pt-2 text-left align-top">
              <span class="font-mono text-xs uppercase tracking-[0.14em] text-slate-500">{{ rowLabel }}</span>
              <div v-if="rowSub" class="text-xs font-normal text-slate-500">{{ rowSub }}</div>
            </th>
            <td v-for="column in columns" :key="column.id" class="p-1" :data-cell="column.id">
              <div
                class="relative flex h-14 items-center justify-end px-3 font-mono text-lg"
                :class="cellTextClass(column)"
                :style="cellStyle(column)"
                :title="cellTitle(column)"
                :data-state="stateOf(column)"
              >
                <span
                  v-if="column.cellBadge !== undefined"
                  class="absolute -right-1.5 -top-1.5 z-10 grid h-5 w-5 place-items-center rounded-full bg-amber-500 font-mono text-xs leading-none text-slate-900"
                  :aria-label="t('revenue.grid.todoBadge', { n: column.cellBadge })"
                  data-testid="cell-badge"
                >{{ column.cellBadge }}</span>
                <span v-if="column.value === null" :title="t('common.unknownValue')">—</span>
                <template v-else>{{ column.kind === 'estimate' ? '~' : '' }}{{ formatIsk(column.value) }}</template>
              </div>
            </td>
            <td class="border-l border-slate-700/70 pl-4">
              <div class="flex h-14 items-center font-mono text-2xl font-semibold" :class="netValue !== null && netValue < 0 ? 'text-red-400' : 'text-slate-50'" data-testid="net-value">
                <span v-if="netValue === null" :title="t('common.unknownValue')">—</span>
                <template v-else>{{ netIsEstimate ? '~' : '' }}{{ formatIsk(netValue) }}</template>
              </div>
            </td>
          </tr>
          <tr>
            <th class="pr-4 pt-1 text-left align-top font-mono text-xs font-normal uppercase tracking-[0.14em] text-slate-500">{{ previousLabel }}</th>
            <td v-for="column in columns" :key="column.id" class="px-2 pt-1 text-right font-mono text-sm" :data-change="column.id">
              <RevenueChange v-if="column.kind !== 'unknown'" :change="column.change" :estimate="column.kind === 'estimate'" :cost-like="isCostColumn(column)" />
            </td>
            <td class="border-l border-slate-700/70 pl-4 pt-1 font-mono text-sm" data-testid="net-change"><RevenueChange :change="net.change" :estimate="netIsEstimate" /></td>
          </tr>
          <tr>
            <th class="pr-4 pt-3 text-left align-top">
              <span class="font-mono text-xs uppercase tracking-[0.14em] text-slate-500">{{ t('revenue.grid.regularity') }}</span>
              <div class="text-xs font-normal text-slate-500">{{ regularityUnitLabel }}</div>
            </th>
            <td v-for="column in columns" :key="column.id" class="px-2 pt-3">
              <RevenueRegularityStrip v-if="column.kind !== 'unknown'" :daily="column.daily" :period="period" :color="stripColorOf(column)" />
            </td>
            <td class="border-l border-slate-700/70 pl-4 pt-3">
              <RevenueRegularityStrip :daily="net.daily" :period="period" :color="REVENUE_TONE_COLORS.neutral" />
            </td>
          </tr>
          <tr>
            <th class="pr-4 pt-3 text-left align-top font-mono text-xs font-normal uppercase tracking-[0.14em] text-slate-500">{{ contributorsLabel }}</th>
            <td v-for="column in columns" :key="column.id" class="px-2 pt-3 align-top">
              <RevenueContributors :contributors="column.contributors" :unit="contributorUnit" :color="colorOf(column)" />
            </td>
            <td class="whitespace-normal border-l border-slate-700/70 pl-4 pt-3 align-top">
              <RevenueContributors :contributors="net.contributors" :unit="contributorUnit" :color="REVENUE_TONE_COLORS.neutral" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 font-mono text-xs text-slate-400" data-testid="revenue-legend">
      <span class="flex items-center gap-1.5">
        <span class="inline-block h-3 w-10" style="background: linear-gradient(90deg, rgba(34, 211, 238, 0.08), rgba(34, 211, 238, 0.6))"></span>{{ t('revenue.grid.legend.weight') }}
      </span>
      <span v-if="hasEstimate" class="flex items-center gap-1.5">
        <span class="inline-block h-3 w-5 border border-dashed border-violet-400 bg-violet-400/15"></span>{{ t('revenue.grid.legend.estimate') }}
      </span>
      <span v-if="hasExcluded" class="flex items-center gap-1.5">
        <span class="inline-block h-3 w-5" :style="{ background: UNKNOWN_HATCH }"></span>{{ t('revenue.grid.legend.unknown') }}
      </span>
      <span v-if="hasBadge" class="flex items-center gap-1.5">
        <span class="inline-grid h-5 w-5 place-items-center rounded-full bg-amber-500 text-xs text-slate-900">1</span>{{ t('revenue.grid.legend.todo') }}
      </span>
    </div>
  </section>
</template>

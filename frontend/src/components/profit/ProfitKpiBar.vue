<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ProfitSummary } from '@/stores/profitTracker'

defineProps<{
  summary: ProfitSummary
  days: number
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

function profitColor(value: number): string {
  return value >= 0 ? 'text-emerald-400' : 'text-red-400'
}

function marginBarWidth(margin: number): string {
  const clamped = Math.min(Math.max(margin, 0), 100)
  return `${clamped}%`
}
</script>

<template>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <!-- Total Profit -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-5" :class="summary.totalProfit >= 0 ? 'shadow-emerald-500/5 shadow-lg' : 'shadow-red-500/5 shadow-lg'">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">{{ t('profitTracker.kpi.totalProfit') }}</span>
        <svg class="w-5 h-5" :class="summary.totalProfit >= 0 ? 'text-emerald-400/60' : 'text-red-400/60'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
        </svg>
      </div>
      <div class="font-mono text-2xl font-semibold" :class="profitColor(summary.totalProfit)">
        {{ summary.totalProfit >= 0 ? '+' : '' }}{{ formatIsk(summary.totalProfit) }}
      </div>
      <div class="text-xs text-slate-500 mt-1">ISK</div>
    </div>

    <!-- Average Margin -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">{{ t('profitTracker.kpi.avgMargin') }}</span>
        <svg class="w-5 h-5 text-cyan-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
      </div>
      <div class="font-mono text-2xl font-semibold" :class="profitColor(summary.avgMargin)">
        {{ summary.avgMargin.toFixed(1) }}<span class="text-lg text-slate-500">%</span>
      </div>
      <div class="w-full bg-slate-800 rounded-full h-1.5 mt-3">
        <div
          class="h-1.5 rounded-full transition-all duration-500"
          :class="summary.avgMargin >= 0 ? 'bg-gradient-to-r from-emerald-500 to-cyan-400' : 'bg-red-500'"
          :style="{ width: marginBarWidth(summary.avgMargin) }"
        ></div>
      </div>
    </div>

    <!-- Items Tracked -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">{{ t('profitTracker.kpi.itemCount') }}</span>
        <svg class="w-5 h-5 text-slate-500/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
        </svg>
      </div>
      <div class="font-mono text-2xl font-semibold text-slate-100">
        {{ summary.itemCount }}<span class="text-lg text-slate-500"> {{ summary.itemCount === 1 ? 'type' : 'types' }}</span>
      </div>
      <div class="text-xs text-slate-500 mt-1">{{ t('profitTracker.period.' + days + 'd') }}</div>
    </div>

    <!-- Best Performer -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-medium text-slate-500 uppercase tracking-wider">{{ t('profitTracker.kpi.bestItem') }}</span>
        <svg class="w-5 h-5 text-amber-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
        </svg>
      </div>
      <template v-if="summary.bestItem">
        <div class="text-lg font-semibold text-slate-100 truncate">{{ summary.bestItem.typeName }}</div>
        <div class="text-xs text-slate-500 mt-1 font-mono" :class="profitColor(summary.bestItem.profit)">
          {{ summary.bestItem.profit >= 0 ? '+' : '' }}{{ formatIsk(summary.bestItem.profit) }} ISK
        </div>
      </template>
      <template v-else>
        <div class="text-sm text-slate-500">---</div>
      </template>
    </div>
  </div>
</template>

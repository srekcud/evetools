<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

export type SellRow = {
  key: string
  venue: string
  tag: string
  unitPrice: number
  revenue: number
  fees: number
  profit: number
  margin: number
  dailyVolume: number | null
  contractCount: number | null
  isBest: boolean
}

defineProps<{
  rows: SellRow[]
  runs: number
  brokerFeeRate: number
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
</script>

<template>
  <div class="eve-card overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.sellComparison') }}</h4>
      </div>
      <span class="text-xs text-slate-600">{{ runs }} runs &middot; {{ t('industry.margins.brokerFeeLabel') }} {{ (brokerFeeRate * 100).toFixed(1) }}%</span>
    </div>

    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
          <th class="text-left py-2.5 px-4">{{ t('industry.margins.venue') }}</th>
          <th class="text-right py-2.5 px-3">{{ t('industry.margins.unitPrice') }}</th>
          <th class="text-right py-2.5 px-3">{{ t('industry.margins.revenue') }}</th>
          <th class="text-right py-2.5 px-3">{{ t('industry.margins.fees') }}</th>
          <th class="text-right py-2.5 px-3">{{ t('industry.margins.profit') }}</th>
          <th class="text-right py-2.5 px-3">{{ t('industry.margins.margin') }}</th>
          <th class="text-right py-2.5 px-4">{{ t('industry.margins.dailyVolume') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800">
        <tr
          v-for="row in rows"
          :key="row.key"
          :class="[
            row.isBest ? 'bg-emerald-500/5 hover:bg-emerald-500/10 border-l-2 border-l-emerald-500' : 'hover:bg-slate-800/50',
          ]"
        >
          <td class="py-2.5 px-4">
            <div class="flex items-center gap-2">
              <span :class="row.isBest ? 'text-slate-100 font-semibold' : 'text-slate-200'">{{ row.venue }}</span>
              <span class="text-[10px] px-1.5 py-0.5 bg-slate-700/50 text-slate-400 rounded-sm">{{ row.tag }}</span>
              <span
                v-if="row.isBest"
                class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-sm font-semibold"
              >{{ t('industry.margins.best') }}</span>
            </div>
          </td>
          <td class="py-2.5 px-3 text-right font-mono" :class="row.isBest ? 'text-slate-100 font-semibold' : 'text-slate-300'">{{ formatIsk(row.unitPrice) }}</td>
          <td class="py-2.5 px-3 text-right font-mono" :class="row.isBest ? 'text-slate-100' : 'text-slate-300'">{{ formatIsk(row.revenue) }}</td>
          <td class="py-2.5 px-3 text-right font-mono text-amber-400" :class="!row.isBest ? 'text-amber-400/70' : ''">-{{ formatIsk(Math.abs(row.fees)) }}</td>
          <td class="py-2.5 px-3 text-right font-mono" :class="[row.profit >= 0 ? 'text-emerald-400' : 'text-red-400', row.isBest ? 'font-bold' : '']">{{ row.profit >= 0 ? '+' : '' }}{{ formatIsk(row.profit) }}</td>
          <td class="py-2.5 px-3 text-right font-mono" :class="[row.margin >= 0 ? 'text-emerald-400' : 'text-red-400', row.isBest ? 'font-bold' : '']">{{ row.margin >= 0 ? '+' : '' }}{{ row.margin.toFixed(1) }}%</td>
          <td class="py-2.5 px-4 text-right font-mono" :class="row.dailyVolume != null || row.contractCount != null ? 'text-slate-300' : 'text-slate-500'">
            <template v-if="row.contractCount != null">{{ t('industry.margins.contractCount', { count: row.contractCount }) }}</template>
            <template v-else-if="row.dailyVolume != null">{{ Math.round(row.dailyVolume) }}/day</template>
            <template v-else>&#x2014;</template>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Info note -->
    <div class="px-4 py-3 bg-cyan-900/10 border-t border-slate-800 flex items-center gap-2">
      <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-xs text-cyan-300/70">{{ t('industry.margins.sellComparisonNote') }}</p>
    </div>
  </div>
</template>

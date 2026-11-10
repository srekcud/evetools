<script setup lang="ts">
import DataTable from '@/components/common/DataTable.vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import PartialPriceMarker from '@/components/industry/PartialPriceMarker.vue'
import PriceSourceBadge from '@/components/industry/PriceSourceBadge.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'
import type { PriceSource } from '@/stores/industry/types'

export type SellRow = {
  key: string
  venue: string
  tag: string
  unitPrice: number
  revenue: number
  fees: number | null
  profit: number | null
  margin: number | null
  dailyVolume: number | null
  contractCount: number | null
  isBest: boolean
  coveragePercent?: number | null
  source?: PriceSource | null
}

defineProps<{
  rows: SellRow[]
  runs: number
  brokerFeeRate: number | null
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
      <span v-if="brokerFeeRate === null" class="text-xs text-slate-500">{{ runs }} runs &middot; <span class="text-amber-400">{{ t('industry.fees.unknown') }}</span></span>
      <span v-else class="text-xs text-slate-500">{{ runs }} runs &middot; {{ t('industry.margins.brokerFeeLabel') }} {{ (brokerFeeRate * 100).toFixed(1) }}%</span>
    </div>

    <DataTable>
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
                <span class="text-xs px-1.5 py-0.5 bg-slate-700/50 text-slate-400 rounded-sm">{{ row.tag }}</span>
                <span
                  v-if="row.isBest"
                  class="text-xs uppercase tracking-wider px-1.5 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-sm font-semibold"
                >{{ t('industry.margins.best') }}</span>
              </div>
            </td>
            <td class="py-2.5 px-3 text-right font-mono tabular-nums max-w-[10rem]" :class="row.isBest ? 'text-slate-100 font-semibold' : 'text-slate-300'">
              {{ formatIsk(row.unitPrice) }}
              <div><PriceSourceBadge :source="row.source" :show-coverage="false" /></div>
            </td>
            <td class="py-2.5 px-3 text-right font-mono tabular-nums" :class="row.isBest ? 'text-slate-100' : 'text-slate-300'">{{ formatIsk(row.revenue) }}<PartialPriceMarker :percent="row.coveragePercent" tooltip-key="industry.partialPrice.sellTooltip" /></td>
            <td v-if="row.fees === null" class="py-2.5 px-3 text-right font-mono text-slate-500 tabular-nums"><UnknownValue /></td>
            <td v-else class="py-2.5 px-3 text-right font-mono text-amber-400 tabular-nums" :class="!row.isBest ? 'text-amber-400/70' : ''">-{{ formatIsk(Math.abs(row.fees)) }}</td>
            <td v-if="row.profit === null" class="py-2.5 px-3 text-right font-mono text-slate-500 tabular-nums"><UnknownValue /></td>
            <td v-else class="py-2.5 px-3 text-right font-mono tabular-nums" :class="[row.profit >= 0 ? 'text-emerald-400' : 'text-red-400', row.isBest ? 'font-bold' : '']">{{ row.profit >= 0 ? '+' : '' }}{{ formatIsk(row.profit) }}</td>
            <td v-if="row.margin == null" class="py-2.5 px-3 text-right font-mono text-slate-500 tabular-nums">{{ t('industry.inventionUnknown.label') }}</td>
            <td v-else class="py-2.5 px-3 text-right font-mono tabular-nums" :class="[row.margin >= 0 ? 'text-emerald-400' : 'text-red-400', row.isBest ? 'font-bold' : '']">{{ row.margin >= 0 ? '+' : '' }}{{ row.margin.toFixed(1) }}%</td>
            <td class="py-2.5 px-4 text-right font-mono tabular-nums" :class="row.dailyVolume != null || row.contractCount != null ? 'text-slate-300' : 'text-slate-500'">
              <template v-if="row.contractCount != null">{{ t('industry.margins.contractCount', { count: row.contractCount }) }}</template>
              <template v-else-if="row.dailyVolume != null">{{ Math.round(row.dailyVolume) }}/day</template>
              <template v-else>&#x2014;</template>
            </td>
          </tr>
        </tbody>
      </table>
    </DataTable>

    <div v-if="brokerFeeRate === null" class="px-4 py-3 bg-amber-500/10 border-t border-slate-800 flex items-center gap-2">
      <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
      <p class="text-xs text-amber-300/80">{{ t('industry.fees.unknownReason') }}</p>
    </div>

    <!-- Info note -->
    <div class="px-4 py-3 bg-cyan-900/10 border-t border-slate-800 flex items-center gap-2">
      <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-xs text-cyan-300/70">{{ t('industry.margins.sellComparisonNote') }}</p>
    </div>
  </div>
</template>

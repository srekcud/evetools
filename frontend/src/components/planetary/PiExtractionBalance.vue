<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import DataTable from '@/components/common/DataTable.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useTypeNames } from '@/composables/useTypeNames'
import type { ExtractionBalanceRow } from '@/stores/planetary'

type GaugeState = 'normal' | 'warning' | 'critical'

const FULL_RUN_RATE = 1
const CRITICAL_RUN_RATE = 0.5
const PERCENT = 100

defineProps<{
  balance: ExtractionBalanceRow[]
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { typeNameLabel } = useTypeNames()

const GAUGE_TEXT: Record<GaugeState, string> = {
  normal: 'text-emerald-400',
  warning: 'text-amber-400',
  critical: 'text-red-400',
}

const GAUGE_BAR: Record<GaugeState, string> = {
  normal: 'bg-emerald-400',
  warning: 'bg-amber-400',
  critical: 'bg-red-500',
}

const ADVICE_TEXT: Record<'deficit' | 'surplus' | 'balanced', string> = {
  deficit: 'text-amber-400',
  surplus: 'text-cyan-400',
  balanced: 'text-emerald-400',
}

function gaugeState(runRate: number): GaugeState {
  if (runRate >= FULL_RUN_RATE) return 'normal'
  if (runRate >= CRITICAL_RUN_RATE) return 'warning'
  return 'critical'
}

function percentOf(runRate: number): number {
  return Math.round(runRate * PERCENT)
}

function signedBalance(value: number): string {
  return `${value > 0 ? '+' : ''}${formatNumber(value, 0)}`
}
</script>

<template>
  <div v-if="balance.length > 0" data-testid="extraction-balance">
    <h4 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-3 flex items-center gap-2">
      <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l9-3 9 3M12 3v18m-6-3h12M6 6l-3 7a3 3 0 006 0L6 6zm12 0l-3 7a3 3 0 006 0l-3-7z" />
      </svg>
      {{ t('pi.balance.title') }}
    </h4>
    <div class="bg-slate-800/60 rounded-lg border border-slate-700/30 overflow-hidden">
      <DataTable>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-700/30">
              <th class="text-left px-3 py-2 font-medium">{{ t('pi.balance.type') }}</th>
              <th class="text-right px-3 py-2 font-medium">{{ t('pi.balance.extracted') }}</th>
              <th class="text-right px-3 py-2 font-medium">{{ t('pi.balance.demanded') }}</th>
              <th class="text-right px-3 py-2 font-medium">{{ t('pi.balance.balance') }}</th>
              <th class="text-left px-3 py-2 font-medium" :title="t('pi.balance.runRateTooltip')">{{ t('pi.balance.runRate') }}</th>
              <th class="text-right px-3 py-2 font-medium">{{ t('pi.balance.factories') }}</th>
              <th class="text-right px-3 py-2 font-medium">{{ t('pi.balance.heads') }}</th>
              <th class="text-left px-3 py-2 font-medium">{{ t('pi.balance.advice') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/20">
            <tr v-for="row in balance" :key="row.typeId" data-testid="extraction-balance-row" class="hover:bg-slate-700/20">
              <td class="px-3 py-2 text-white font-medium max-w-[200px]">
                <span class="block truncate" :title="typeNameLabel(row.typeName, row.typeId)">{{ typeNameLabel(row.typeName, row.typeId) }}</span>
              </td>
              <td data-testid="extraction-balance-extracted" class="px-3 py-2 text-right font-mono-tech text-cyan-400 whitespace-nowrap">
                <span
                  v-if="row.extractedPerDay === null"
                  data-testid="extraction-balance-unknown"
                  :title="t('pi.balance.unknownTooltip')"
                  class="text-slate-500 italic font-sans"
                >{{ t('pi.balance.unknown') }}</span>
                <template v-else>{{ formatNumber(row.extractedPerDay, 0) }}</template>
              </td>
              <td data-testid="extraction-balance-demanded" class="px-3 py-2 text-right font-mono-tech text-white whitespace-nowrap">{{ formatNumber(row.demandedPerDay, 0) }}</td>
              <td
                data-testid="extraction-balance-balance"
                :class="['px-3 py-2 text-right font-mono-tech whitespace-nowrap', row.balancePerDay !== null && row.balancePerDay < 0 ? 'text-red-400' : 'text-slate-300']"
              >
                <template v-if="row.balancePerDay !== null">{{ signedBalance(row.balancePerDay) }}</template>
                <span v-else class="text-slate-500">-</span>
              </td>
              <td class="px-3 py-2 min-w-[110px]">
                <div
                  v-if="row.runRate !== null"
                  data-testid="extraction-balance-gauge"
                  :data-state="gaugeState(row.runRate)"
                >
                  <div class="h-1.5 w-full rounded-xs bg-slate-700 overflow-hidden">
                    <div
                      :class="['h-full rounded-xs', GAUGE_BAR[gaugeState(row.runRate)]]"
                      :style="{ width: `${Math.min(percentOf(row.runRate), PERCENT)}%` }"
                    ></div>
                  </div>
                  <div :class="['mt-0.5 text-right text-xs font-mono-tech', GAUGE_TEXT[gaugeState(row.runRate)]]">{{ percentOf(row.runRate) }}%</div>
                </div>
                <span v-else class="text-slate-500">-</span>
              </td>
              <td data-testid="extraction-balance-factories" class="px-3 py-2 text-right font-mono-tech text-slate-300">{{ row.factoryCount }}</td>
              <td data-testid="extraction-balance-heads" class="px-3 py-2 text-right font-mono-tech text-slate-300">
                <template v-if="row.headCount !== null">{{ row.headCount }}</template>
                <span v-else class="text-slate-500">-</span>
              </td>
              <td class="px-3 py-2 text-table-cell">
                <div
                  v-if="row.advice !== null"
                  data-testid="extraction-balance-advice"
                  :data-kind="row.advice.kind"
                  :class="['flex flex-wrap items-baseline gap-x-1.5 font-medium', ADVICE_TEXT[row.advice.kind]]"
                >
                  <template v-if="row.advice.kind === 'deficit'">
                    <span v-if="row.advice.heads !== null" data-testid="extraction-balance-advice-heads">{{ t('pi.balance.adviceHeads', { count: row.advice.heads }, row.advice.heads) }}</span>
                    <span v-if="row.advice.heads !== null && row.advice.factories !== null" class="text-slate-500 font-normal">{{ t('pi.balance.adviceOr') }}</span>
                    <span v-if="row.advice.factories !== null" data-testid="extraction-balance-advice-factories">{{ t('pi.balance.adviceFactories', { count: row.advice.factories }, row.advice.factories) }}</span>
                  </template>
                  <span v-else-if="row.advice.kind === 'surplus' && row.advice.factories !== null" data-testid="extraction-balance-advice-factories">{{ t('pi.balance.adviceSurplus', { count: row.advice.factories }, row.advice.factories) }}</span>
                  <span v-else>{{ t('pi.balance.adviceBalanced') }}</span>
                </div>
                <span v-else class="text-slate-500">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </DataTable>
    </div>
  </div>
</template>

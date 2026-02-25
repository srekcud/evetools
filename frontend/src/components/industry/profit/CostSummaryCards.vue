<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

const props = defineProps<{
  totalCost: number
  costPerUnit: number
  bestSellRevenue: number
  bestUnitPrice: number
  bestProfit: number
  profitPerRun: number
  bestMargin: number
  bestVenueLabel: string
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
</script>

<template>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <!-- Total Cost -->
    <div class="eve-card p-4">
      <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.totalCost') }}</p>
      <p class="text-xl font-mono text-slate-100 font-semibold">{{ formatIsk(props.totalCost) }}</p>
      <p class="text-xs text-slate-500 font-mono mt-1">{{ t('industry.margins.perUnit') }}: <span class="text-slate-400">{{ formatIsk(props.costPerUnit) }} ISK</span></p>
    </div>
    <!-- Best Sell Price -->
    <div class="eve-card p-4">
      <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.bestSellPrice') }}</p>
      <p class="text-xl font-mono text-slate-100 font-semibold">{{ formatIsk(props.bestSellRevenue) }}</p>
      <p class="text-xs text-slate-500 font-mono mt-1">{{ t('industry.margins.perUnit') }}: <span class="text-slate-400">{{ formatIsk(props.bestUnitPrice) }} ISK</span></p>
    </div>
    <!-- Profit -->
    <div class="eve-card p-4">
      <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.profit') }}</p>
      <p
        class="text-xl font-mono font-bold"
        :class="props.bestProfit >= 0 ? 'text-emerald-400' : 'text-red-400'"
      >{{ props.bestProfit >= 0 ? '+' : '' }}{{ formatIsk(props.bestProfit) }}</p>
      <p
        class="text-xs font-mono mt-1"
        :class="props.bestProfit >= 0 ? 'text-emerald-400/70' : 'text-red-400/70'"
      >{{ t('industry.margins.perUnit') }}: <span :class="props.bestProfit >= 0 ? 'text-emerald-400' : 'text-red-400'">{{ props.bestProfit >= 0 ? '+' : '' }}{{ formatIsk(props.profitPerRun) }} ISK</span></p>
    </div>
    <!-- Margin % -->
    <div class="eve-card p-4 border-cyan-500/30">
      <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.margin') }}</p>
      <p
        class="text-2xl font-mono font-bold"
        :class="props.bestMargin >= 0 ? 'text-emerald-400' : 'text-red-400'"
      >{{ props.bestMargin >= 0 ? '+' : '' }}{{ props.bestMargin.toFixed(1) }}%</p>
      <p class="text-xs text-slate-500 mt-1">{{ props.bestVenueLabel }} ({{ t('industry.margins.best').toLowerCase() }})</p>
    </div>
  </div>
</template>

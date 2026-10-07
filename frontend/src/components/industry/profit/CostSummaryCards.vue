<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

const props = defineProps<{
  totalCost: number | null
  costPerUnit: number | null
  bestSellRevenue: number
  bestUnitPrice: number
  bestProfit: number | null
  profitPerRun: number | null
  bestMargin: number | null
  bestVenueLabel: string
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

// A null cost means the production cost is unknown (e.g. missing invention probability)
function formatCost(cost: number | null): string {
  if (cost == null) return t('industry.inventionUnknown.label')
  return formatIsk(cost)
}

function signedCost(cost: number | null): string {
  if (cost == null) return t('industry.inventionUnknown.label')
  return `${cost >= 0 ? '+' : ''}${formatIsk(cost)}`
}

function profitColor(value: number | null, muted: boolean = false): string {
  if (value == null) return 'text-slate-500'
  if (value >= 0) return muted ? 'text-emerald-400/70' : 'text-emerald-400'
  return muted ? 'text-red-400/70' : 'text-red-400'
}
</script>

<template>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <!-- Total Cost -->
    <div class="eve-card p-4">
      <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.totalCost') }}</p>
      <p class="text-xl font-mono text-slate-100 font-semibold">{{ formatCost(props.totalCost) }}</p>
      <p class="text-xs text-slate-500 font-mono mt-1">{{ t('industry.margins.perUnit') }}: <span class="text-slate-400">{{ formatCost(props.costPerUnit) }}<template v-if="props.costPerUnit != null"> ISK</template></span></p>
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
        :class="profitColor(props.bestProfit)"
      >{{ signedCost(props.bestProfit) }}</p>
      <p
        class="text-xs font-mono mt-1"
        :class="profitColor(props.bestProfit, true)"
      >{{ t('industry.margins.perUnit') }}: <span :class="profitColor(props.bestProfit)">{{ signedCost(props.profitPerRun) }}<template v-if="props.profitPerRun != null"> ISK</template></span></p>
    </div>
    <!-- Margin % -->
    <div class="eve-card p-4 border-cyan-500/30">
      <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.margin') }}</p>
      <p
        class="text-2xl font-mono font-bold"
        :class="profitColor(props.bestMargin)"
      >{{ props.bestMargin == null ? t('industry.inventionUnknown.label') : `${props.bestMargin >= 0 ? '+' : ''}${props.bestMargin.toFixed(1)}%` }}</p>
      <p class="text-xs text-slate-500 mt-1">{{ props.bestVenueLabel }} ({{ t('industry.margins.best').toLowerCase() }})</p>
    </div>
  </div>
</template>

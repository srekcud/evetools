<script setup lang="ts">
import { computed } from 'vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupProjectStore } from '@/stores/group-industry/project'

const distStore = useGroupDistributionStore()
const projectStore = useGroupProjectStore()
const { formatIsk } = useFormatters()

const dist = computed(() => distStore.distribution)

const profit = computed(() => {
  if (!dist.value) return 0
  return dist.value.netRevenue - dist.value.totalProjectCost
})

const isProfit = computed(() => profit.value >= 0)

const marginPercent = computed(() => dist.value?.marginPercent ?? 0)

const brokerFeeLabel = computed(() => {
  const pct = projectStore.currentProject?.brokerFeePercent
  return pct != null ? `Broker Fee (${pct}%)` : 'Broker Fee'
})

const salesTaxLabel = computed(() => {
  const pct = projectStore.currentProject?.salesTaxPercent
  return pct != null ? `Sales Tax (${pct}%)` : 'Sales Tax'
})

</script>

<template>
  <div v-if="dist" class="mb-8">
    <h2 class="text-lg font-semibold text-slate-100 mb-4">Financial Summary</h2>
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-6">
      <div class="grid grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Revenue breakdown -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-400">Total Revenue</span>
            <span class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.totalRevenue) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ brokerFeeLabel }}</span>
            <span class="font-mono text-sm text-red-400" style="font-variant-numeric: tabular-nums;">
              -{{ formatIsk(dist.brokerFee) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ salesTaxLabel }}</span>
            <span class="font-mono text-sm text-red-400" style="font-variant-numeric: tabular-nums;">
              -{{ formatIsk(dist.salesTax) }}
            </span>
          </div>
          <div class="border-t border-slate-700 pt-2 flex items-center justify-between">
            <span class="text-sm font-semibold text-slate-300">Net Revenue</span>
            <span class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.netRevenue) }}
            </span>
          </div>
        </div>

        <!-- Cost breakdown -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-400">Total Project Cost</span>
            <span class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.totalProjectCost) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">Materials</span>
            <span class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.members.reduce((s, m) => s + m.materialCosts, 0)) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">Job Install + Line Rental</span>
            <span class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.members.reduce((s, m) => s + m.jobInstallCosts + m.lineRentalCosts, 0)) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">BPC</span>
            <span class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.members.reduce((s, m) => s + m.bpcCosts, 0)) }}
            </span>
          </div>
        </div>

        <!-- Profit ring -->
        <div
          class="rounded-xl border p-5 flex flex-col items-center justify-center"
          :class="isProfit ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-red-500/5 border-red-500/20'"
        >
          <p
            class="text-xs uppercase tracking-wider mb-1"
            :class="isProfit ? 'text-emerald-400/60' : 'text-red-400/60'"
          >Profit</p>
          <p
            class="text-3xl font-mono font-bold"
            :class="isProfit ? 'text-emerald-400' : 'text-red-400'"
            style="font-variant-numeric: tabular-nums;"
          >{{ isProfit ? '+' : '' }}{{ formatIsk(profit) }}</p>
          <p
            class="text-sm font-mono mt-1"
            :class="isProfit ? 'text-emerald-400/80' : 'text-red-400/80'"
          >Margin: {{ isProfit ? '+' : '' }}{{ marginPercent.toFixed(1) }}%</p>
        </div>
      </div>
    </div>
  </div>
</template>

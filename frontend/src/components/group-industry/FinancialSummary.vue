<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import UnknownValue from '@/components/common/UnknownValue.vue'

const { t } = useI18n()
const distStore = useGroupDistributionStore()
const projectStore = useGroupProjectStore()
const { formatIsk } = useFormatters()

const dist = computed(() => distStore.distribution)

const feesUnknown = computed(() => dist.value?.brokerFee === null || dist.value?.salesTax === null)

const profit = computed((): number | null => {
  if (!dist.value || dist.value.netRevenue === null) return null
  return dist.value.netRevenue - dist.value.totalProjectCost
})

const isProfit = computed(() => profit.value !== null && profit.value >= 0)
const profitUnknown = computed(() => profit.value === null)

const brokerFeeLabel = computed(() => {
  const pct = projectStore.currentProject?.brokerFeePercent
  return pct != null ? t('groupIndustry.financialSummary.brokerFeeWithRate', { percent: pct }) : t('groupIndustry.distribution.brokerFee')
})

const salesTaxLabel = computed(() => {
  const pct = projectStore.currentProject?.salesTaxPercent
  return pct != null ? t('groupIndustry.financialSummary.salesTaxWithRate', { percent: pct }) : t('groupIndustry.distribution.salesTax')
})

</script>

<template>
  <div v-if="dist" class="mb-8">
    <h2 class="text-lg font-semibold text-slate-100 mb-4">{{ t('groupIndustry.financialSummary.title') }}</h2>
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-6">
      <div class="grid grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Revenue breakdown -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-400">{{ t('groupIndustry.distribution.totalRevenue') }}</span>
            <span class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.totalRevenue) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ brokerFeeLabel }}</span>
            <span class="font-mono text-sm text-red-400" style="font-variant-numeric: tabular-nums;">
              <UnknownValue v-if="dist.brokerFee === null" /><template v-else>-{{ formatIsk(dist.brokerFee) }}</template>
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ salesTaxLabel }}</span>
            <span class="font-mono text-sm text-red-400" style="font-variant-numeric: tabular-nums;">
              <UnknownValue v-if="dist.salesTax === null" /><template v-else>-{{ formatIsk(dist.salesTax) }}</template>
            </span>
          </div>
          <div class="border-t border-slate-700 pt-2 flex items-center justify-between">
            <span class="text-sm font-semibold text-slate-300">{{ t('groupIndustry.distribution.netRevenue') }}</span>
            <span class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              <UnknownValue v-if="dist.netRevenue === null" /><template v-else>{{ formatIsk(dist.netRevenue) }}</template>
            </span>
          </div>
          <p v-if="feesUnknown" class="text-xs text-amber-400">{{ t('groupIndustry.financialSummary.feesUnknown') }}</p>
        </div>

        <!-- Cost breakdown -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-400">{{ t('groupIndustry.distribution.totalCost') }}</span>
            <span class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.totalProjectCost) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ t('groupIndustry.financialSummary.materials') }}</span>
            <span class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.members.reduce((s, m) => s + m.materialCosts, 0)) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ t('groupIndustry.financialSummary.jobInstallAndLineRental') }}</span>
            <span class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.members.reduce((s, m) => s + m.jobInstallCosts + m.lineRentalCosts, 0)) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ t('groupIndustry.financialSummary.bpc') }}</span>
            <span class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.members.reduce((s, m) => s + m.bpcCosts, 0)) }}
            </span>
          </div>
        </div>

        <!-- Profit ring -->
        <div
          class="rounded-xl border p-5 flex flex-col items-center justify-center"
          :class="profitUnknown ? 'bg-slate-800/40 border-slate-700' : isProfit ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-red-500/5 border-red-500/20'"
        >
          <p
            class="text-xs uppercase tracking-wider mb-1"
            :class="profitUnknown ? 'text-slate-500' : isProfit ? 'text-emerald-400/60' : 'text-red-400/60'"
          >{{ t('groupIndustry.financialSummary.profit') }}</p>
          <p
            class="text-3xl font-mono font-bold"
            :class="profitUnknown ? 'text-slate-500' : isProfit ? 'text-emerald-400' : 'text-red-400'"
            style="font-variant-numeric: tabular-nums;"
          ><UnknownValue v-if="profit === null" /><template v-else>{{ isProfit ? '+' : '' }}{{ formatIsk(profit) }}</template></p>
          <p
            class="text-sm font-mono mt-1"
            :class="profitUnknown ? 'text-slate-500' : isProfit ? 'text-emerald-400/80' : 'text-red-400/80'"
          >{{ t('groupIndustry.financialSummary.marginLabel') }} <UnknownValue v-if="dist.marginPercent === null" /><template v-else>{{ dist.marginPercent >= 0 ? '+' : '' }}{{ dist.marginPercent.toFixed(1) }}%</template></p>
        </div>
      </div>
    </div>
  </div>
</template>

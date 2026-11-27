<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupSellForecastStore } from '@/stores/group-industry/sellForecast'
import { buildForecastProfit, hasProductsLeftToSell, type ForecastProfitColumn } from '@/components/group-industry/projectForecastProfit'
import UnknownValue from '@/components/common/UnknownValue.vue'
import UnclaimedStockNotice from '@/components/group-industry/UnclaimedStockNotice.vue'
import UnknownContributionValue from '@/components/group-industry/UnknownContributionValue.vue'
import AwaitingSalesValue from '@/components/group-industry/AwaitingSalesValue.vue'

const { t } = useI18n()
const distStore = useGroupDistributionStore()
const projectStore = useGroupProjectStore()
const forecastStore = useGroupSellForecastStore()
const { formatIsk } = useFormatters()

const dist = computed(() => distStore.distribution)

const isAwaitingSales = computed(() => dist.value?.awaitingSales === true)

const projectForecast = computed(() => {
  const forecast = forecastStore.forecast
  if (!dist.value || forecast?.projectId !== projectStore.currentProject?.id || !hasProductsLeftToSell(forecast)) return null
  return { sellStructureName: forecast.sellStructure?.name ?? null, profit: buildForecastProfit(dist.value, forecast) }
})

const forecastMarkets = computed(() => {
  if (projectForecast.value === null) return []
  const { sellStructureName, profit } = projectForecast.value
  const markets: { key: 'structure' | 'jita'; label: string; column: ForecastProfitColumn }[] = []
  if (profit.structure !== null && sellStructureName !== null) markets.push({ key: 'structure', label: sellStructureName, column: profit.structure })
  markets.push({ key: 'jita', label: t('groupIndustry.sellForecast.jita'), column: profit.jita })
  return markets
})

const forecastRows = [
  { field: 'sold', labelKey: 'groupIndustry.financialSummary.forecastSold', valueOf: (column: ForecastProfitColumn) => column.netRevenueSold, sign: '' },
  { field: 'left', labelKey: 'groupIndustry.financialSummary.forecastLeft', valueOf: (column: ForecastProfitColumn) => column.netRevenueLeft, sign: '' },
  { field: 'incurred', labelKey: 'groupIndustry.financialSummary.forecastIncurred', valueOf: (column: ForecastProfitColumn) => column.costIncurred, sign: '-' },
  { field: 'remaining', labelKey: 'groupIndustry.financialSummary.forecastRemaining', valueOf: (column: ForecastProfitColumn) => column.costLeft, sign: '-' },
] as const

function forecastProfitClass(profit: number | null): string {
  if (profit === null) return 'text-slate-500'
  return profit >= 0 ? 'text-emerald-400' : 'text-red-400'
}

const unmatchedSales = computed(() => dist.value?.unmatchedSales ?? [])

const isProvisional = computed(() => !isAwaitingSales.value && (dist.value?.unsoldProducts.length ?? 0) > 0)

const profitLabel = computed(() => t(isProvisional.value ? 'groupIndustry.financialSummary.provisionalProfit' : 'groupIndustry.financialSummary.profit'))

const hasContractSales = computed(() => dist.value?.contractRevenue !== undefined && dist.value.contractRevenue > 0)
const contractFeesUnknown = computed(() => hasContractSales.value && (dist.value?.contractFees === null || dist.value?.contractFees === undefined))
const feesUnknown = computed(() => dist.value?.brokerFee === null || dist.value?.salesTax === null || contractFeesUnknown.value)

const profit = computed((): number | null => {
  if (!dist.value || isAwaitingSales.value || dist.value.netRevenue === null || dist.value.totalProjectCost === null) return null
  return dist.value.netRevenue - dist.value.totalProjectCost
})

const isProfit = computed(() => profit.value !== null && profit.value >= 0)
const profitUnknown = computed(() => profit.value === null)

function sumOrUnknown(values: (number | null)[]): number | null {
  let sum = 0
  for (const value of values) {
    if (value === null) return null
    sum += value
  }
  return sum
}

const materialCosts = computed(() => dist.value ? sumOrUnknown(dist.value.members.map(m => m.materialCosts)) : null)
const jobInstallAndLineRentalCosts = computed(() => dist.value ? sumOrUnknown(dist.value.members.flatMap(m => [m.jobInstallCosts, m.lineRentalCosts])) : null)
const bpcCosts = computed(() => dist.value ? sumOrUnknown(dist.value.members.map(m => m.bpcCosts)) : null)
const courierCosts = computed(() => dist.value ? sumOrUnknown(dist.value.members.map(m => m.courierCosts)) : null)

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
              <template v-if="isAwaitingSales">{{ t('groupIndustry.distribution.noSale') }}</template>
              <template v-else>{{ formatIsk(dist.totalRevenue) }}</template>
            </span>
          </div>
          <div v-if="hasContractSales" data-testid="revenue-split" class="flex items-center justify-between text-xs text-slate-500">
            <span>{{ t('groupIndustry.financialSummary.marketRevenue') }} {{ formatIsk(dist.marketRevenue ?? null) }}</span>
            <span>{{ t('groupIndustry.financialSummary.contractRevenue') }} {{ formatIsk(dist.contractRevenue ?? null) }}</span>
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
          <div v-if="hasContractSales" data-testid="contract-fees-row" class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ t('groupIndustry.financialSummary.contractFees') }}</span>
            <span class="font-mono text-sm text-red-400" style="font-variant-numeric: tabular-nums;">
              <span v-if="contractFeesUnknown" data-testid="contract-fees-unknown"><UnknownValue /></span><template v-else>-{{ formatIsk(dist.contractFees ?? null) }}</template>
            </span>
          </div>
          <div class="border-t border-slate-700 pt-2 flex items-center justify-between">
            <span class="text-sm font-semibold text-slate-300">{{ t('groupIndustry.distribution.netRevenue') }}</span>
            <span class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              <AwaitingSalesValue v-if="isAwaitingSales" /><UnknownValue v-else-if="dist.netRevenue === null" /><template v-else>{{ formatIsk(dist.netRevenue) }}</template>
            </span>
          </div>
          <p v-if="feesUnknown" class="text-xs text-amber-400">{{ t('groupIndustry.financialSummary.feesUnknown') }}</p>
        </div>

        <!-- Cost breakdown -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-400">{{ t('groupIndustry.distribution.totalCost') }}</span>
            <UnknownContributionValue v-if="dist.totalProjectCost === null" />
            <span v-else class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(dist.totalProjectCost) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ t('groupIndustry.financialSummary.materials') }}</span>
            <UnknownContributionValue v-if="materialCosts === null" />
            <span v-else class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(materialCosts) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ t('groupIndustry.financialSummary.jobInstallAndLineRental') }}</span>
            <UnknownContributionValue v-if="jobInstallAndLineRentalCosts === null" />
            <span v-else class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(jobInstallAndLineRentalCosts) }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ t('groupIndustry.financialSummary.bpc') }}</span>
            <UnknownContributionValue v-if="bpcCosts === null" />
            <span v-else class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(bpcCosts) }}
            </span>
          </div>
          <div v-if="courierCosts === null || courierCosts > 0" class="flex items-center justify-between">
            <span class="text-sm text-slate-500">{{ t('groupIndustry.financialSummary.courier') }}</span>
            <UnknownContributionValue v-if="courierCosts === null" />
            <span v-else class="font-mono text-sm text-slate-400" style="font-variant-numeric: tabular-nums;">
              {{ formatIsk(courierCosts) }}
            </span>
          </div>
          <div v-if="dist.totalProjectCost === null && (dist.unclaimedStockLines ?? []).length > 0" class="text-xs text-violet-300">
            <UnclaimedStockNotice :lines="dist.unclaimedStockLines ?? []" />
          </div>
          <p v-else-if="dist.totalProjectCost === null" class="text-xs text-violet-300">{{ t('groupIndustry.financialSummary.unknownContributionValue') }}</p>
        </div>

        <!-- Forecast profit of the whole project -->
        <div v-if="projectForecast" data-testid="forecast-profit" class="rounded-xl border border-slate-700 bg-slate-800/40 p-5">
          <p class="text-xs uppercase tracking-wider mb-3 text-slate-400">{{ t('groupIndustry.financialSummary.forecastProfit') }}</p>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-xs uppercase tracking-wider text-slate-500">
                <th class="py-1"></th>
                <th v-for="market in forecastMarkets" :key="market.key" class="py-1 pl-3 text-right" :class="market.key === 'structure' ? 'text-cyan-400' : ''">{{ market.label }}</th>
              </tr>
            </thead>
            <tbody class="font-mono" style="font-variant-numeric: tabular-nums;">
              <tr v-for="row in forecastRows" :key="row.field">
                <td class="py-1 font-sans text-slate-500">{{ t(row.labelKey) }}</td>
                <td v-for="market in forecastMarkets" :key="market.key" :data-testid="`forecast-${market.key}-${row.field}`" class="py-1 pl-3 text-right whitespace-nowrap text-slate-300">
                  <UnknownValue v-if="row.valueOf(market.column) === null" /><template v-else>{{ row.sign }}{{ formatIsk(row.valueOf(market.column)) }}</template>
                </td>
              </tr>
              <tr class="border-t border-slate-700">
                <td class="py-2 font-sans font-semibold text-slate-300">{{ t('groupIndustry.financialSummary.forecastProfit') }}</td>
                <td v-for="market in forecastMarkets" :key="market.key" :data-testid="`forecast-${market.key}-profit`" class="py-2 pl-3 text-right whitespace-nowrap text-lg font-bold" :class="forecastProfitClass(market.column.profit)">
                  <UnknownValue v-if="market.column.profit === null" /><template v-else>{{ market.column.profit >= 0 ? '+' : '' }}{{ formatIsk(market.column.profit) }}</template>
                </td>
              </tr>
              <tr>
                <td class="py-1 font-sans text-slate-500">{{ t('groupIndustry.financialSummary.marginLabel') }}</td>
                <td v-for="market in forecastMarkets" :key="market.key" :data-testid="`forecast-${market.key}-margin`" class="py-1 pl-3 text-right whitespace-nowrap text-sm" :class="forecastProfitClass(market.column.profit)">
                  <UnknownValue v-if="market.column.marginPercent === null" /><template v-else>{{ market.column.marginPercent >= 0 ? '+' : '' }}{{ market.column.marginPercent.toFixed(1) }}%</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Profit ring -->
        <div
          v-else
          class="rounded-xl border p-5 flex flex-col items-center justify-center"
          :class="profitUnknown ? 'bg-slate-800/40 border-slate-700' : isProfit ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-red-500/5 border-red-500/20'"
        >
          <p
            class="text-xs uppercase tracking-wider mb-1"
            :class="profitUnknown ? 'text-slate-500' : isProfit ? 'text-emerald-400/60' : 'text-red-400/60'"
          >{{ profitLabel }}</p>
          <p
            class="text-3xl font-mono font-bold"
            :class="profitUnknown ? 'text-slate-500' : isProfit ? 'text-emerald-400' : 'text-red-400'"
            style="font-variant-numeric: tabular-nums;"
          ><AwaitingSalesValue v-if="isAwaitingSales" /><UnknownValue v-else-if="profit === null" /><template v-else>{{ isProfit ? '+' : '' }}{{ formatIsk(profit) }}</template></p>
          <p
            class="text-sm font-mono mt-1"
            :class="profitUnknown ? 'text-slate-500' : isProfit ? 'text-emerald-400/80' : 'text-red-400/80'"
          >{{ t('groupIndustry.financialSummary.marginLabel') }} <AwaitingSalesValue v-if="isAwaitingSales" /><UnknownValue v-else-if="dist.marginPercent === null" /><template v-else>{{ dist.marginPercent >= 0 ? '+' : '' }}{{ dist.marginPercent.toFixed(1) }}%</template></p>
        </div>
      </div>
      <div v-if="unmatchedSales.length > 0" data-testid="unmatched-sales" class="mt-6 border-t border-slate-800 pt-4 text-xs text-amber-400">
        <p class="font-semibold">{{ t('groupIndustry.financialSummary.unmatchedSalesTitle') }}</p>
        <p class="mt-1 text-slate-500">{{ t('groupIndustry.financialSummary.unmatchedSalesHint') }}</p>
        <ul class="mt-2 space-y-1">
          <li v-for="unmatchedSale in unmatchedSales" :key="unmatchedSale.typeId" class="flex items-center justify-between">
            <span>{{ unmatchedSale.typeName }} × {{ unmatchedSale.quantity }}</span>
            <span class="font-mono" style="font-variant-numeric: tabular-nums;">{{ formatIsk(unmatchedSale.revenue) }}</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

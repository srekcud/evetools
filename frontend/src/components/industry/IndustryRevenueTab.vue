<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore } from '@/stores/ledger'
import { useFormatters } from '@/composables/useFormatters'
import type { RevenueTodoItem } from '@/types/revenueGrid'
import { MULTI_PRODUCT_CONTRIBUTOR_ID, buildIndustryNetSeries, buildIndustryRevenueColumns, hasNoKnownProfit } from '@/utils/industryRevenueGrid'
import IskAmount from '@/components/common/IskAmount.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import RevenueLineGrid from '@/components/revenue/RevenueLineGrid.vue'
import RevenueTodoPanel from '@/components/revenue/RevenueTodoPanel.vue'

const props = defineProps<{
  selectedDays: number
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const ledgerStore = useLedgerStore()

const industry = computed(() => ledgerStore.industry)

watch(() => props.selectedDays, days => ledgerStore.fetchIndustry(days), { immediate: true })

const columns = computed(() =>
  industry.value === null
    ? []
    : buildIndustryRevenueColumns(industry.value, {
        profit: t('revenue.industry.columns.profit'),
        unknownProfit: t('revenue.industry.columns.unknownProfit'),
        multiProduct: t('ledger.industry.multiProduct'),
      }),
)
const netSeries = computed(() => (industry.value === null ? null : buildIndustryNetSeries(industry.value)))
const period = computed(() => (industry.value === null ? null : { to: industry.value.period.to, days: industry.value.period.days }))

const todoItems = computed<RevenueTodoItem[]>(() => {
  const unknownProjectCount = industry.value?.totals.unknownProjectCount ?? 0
  if (unknownProjectCount === 0) return []
  return [{
    text: t('revenue.industry.todo.unknownProfit.text', { count: unknownProjectCount }, unknownProjectCount),
    effect: t('revenue.industry.todo.unknownProfit.effect'),
    actionLabel: t('revenue.industry.todo.unknownProfit.action'),
    to: { name: 'industry-projects' },
  }]
})

function profitClassOf(profit: number): string {
  return profit < 0 ? 'text-red-400' : 'text-emerald-400'
}

function formatMonth(month: string): string {
  const [year, monthNumber] = month.split('-').map(Number)
  return new Date(Date.UTC(year, monthNumber - 1, 1)).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })
}
</script>

<template>
  <div class="space-y-4">
    <div v-if="!industry" class="flex justify-center py-12">
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>

    <div
      v-else-if="industry.months.length === 0"
      data-testid="industry-empty"
      class="bg-slate-900 rounded-xl p-8 border border-slate-800 text-center text-slate-400"
    >{{ t('ledger.industry.empty') }}</div>

    <template v-else>
      <RevenueLineGrid
        v-if="netSeries && period"
        :title="t('revenue.industry.title')"
        :row-label="t('revenue.industry.rowLabel')"
        :row-sub="t('revenue.industry.rowSub', { count: industry.totals.projectCount }, industry.totals.projectCount)"
        :net-label="t('revenue.industry.columns.profit')"
        :columns="columns"
        :net="netSeries"
        :period="period"
        contributor-unit="products"
      />
      <RevenueTodoPanel :items="todoItems" />

      <div class="bg-slate-900 rounded-xl border border-slate-800">
        <div class="flex items-center justify-between p-4">
          <h3 class="text-sm font-semibold text-white">{{ t('ledger.industry.monthlyProfit') }}</h3>
          <span class="text-xs text-slate-500">{{ t('ledger.industry.completedProjects') }}</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-t border-slate-800">
                <th class="px-4 py-2">{{ t('ledger.industry.columns.month') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.projects') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.profit') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.margin') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.slotHours') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.iskPerHour') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.capital') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="month in industry.months"
                :key="month.month"
                data-testid="industry-month-row"
                class="border-t border-slate-800"
              >
                <td class="px-4 py-2 text-white">
                  {{ formatMonth(month.month) }}
                  <div v-if="month.unknownProjectCount > 0" class="text-xs text-violet-300">
                    {{ t('ledger.industry.unknownProjects', { count: month.unknownProjectCount }, month.unknownProjectCount) }}
                  </div>
                </td>
                <td class="px-4 py-2 text-right text-slate-300">{{ month.projectCount }}</td>
                <td class="px-4 py-2 text-right font-medium" :class="profitClassOf(month.profit)">
                  <UnknownValue v-if="hasNoKnownProfit(month)" />
                  <IskAmount v-else :value="month.profit" short />
                </td>
                <td class="px-4 py-2 text-right text-slate-300">
                  <UnknownValue v-if="month.marginPercent === null" />
                  <template v-else>{{ formatNumber(month.marginPercent, 1) }}%</template>
                </td>
                <td class="px-4 py-2 text-right text-slate-300">
                  <UnknownValue v-if="month.slotHours === null" />
                  <template v-else>{{ formatNumber(month.slotHours, 1) }} {{ t('ledger.industry.hoursUnit') }}</template>
                </td>
                <td class="px-4 py-2 text-right text-slate-300">
                  <UnknownValue v-if="month.iskPerHour === null" />
                  <template v-else>{{ formatIsk(month.iskPerHour) }}</template>
                </td>
                <td class="px-4 py-2 text-right text-slate-300">
                  <UnknownValue v-if="hasNoKnownProfit(month)" />
                  <IskAmount v-else :value="month.capital" short />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bg-slate-900 rounded-xl border border-slate-800">
        <h3 class="p-4 text-sm font-semibold text-white">{{ t('ledger.industry.byProduct') }}</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-t border-slate-800">
                <th class="px-4 py-2">{{ t('ledger.industry.columns.product') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.projects') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.capital') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.profit') }}</th>
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.margin') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="product in industry.products"
                :key="product.typeId ?? MULTI_PRODUCT_CONTRIBUTOR_ID"
                data-testid="industry-product-row"
                class="border-t border-slate-800"
              >
                <td class="px-4 py-2 text-white">
                  <span
                    class="block max-w-64 truncate"
                    :title="product.typeName ?? t('ledger.industry.multiProduct')"
                  >{{ product.typeName ?? t('ledger.industry.multiProduct') }}</span>
                </td>
                <td class="px-4 py-2 text-right text-slate-300">
                  {{ product.projectCount }}
                  <div v-if="product.unknownProjectCount > 0" class="text-xs text-violet-300">
                    {{ t('ledger.industry.unknownProjects', { count: product.unknownProjectCount }, product.unknownProjectCount) }}
                  </div>
                </td>
                <td class="px-4 py-2 text-right text-slate-300">
                  <UnknownValue v-if="hasNoKnownProfit(product)" />
                  <IskAmount v-else :value="product.capital" short />
                </td>
                <td class="px-4 py-2 text-right font-medium" :class="profitClassOf(product.profit)">
                  <UnknownValue v-if="hasNoKnownProfit(product)" />
                  <IskAmount v-else :value="product.profit" short />
                </td>
                <td class="px-4 py-2 text-right text-slate-300">
                  <UnknownValue v-if="hasNoKnownProfit(product) || product.marginPercent === null" />
                  <template v-else>{{ formatNumber(product.marginPercent, 1) }}%</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="p-4 text-xs text-slate-500">{{ t('ledger.industry.hint') }}</p>
      </div>
    </template>
  </div>
</template>

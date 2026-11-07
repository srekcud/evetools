<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore } from '@/stores/ledger'
import { useFormatters } from '@/composables/useFormatters'
import IskAmount from '@/components/common/IskAmount.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const props = defineProps<{
  selectedDays: number
}>()

const MINIMUM_BAR_HEIGHT_PX = 4
const MAXIMUM_BAR_HEIGHT_PX = 160

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const ledgerStore = useLedgerStore()

const industry = computed(() => ledgerStore.industry)

watch(() => props.selectedDays, days => ledgerStore.fetchIndustry(days), { immediate: true })

const largestMonthlyProfit = computed(() =>
  Math.max(1, ...(industry.value?.months ?? []).map(month => Math.abs(month.profit))),
)

function barHeightOf(profit: number): string {
  const height = Math.abs(profit) / largestMonthlyProfit.value * MAXIMUM_BAR_HEIGHT_PX
  return `${Math.max(MINIMUM_BAR_HEIGHT_PX, height)}px`
}

function hasNoKnownProfit(line: { projectCount: number; unknownProjectCount: number }): boolean {
  return line.projectCount > 0 && line.unknownProjectCount === line.projectCount
}

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
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-slate-900 rounded-xl p-4 border border-emerald-500/20">
          <span class="text-emerald-400 text-sm">{{ t('ledger.industry.totalProfit') }}</span>
          <div class="mt-2 text-2xl font-bold text-emerald-400">
            <UnknownValue v-if="hasNoKnownProfit(industry.totals)" />
            <IskAmount v-else :value="industry.totals.profit" short />
          </div>
        </div>
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <span class="text-slate-400 text-sm">{{ t('ledger.industry.columns.projects') }}</span>
          <div class="mt-2 text-2xl font-bold text-white">{{ industry.totals.projectCount }}</div>
          <div v-if="industry.totals.unknownProjectCount > 0" class="mt-1 text-xs text-violet-300">
            {{ t('ledger.industry.unknownProjects', { count: industry.totals.unknownProjectCount }, industry.totals.unknownProjectCount) }}
          </div>
        </div>
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <span class="text-slate-400 text-sm">{{ t('ledger.industry.columns.iskPerHour') }}</span>
          <div class="mt-2 text-2xl font-bold text-white">
            <IskAmount :value="industry.totals.iskPerHour" short />
          </div>
        </div>
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <span class="text-slate-400 text-sm">{{ t('ledger.industry.columns.capital') }}</span>
          <div class="mt-2 text-2xl font-bold text-white">
            <UnknownValue v-if="hasNoKnownProfit(industry.totals)" />
            <IskAmount v-else :value="industry.totals.capital" short />
          </div>
        </div>
      </div>

      <div class="bg-slate-900 rounded-xl border border-slate-800">
        <div class="flex items-center justify-between p-4">
          <h3 class="text-sm font-semibold text-white">{{ t('ledger.industry.monthlyProfit') }}</h3>
          <span class="text-xs text-slate-500">{{ t('ledger.industry.completedProjects') }}</span>
        </div>
        <div class="flex flex-row-reverse items-end justify-center gap-6 px-4 pb-4" style="height: 220px">
          <div
            v-for="month in industry.months"
            :key="month.month"
            class="flex flex-1 flex-col items-center justify-end gap-2"
          >
            <span class="font-mono text-xs text-slate-300">
              <UnknownValue v-if="hasNoKnownProfit(month)" />
              <IskAmount v-else :value="month.profit" short />
            </span>
            <div
              data-testid="industry-month-bar"
              :class="['w-3/5 rounded-t', month.profit < 0 ? 'bg-red-500/70' : 'bg-cyan-500/70']"
              :style="{ height: hasNoKnownProfit(month) ? `${MINIMUM_BAR_HEIGHT_PX}px` : barHeightOf(month.profit) }"
            />
            <span class="text-xs text-slate-500">{{ formatMonth(month.month) }}</span>
          </div>
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
                <th class="px-4 py-2 text-right">{{ t('ledger.industry.columns.profit') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="product in industry.products"
                :key="product.typeId ?? 'multi-product'"
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
                <td class="px-4 py-2 text-right font-medium" :class="profitClassOf(product.profit)">
                  <UnknownValue v-if="hasNoKnownProfit(product)" />
                  <IskAmount v-else :value="product.profit" short />
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

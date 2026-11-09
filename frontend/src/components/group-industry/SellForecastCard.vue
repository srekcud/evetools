<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'
import PartialPriceMarker from '@/components/industry/PartialPriceMarker.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupSellForecastStore } from '@/stores/group-industry/sellForecast'

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
const forecastStore = useGroupSellForecastStore()
const distributionStore = useGroupDistributionStore()
const projectStore = useGroupProjectStore()

const forecast = computed(() => forecastStore.forecast)
const hasStructure = computed(() => forecast.value?.sellStructure != null)
const isLoading = computed(() => forecastStore.loading && forecast.value === null)
const hasError = computed(() => forecastStore.error !== null && forecast.value === null)
const hasProducts = computed(() => (forecast.value?.products.length ?? 0) > 0)

watch(
  [
    () => props.projectId,
    () => distributionStore.distribution,
    () => projectStore.currentProject?.sellStructureId,
  ],
  () => { void forecastStore.fetchForProject(props.projectId) },
  { immediate: true },
)
</script>

<template>
  <div class="eve-card overflow-hidden mb-8" data-testid="sell-forecast-card">
    <div class="px-4 py-3 border-b border-slate-800">
      <h3 class="text-sm font-semibold text-slate-200">{{ t('groupIndustry.sellForecast.title') }}</h3>
      <p v-if="forecast && forecast.sellStructure" class="text-xs text-slate-400 mt-0.5" data-testid="sell-forecast-subtitle">
        {{ t('groupIndustry.sellForecast.sellStructure', { name: forecast.sellStructure.name }) }}
      </p>
      <p v-else-if="forecast" class="text-xs text-amber-400 mt-0.5" data-testid="sell-forecast-no-structure">
        {{ t('groupIndustry.sellForecast.noSellStructure') }}
      </p>
    </div>

    <div v-if="isLoading" class="p-6 flex justify-center" data-testid="sell-forecast-loading">
      <LoadingSpinner />
    </div>
    <p v-else-if="hasError" class="p-4 text-sm text-red-400" data-testid="sell-forecast-error">
      {{ t('groupIndustry.sellForecast.loadError') }}
    </p>
    <p v-else-if="forecast && !hasProducts" class="p-4 text-sm text-slate-400" data-testid="sell-forecast-empty">
      {{ t('groupIndustry.sellForecast.nothingToSell') }}
    </p>

    <div v-else-if="forecast" class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs uppercase tracking-wider text-slate-500">
            <th rowspan="2" class="px-4 py-2 text-left">{{ t('groupIndustry.sellForecast.product') }}</th>
            <th rowspan="2" class="px-4 py-2 text-right">{{ t('groupIndustry.sellForecast.quantity') }}</th>
            <th v-if="hasStructure && forecast.sellStructure" colspan="2" class="px-4 py-2 text-center text-cyan-400" data-testid="sell-forecast-structure-header">{{ forecast.sellStructure.name }}</th>
            <th colspan="2" class="px-4 py-2 text-center">{{ t('groupIndustry.sellForecast.jita') }}</th>
          </tr>
          <tr class="text-xs uppercase tracking-wider text-slate-500">
            <template v-if="hasStructure">
              <th class="px-4 py-1 text-right">{{ t('groupIndustry.sellForecast.netUnitPrice') }}</th>
              <th class="px-4 py-1 text-right">{{ t('groupIndustry.sellForecast.netRevenue') }}</th>
            </template>
            <th class="px-4 py-1 text-right">{{ t('groupIndustry.sellForecast.netUnitPrice') }}</th>
            <th class="px-4 py-1 text-right">{{ t('groupIndustry.sellForecast.netRevenue') }}</th>
          </tr>
        </thead>
        <tbody class="font-mono" style="font-variant-numeric: tabular-nums;">
          <tr
            v-for="product in forecast.products"
            :key="product.typeId"
            class="border-t border-slate-800"
            :data-testid="`sell-forecast-row-${product.typeId}`"
          >
            <td class="px-4 py-2 font-sans text-slate-200" data-testid="sell-forecast-product">{{ product.typeName }}</td>
            <td class="px-4 py-2 text-right text-slate-300" data-testid="sell-forecast-quantity">{{ product.quantity }}</td>
            <template v-if="hasStructure && product.structure">
              <td class="px-4 py-2 text-right text-slate-300" data-testid="sell-forecast-structure-net-unit">
                <UnknownValue v-if="product.structure.netUnitPrice === null" /><template v-else>{{ formatIsk(product.structure.netUnitPrice) }}</template>
              </td>
              <td class="px-4 py-2 text-right text-slate-100" data-testid="sell-forecast-structure-net-revenue">
                <UnknownValue v-if="product.structure.netRevenue === null" /><template v-else>{{ formatIsk(product.structure.netRevenue) }}</template>
              </td>
            </template>
            <td class="px-4 py-2 text-right text-slate-300" data-testid="sell-forecast-jita-net-unit">
              <UnknownValue v-if="product.jita.netUnitPrice === null" /><template v-else>{{ formatIsk(product.jita.netUnitPrice) }}</template><PartialPriceMarker :percent="product.jita.coveragePercent" />
            </td>
            <td class="px-4 py-2 text-right text-slate-100" data-testid="sell-forecast-jita-net-revenue">
              <UnknownValue v-if="product.jita.netRevenue === null" /><template v-else>{{ formatIsk(product.jita.netRevenue) }}</template>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-slate-700" data-testid="sell-forecast-total">
            <td colspan="2" class="px-4 py-2 text-xs uppercase tracking-wider text-slate-400">{{ t('groupIndustry.sellForecast.total') }}</td>
            <td v-if="hasStructure" colspan="2" class="px-4 py-2 text-right font-mono font-bold text-slate-100" data-testid="sell-forecast-total-structure">
              <UnknownValue v-if="forecast.totals.structureNetRevenue === null" /><template v-else>{{ formatIsk(forecast.totals.structureNetRevenue) }}</template>
              <p v-if="forecast.totals.structureUnknownProductCount > 0" class="font-sans text-xs font-normal text-amber-400">{{ t('groupIndustry.sellForecast.unknownProducts', { count: forecast.totals.structureUnknownProductCount }) }}</p>
            </td>
            <td colspan="2" class="px-4 py-2 text-right font-mono font-bold text-slate-100" data-testid="sell-forecast-total-jita">
              <UnknownValue v-if="forecast.totals.jitaNetRevenue === null" /><template v-else>{{ formatIsk(forecast.totals.jitaNetRevenue) }}</template>
              <p v-if="forecast.totals.jitaUnknownProductCount > 0" class="font-sans text-xs font-normal text-amber-400">{{ t('groupIndustry.sellForecast.unknownProducts', { count: forecast.totals.jitaUnknownProductCount }) }}</p>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>

    <p v-if="forecast && hasProducts" class="px-4 py-2 text-xs text-slate-500 border-t border-slate-800">{{ t('groupIndustry.sellForecast.netRevenueOnly') }}</p>
  </div>
</template>

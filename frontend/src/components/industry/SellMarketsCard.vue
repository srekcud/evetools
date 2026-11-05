<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from '@/components/common/DataTable.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import PartialPriceMarker from '@/components/industry/PartialPriceMarker.vue'
import SellMarketUnknownValue from '@/components/industry/SellMarketUnknownValue.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useSellMarketsStore } from '@/stores/industry/sellMarkets'
import type { SellMarketLine } from '@/stores/industry/types'

const AMBER_DAYS_THRESHOLD = 14
const MINIMUM_QUANTITY = 1
const MAXIMUM_QUANTITY = 100_000_000
const PERCENT_PER_FRACTION = 100
const DAYS_DECIMALS_FACTOR = 10
const FEE_RATE_DECIMALS = 2

const props = defineProps<{
  typeId: number
  quantity: number
}>()

const { t } = useI18n()
const { formatIskFull } = useFormatters()
const store = useSellMarketsStore()

const editedQuantity = ref<number | string>(props.quantity)
const requestedQuantity = ref(props.quantity)

watch(
  () => [props.typeId, props.quantity] as const,
  ([typeId, quantity]) => {
    editedQuantity.value = quantity
    requestedQuantity.value = quantity
    void store.fetchSellMarkets(typeId, quantity)
  },
  { immediate: true },
)

function reloadForEditedQuantity() {
  const quantity = editedQuantity.value
  if (typeof quantity !== 'number' || !Number.isInteger(quantity)) {
    editedQuantity.value = requestedQuantity.value
    return
  }
  if (quantity === requestedQuantity.value) return
  requestedQuantity.value = quantity
  void store.fetchSellMarkets(props.typeId, quantity)
}

function volumeCauseKey(market: SellMarketLine): string {
  return market.volumeKind === 'structure_estimate'
    ? 'industry.sellMarkets.unknown.noVolume'
    : 'industry.sellMarkets.unknown.noRegionVolume'
}

function formatDays(days: number): string {
  return t('industry.sellMarkets.days', { days: Math.round(days * DAYS_DECIMALS_FACTOR) / DAYS_DECIMALS_FACTOR })
}

function formatFeeRate(feeRate: number): string {
  return `${(feeRate * PERCENT_PER_FRACTION).toFixed(FEE_RATE_DECIMALS)}%`
}
</script>

<template>
  <div class="eve-card overflow-hidden" data-testid="sell-markets-card">
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3">
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
        </svg>
        <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.sellMarkets.title') }}</h4>
      </div>
      <label class="flex items-center gap-2 text-xs text-slate-400 uppercase tracking-wider">
        {{ t('industry.sellMarkets.quantity') }}
        <input
          v-model.number="editedQuantity"
          data-testid="sell-markets-quantity"
          type="number"
          :min="MINIMUM_QUANTITY"
          :max="MAXIMUM_QUANTITY"
          step="1"
          class="w-28 px-2 py-1 bg-slate-900 border border-slate-700 rounded-sm text-right font-mono text-slate-100 normal-case tabular-nums focus:border-cyan-500 focus:outline-hidden"
          @change="reloadForEditedQuantity"
        />
      </label>
    </div>

    <p v-if="store.error" class="px-4 py-3 text-sm text-red-400">{{ store.error }}</p>

    <div v-else-if="store.loading && !store.result" class="flex justify-center py-6">
      <LoadingSpinner />
    </div>

    <template v-else-if="store.result">
      <DataTable>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-left py-2.5 px-4"></th>
              <th class="text-right py-2.5 px-3">{{ t('industry.sellMarkets.price') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.sellMarkets.volume') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.sellMarkets.fees') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.sellMarkets.net') }}</th>
              <th class="text-right py-2.5 px-4">{{ t('industry.sellMarkets.daysToSellAll') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="market in store.result.markets"
              :key="market.key"
              :data-testid="`sell-market-row-${market.key}`"
              class="hover:bg-slate-800/50"
            >
              <td class="py-2.5 px-4 text-slate-200">{{ market.name }}</td>
              <td data-testid="sell-market-price" class="py-2.5 px-3 text-right font-mono tabular-nums text-slate-100">
                <SellMarketUnknownValue v-if="market.unitPrice === null" :cause="t('industry.sellMarkets.unknown.noSellOrder')" />
                <template v-else>
                  {{ formatIskFull(market.unitPrice) }}<PartialPriceMarker :percent="market.coveragePercent" tooltip-key="industry.partialPrice.sellTooltip" />
                </template>
              </td>
              <td data-testid="sell-market-volume" class="py-2.5 px-3 text-right font-mono tabular-nums text-slate-300">
                <SellMarketUnknownValue v-if="market.dailyVolume === null" :cause="t(volumeCauseKey(market))" />
                <template v-else>
                  {{ market.dailyVolume }}
                  <span v-if="market.volumeKind === 'structure_estimate'" class="ml-1 text-xs font-sans text-slate-500">{{ t('industry.sellMarkets.capitalEstimate') }}</span>
                </template>
              </td>
              <td data-testid="sell-market-fees" class="py-2.5 px-3 text-right font-mono tabular-nums text-amber-400/70">
                <SellMarketUnknownValue v-if="market.feeRate === null" :cause="t('industry.sellMarkets.unknown.noFees')" />
                <template v-else>{{ formatFeeRate(market.feeRate) }}</template>
              </td>
              <td data-testid="sell-market-net" class="py-2.5 px-3 text-right font-mono tabular-nums text-emerald-400">
                <SellMarketUnknownValue v-if="market.unitPrice === null" :cause="t('industry.sellMarkets.unknown.noSellOrder')" />
                <SellMarketUnknownValue v-else-if="market.netUnitPrice === null" :cause="t('industry.sellMarkets.unknown.noFees')" />
                <template v-else>{{ formatIskFull(market.netUnitPrice) }}</template>
              </td>
              <td
                data-testid="sell-market-days"
                class="py-2.5 px-4 text-right font-mono tabular-nums"
                :class="market.daysToSell !== null && market.daysToSell > AMBER_DAYS_THRESHOLD ? 'text-amber-400' : 'text-slate-300'"
              >
                <SellMarketUnknownValue v-if="market.daysToSell === null" :cause="t(volumeCauseKey(market))" />
                <template v-else>{{ formatDays(market.daysToSell) }}</template>
              </td>
            </tr>
          </tbody>
        </table>
      </DataTable>

      <div data-testid="immediate-sale" class="px-4 py-3 border-t border-slate-800 bg-slate-900/40">
        <div class="flex items-center justify-between gap-3">
          <h5 class="text-xs uppercase tracking-wider text-slate-400">{{ t('industry.sellMarkets.immediateSale') }}</h5>
          <span class="text-xs text-slate-500">{{ t('industry.sellMarkets.shareOfVolume', { percent: store.result.sellShareOfDailyVolume * PERCENT_PER_FRACTION }) }}</span>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-4 text-sm">
          <div>
            <div class="text-xs text-slate-500">{{ t('industry.sellMarkets.averagePrice') }}</div>
            <div data-testid="immediate-sale-price" class="font-mono tabular-nums text-slate-100">
              <SellMarketUnknownValue v-if="store.result.immediateSale.averageUnitPrice === null" :cause="t('industry.sellMarkets.unknown.noBuyOrder')" />
              <template v-else>
                {{ formatIskFull(store.result.immediateSale.averageUnitPrice) }}<PartialPriceMarker :percent="store.result.immediateSale.coveragePercent" tooltip-key="industry.partialPrice.sellTooltip" />
              </template>
            </div>
          </div>
          <div>
            <div class="text-xs text-slate-500">{{ t('industry.sellMarkets.net') }}</div>
            <div data-testid="immediate-sale-net" class="font-mono tabular-nums text-emerald-400">
              <SellMarketUnknownValue v-if="store.result.immediateSale.averageUnitPrice === null" :cause="t('industry.sellMarkets.unknown.noBuyOrder')" />
              <SellMarketUnknownValue v-else-if="store.result.immediateSale.netUnitPrice === null" :cause="t('industry.sellMarkets.unknown.noFees')" />
              <template v-else>{{ formatIskFull(store.result.immediateSale.netUnitPrice) }}</template>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupContractSalesStore } from '@/stores/group-industry/contractSales'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupSellForecastStore } from '@/stores/group-industry/sellForecast'
import { useProjectSellOrdersStore } from '@/stores/group-industry/sellOrders'
import type { OrderPositionState, SellForecastMarket } from '@/stores/group-industry/types'
import { ofType, sellForecastProductOf, sellOrdersOfType, type DetailBlockKind } from './projectFlowCardDetail'
import { useFlowDetailFormat } from './useFlowDetailFormat'

const props = defineProps<{
  kind: DetailBlockKind
  typeId: number
  canRecordSales: boolean
}>()

const POSITION_LABEL_KEYS: Record<OrderPositionState, string> = {
  first: 'positions.first',
  undercut: 'positions.undercut',
  unknown: 'unknown',
}

const { formatNumber } = useFormatters()
const { detailText, numberOrUnknown, iskOrUnknown, textOrUnknown, percentOrUnknown, dateTimeOrUnknown } = useFlowDetailFormat()
const sellForecastStore = useGroupSellForecastStore()
const sellOrdersStore = useProjectSellOrdersStore()
const distributionStore = useGroupDistributionStore()
const contractSalesStore = useGroupContractSalesStore()

const forecastProduct = computed(() => (sellForecastStore.forecast === null ? undefined : sellForecastProductOf(sellForecastStore.forecast.products, props.typeId)))
const forecastMarkets = computed(() => {
  const product = forecastProduct.value
  if (product === undefined) return []
  const markets: SellForecastMarket[] = product.structure === null ? [product.jita] : [product.structure, product.jita]
  return markets
})
const sellOrders = computed(() => (sellOrdersStore.sellOrders === null ? [] : sellOrdersOfType(sellOrdersStore.sellOrders.orders, props.typeId)))
const transactionSuggestions = computed(() => (props.canRecordSales ? ofType(distributionStore.saleSuggestions, props.typeId) : []))
const contractSuggestions = computed(() => (props.canRecordSales && contractSalesStore.suggestions !== null ? ofType(contractSalesStore.suggestions.candidates, props.typeId) : []))
const recordedSales = computed(() => ofType(distributionStore.sales, props.typeId))
const hasNothingToMatch = computed(() => transactionSuggestions.value.length === 0 && contractSuggestions.value.length === 0)

function orderPosition(order: { state: OrderPositionState; rank: number | null }): string {
  return order.state === 'unknown' || order.rank === null ? detailText(POSITION_LABEL_KEYS[order.state]) : `${detailText(POSITION_LABEL_KEYS[order.state])} (${formatNumber(order.rank, 0)})`
}
</script>

<template>
  <div v-if="kind === 'whereToSell'">
    <table v-if="forecastMarkets.length > 0" class="w-full border-collapse text-left">
      <thead>
        <tr class="text-[10.5px] uppercase tracking-wider text-slate-500">
          <th class="py-0.5 pr-2 font-semibold">{{ detailText('fields.market') }}</th>
          <th class="py-0.5 pr-2 text-right font-semibold">{{ detailText('fields.netPerUnit') }}</th>
          <th class="py-0.5 pr-2 text-right font-semibold">{{ detailText('fields.coverage') }}</th>
          <th class="py-0.5 text-right font-semibold">{{ detailText('fields.daysToSell') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="market in forecastMarkets" :key="market.name" data-testid="flow-detail-market" class="border-t border-slate-800/60">
          <td class="py-0.5 pr-2">{{ market.name }}</td>
          <td class="py-0.5 pr-2 text-right tabular-nums">{{ iskOrUnknown(market.netUnitPrice) }}</td>
          <td class="py-0.5 pr-2 text-right tabular-nums">{{ percentOrUnknown(market.coveragePercent) }}</td>
          <td class="py-0.5 text-right tabular-nums">{{ numberOrUnknown(market.daysToSell) }}</td>
        </tr>
      </tbody>
    </table>
    <p v-else class="text-slate-400">{{ detailText('unknown') }}</p>
  </div>

  <div v-else-if="kind === 'sellOrders'">
    <ul v-if="sellOrders.length > 0" class="space-y-0.5">
      <li v-for="order in sellOrders" :key="order.orderId" data-testid="flow-detail-sell-order" class="flex flex-wrap gap-x-2">
        <span>{{ textOrUnknown(order.marketName) }}</span>
        <span class="tabular-nums">{{ formatNumber(order.volumeRemain, 0) }}</span>
        <span class="tabular-nums">{{ iskOrUnknown(order.price) }}</span>
        <span>{{ orderPosition(order) }}</span>
        <span v-if="order.suggestedPrice !== null" class="tabular-nums text-amber-300">{{ detailText('phrases.suggestedPrice', { price: iskOrUnknown(order.suggestedPrice) }) }}</span>
      </li>
    </ul>
    <p v-else class="text-slate-400">{{ detailText('phrases.noSellOrder') }}</p>
  </div>

  <div v-else-if="kind === 'salesToMatch'">
    <p v-if="!canRecordSales" class="text-slate-500">{{ detailText('phrases.cannotRecordSales') }}</p>
    <p v-else-if="hasNothingToMatch" class="text-slate-400">{{ detailText('phrases.noSaleToMatch') }}</p>
    <ul v-else class="space-y-0.5">
      <li v-for="suggestion in transactionSuggestions" :key="suggestion.transactionId" data-testid="flow-detail-sale-suggestion" class="flex flex-wrap gap-x-2">
        <span>{{ suggestion.sellerCharacterName }}</span>
        <span class="tabular-nums">{{ formatNumber(suggestion.quantity, 0) }}</span>
        <span class="tabular-nums">{{ iskOrUnknown(suggestion.unitPrice) }}</span>
        <span class="text-slate-500">{{ dateTimeOrUnknown(suggestion.date) }}</span>
      </li>
      <li v-for="candidate in contractSuggestions" :key="candidate.contractId" data-testid="flow-detail-contract-suggestion" class="flex flex-wrap gap-x-2">
        <span>{{ candidate.sellerCharacterName }}</span>
        <span class="tabular-nums">{{ formatNumber(candidate.quantity, 0) }}</span>
        <span class="tabular-nums">{{ iskOrUnknown(candidate.unitPrice) }}</span>
        <span class="text-slate-500">{{ detailText('phrases.contractNumber', { id: candidate.contractId }) }}</span>
      </li>
    </ul>
    <template v-if="recordedSales.length > 0">
      <p class="mt-1.5 text-slate-500">{{ detailText('phrases.recordedSales') }}</p>
      <ul class="space-y-0.5">
        <li v-for="sale in recordedSales" :key="sale.id" data-testid="flow-detail-recorded-sale" class="flex flex-wrap gap-x-2">
          <span class="tabular-nums">{{ formatNumber(sale.quantity, 0) }}</span>
          <span class="tabular-nums">{{ iskOrUnknown(sale.unitPrice) }}</span>
          <span class="text-slate-500">{{ dateTimeOrUnknown(sale.soldAt) }}</span>
        </li>
      </ul>
    </template>
  </div>
</template>

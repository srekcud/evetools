<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { MarketOrder } from '@/stores/market'

const props = defineProps<{
  sellOrders: MarketOrder[]
  buyOrders: MarketOrder[]
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()

const maxOrders = 10

interface OrderWithCumulative extends MarketOrder {
  cumulative: number
}

const sellOrdersWithCumulative = computed<OrderWithCumulative[]>(() => {
  const orders = props.sellOrders.slice(0, maxOrders)
  let cumulative = 0
  return orders.map(o => {
    cumulative += o.volume
    return { ...o, cumulative }
  })
})

const buyOrdersWithCumulative = computed<OrderWithCumulative[]>(() => {
  const orders = props.buyOrders.slice(0, maxOrders)
  let cumulative = 0
  return orders.map(o => {
    cumulative += o.volume
    return { ...o, cumulative }
  })
})

const maxSellCumulative = computed(() => {
  if (sellOrdersWithCumulative.value.length === 0) return 1
  return sellOrdersWithCumulative.value[sellOrdersWithCumulative.value.length - 1].cumulative
})

const maxBuyCumulative = computed(() => {
  if (buyOrdersWithCumulative.value.length === 0) return 1
  return buyOrdersWithCumulative.value[buyOrdersWithCumulative.value.length - 1].cumulative
})
</script>

<template>
  <div class="grid grid-cols-2 gap-4">
    <!-- Sell Orders -->
    <div>
      <h4 class="text-xs font-medium text-red-400 uppercase tracking-wider mb-2">
        {{ t('market.detail.sell') }}
      </h4>
      <div class="space-y-0.5">
        <div
          v-for="(order, i) in sellOrdersWithCumulative"
          :key="'sell-' + i"
          class="relative flex items-center justify-between px-2 py-1 text-xs rounded-sm overflow-hidden"
        >
          <div
            class="absolute inset-0 bg-red-500/10"
            :style="{ width: (order.cumulative / maxSellCumulative * 100) + '%' }"
          />
          <span class="relative font-mono text-red-400">{{ formatIsk(order.price) }}</span>
          <span class="relative font-mono text-slate-400">{{ formatNumber(order.volume, 0) }}</span>
        </div>
        <div v-if="sellOrders.length === 0" class="text-center py-4">
          <span class="text-xs text-slate-600">---</span>
        </div>
      </div>
    </div>

    <!-- Buy Orders -->
    <div>
      <h4 class="text-xs font-medium text-emerald-400 uppercase tracking-wider mb-2">
        {{ t('market.detail.buy') }}
      </h4>
      <div class="space-y-0.5">
        <div
          v-for="(order, i) in buyOrdersWithCumulative"
          :key="'buy-' + i"
          class="relative flex items-center justify-between px-2 py-1 text-xs rounded-sm overflow-hidden"
        >
          <div
            class="absolute inset-0 bg-emerald-500/10 right-0"
            :style="{ width: (order.cumulative / maxBuyCumulative * 100) + '%' }"
          />
          <span class="relative font-mono text-emerald-400">{{ formatIsk(order.price) }}</span>
          <span class="relative font-mono text-slate-400">{{ formatNumber(order.volume, 0) }}</span>
        </div>
        <div v-if="buyOrders.length === 0" class="text-center py-4">
          <span class="text-xs text-slate-600">---</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { MarketOrder } from '@/stores/market'

defineProps<{
  sellOrders: MarketOrder[]
  buyOrders: MarketOrder[]
  structureSellOrders?: MarketOrder[]
  structureBuyOrders?: MarketOrder[]
  hasStructure?: boolean
  structureName?: string
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()

const maxOrders = 10

function extractSystemName(name?: string): string {
  if (!name) return 'Structure'
  const dash = name.indexOf(' - ')
  return dash > 0 ? name.substring(0, dash) : name
}
</script>

<template>
  <div
    class="grid divide-x divide-slate-800/50"
    :class="hasStructure ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-2'"
  >
    <!-- Jita Sell -->
    <div>
      <div class="px-3 py-2 bg-cyan-500/5 border-b border-slate-800/30">
        <span class="text-xs font-medium text-cyan-400 uppercase tracking-wider">
          <template v-if="hasStructure">Jita {{ t('market.detail.sell') }}</template>
          <template v-else>{{ t('market.detail.sellOrders') }}</template>
        </span>
      </div>
      <table class="w-full">
        <thead>
          <tr class="border-b border-slate-800/30">
            <th class="text-right px-3 py-1.5 text-xs text-slate-600">Price</th>
            <th class="text-right px-3 py-1.5 text-xs text-slate-600">Volume</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/20">
          <tr
            v-for="(order, i) in sellOrders.slice(0, maxOrders)"
            :key="'sell-' + i"
            class="hover:bg-cyan-500/5 transition-colors"
          >
            <td class="text-right px-3 py-1.5 text-xs font-mono text-cyan-400">{{ formatIsk(order.price) }}</td>
            <td class="text-right px-3 py-1.5 text-xs font-mono text-slate-400">{{ formatNumber(order.volume, 0) }}</td>
          </tr>
          <tr v-if="sellOrders.length === 0">
            <td colspan="2" class="text-center py-4">
              <span class="text-xs text-slate-600">---</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Jita Buy -->
    <div>
      <div class="px-3 py-2 bg-amber-500/5 border-b border-slate-800/30">
        <span class="text-xs font-medium text-amber-400 uppercase tracking-wider">
          <template v-if="hasStructure">Jita {{ t('market.detail.buy') }}</template>
          <template v-else>{{ t('market.detail.buyOrders') }}</template>
        </span>
      </div>
      <table class="w-full">
        <thead>
          <tr class="border-b border-slate-800/30">
            <th class="text-right px-3 py-1.5 text-xs text-slate-600">Price</th>
            <th class="text-right px-3 py-1.5 text-xs text-slate-600">Volume</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/20">
          <tr
            v-for="(order, i) in buyOrders.slice(0, maxOrders)"
            :key="'buy-' + i"
            class="hover:bg-amber-500/5 transition-colors"
          >
            <td class="text-right px-3 py-1.5 text-xs font-mono text-amber-400">{{ formatIsk(order.price) }}</td>
            <td class="text-right px-3 py-1.5 text-xs font-mono text-slate-400">{{ formatNumber(order.volume, 0) }}</td>
          </tr>
          <tr v-if="buyOrders.length === 0">
            <td colspan="2" class="text-center py-4">
              <span class="text-xs text-slate-600">---</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Structure Sell -->
    <div v-if="hasStructure">
      <div class="px-3 py-2 bg-teal-500/5 border-b border-slate-800/30">
        <span class="text-xs font-medium text-teal-400 uppercase tracking-wider">
          {{ extractSystemName(structureName) }} {{ t('market.detail.sell') }}
        </span>
      </div>
      <table class="w-full">
        <thead>
          <tr class="border-b border-slate-800/30">
            <th class="text-right px-3 py-1.5 text-xs text-slate-600">Price</th>
            <th class="text-right px-3 py-1.5 text-xs text-slate-600">Volume</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/20">
          <tr
            v-for="(order, i) in (structureSellOrders ?? []).slice(0, maxOrders)"
            :key="'struct-sell-' + i"
            class="hover:bg-teal-500/5 transition-colors"
          >
            <td class="text-right px-3 py-1.5 text-xs font-mono text-teal-400">{{ formatIsk(order.price) }}</td>
            <td class="text-right px-3 py-1.5 text-xs font-mono text-slate-400">{{ formatNumber(order.volume, 0) }}</td>
          </tr>
          <tr v-if="!structureSellOrders || structureSellOrders.length === 0">
            <td colspan="2" class="text-center py-4">
              <span class="text-xs text-slate-600">{{ t('market.detail.noOrders') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Structure Buy -->
    <div v-if="hasStructure">
      <div class="px-3 py-2 bg-orange-500/5 border-b border-slate-800/30">
        <span class="text-xs font-medium text-orange-400 uppercase tracking-wider">
          {{ extractSystemName(structureName) }} {{ t('market.detail.buy') }}
        </span>
      </div>
      <table class="w-full">
        <thead>
          <tr class="border-b border-slate-800/30">
            <th class="text-right px-3 py-1.5 text-xs text-slate-600">Price</th>
            <th class="text-right px-3 py-1.5 text-xs text-slate-600">Volume</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/20">
          <tr
            v-for="(order, i) in (structureBuyOrders ?? []).slice(0, maxOrders)"
            :key="'struct-buy-' + i"
            class="hover:bg-orange-500/5 transition-colors"
          >
            <td class="text-right px-3 py-1.5 text-xs font-mono text-orange-400">{{ formatIsk(order.price) }}</td>
            <td class="text-right px-3 py-1.5 text-xs font-mono text-slate-400">{{ formatNumber(order.volume, 0) }}</td>
          </tr>
          <tr v-if="!structureBuyOrders || structureBuyOrders.length === 0">
            <td colspan="2" class="text-center py-4">
              <span class="text-xs text-slate-600">{{ t('market.detail.noOrders') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

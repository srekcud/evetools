<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ProjectSellOrder, ProjectSellOrders } from '@/stores/group-industry/types'

const props = defineProps<{ sellOrders: ProjectSellOrders; now: number }>()

const MS_PER_MINUTE = 60_000
const MINUTES_PER_HOUR = 60
const HOURS_PER_DAY = 24

const { t } = useI18n()
const { formatIsk } = useFormatters()

function undercutDuration(since: string | null): string {
  if (since === null) return t('common.unknownValue')
  const minutes = Math.max(0, Math.floor((props.now - Date.parse(since)) / MS_PER_MINUTE))
  if (minutes < MINUTES_PER_HOUR) return t('groupIndustry.sellOrders.duration.minutes', { count: minutes })
  const hours = Math.floor(minutes / MINUTES_PER_HOUR)
  if (hours < HOURS_PER_DAY) return t('groupIndustry.sellOrders.duration.hours', { count: hours })
  return t('groupIndustry.sellOrders.duration.days', { count: Math.floor(hours / HOURS_PER_DAY) })
}

function positionText(order: ProjectSellOrder): string {
  if (order.state === 'first') return t('groupIndustry.sellOrders.position.first')
  if (order.state === 'undercut') {
    return t('groupIndustry.sellOrders.position.undercut', { rank: order.rank, duration: undercutDuration(order.undercutSince) })
  }
  return t(`groupIndustry.sellOrders.unknownReason.${order.unknownReason}`)
}

function positionClasses(order: ProjectSellOrder): string {
  if (order.state === 'first') return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'
  if (order.state === 'undercut') return 'border-amber-500/30 bg-amber-500/10 text-amber-300'
  return 'border-dashed border-violet-500/40 text-violet-300'
}

async function copyPrice(order: ProjectSellOrder): Promise<void> {
  if (order.suggestedPrice === null) return
  await navigator.clipboard.writeText(String(order.suggestedPrice))
}
</script>

<template>
  <section class="mb-6 rounded-xl border border-slate-800 bg-slate-900/50" data-testid="sell-orders-card">
    <header class="border-b border-slate-800 px-4 py-3">
      <h3 class="text-sm font-semibold text-slate-200">{{ t('groupIndustry.sellOrders.title') }}</h3>
    </header>

    <p
      v-if="sellOrders.charactersToReconnect.length > 0"
      data-testid="sell-orders-reconnect"
      class="border-b border-slate-800 px-4 py-2 text-sm text-amber-300"
    >
      {{ t('groupIndustry.sellOrders.reconnect', { characters: sellOrders.charactersToReconnect.join(', ') }) }}
    </p>

    <p v-if="sellOrders.orders.length === 0" data-testid="sell-orders-empty" class="px-4 py-4 text-sm text-slate-400">
      {{ t('groupIndustry.sellOrders.empty') }}
    </p>
    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
            <th class="px-4 py-2">{{ t('groupIndustry.sellOrders.columns.order') }}</th>
            <th class="px-4 py-2">{{ t('groupIndustry.sellOrders.columns.market') }}</th>
            <th class="px-4 py-2 text-right">{{ t('groupIndustry.sellOrders.columns.myPrice') }}</th>
            <th class="px-4 py-2 text-right">{{ t('groupIndustry.sellOrders.columns.bestPrice') }}</th>
            <th class="px-4 py-2">{{ t('groupIndustry.sellOrders.columns.position') }}</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="order in sellOrders.orders" :key="order.orderId" data-testid="sell-order-row" class="border-t border-slate-800">
            <td class="px-4 py-2">
              <b class="font-medium text-slate-100">{{ order.typeName }}</b>
              <span class="ml-1 text-slate-400">×{{ order.volumeRemain }}</span>
              <div class="text-xs text-slate-500">{{ order.characterName }}</div>
            </td>
            <td class="px-4 py-2 text-slate-300">{{ order.marketName ?? `#${order.locationId}` }}</td>
            <td class="px-4 py-2 text-right font-mono text-slate-100">{{ formatIsk(order.price) }}</td>
            <td data-testid="sell-order-best-price" class="px-4 py-2 text-right font-mono" :class="order.state === 'undercut' ? 'text-amber-300' : 'text-slate-100'">
              <span v-if="order.bestPrice === null" data-unknown="true" class="text-violet-300">{{ t('common.unknownValue') }}</span>
              <template v-else>{{ formatIsk(order.bestPrice) }}</template>
            </td>
            <td class="px-4 py-2">
              <span
                data-testid="sell-order-position"
                :data-state="order.state"
                class="inline-block rounded-full border px-2 py-0.5 text-xs"
                :class="positionClasses(order)"
              >{{ positionText(order) }}</span>
            </td>
            <td class="px-4 py-2 text-right">
              <button
                v-if="order.suggestedPrice !== null"
                data-testid="sell-order-copy-price"
                type="button"
                class="rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-xs font-medium text-cyan-200 transition-colors hover:bg-cyan-500/20"
                @click="copyPrice(order)"
              >
                {{ t('groupIndustry.sellOrders.copyPrice', { price: formatIsk(order.suggestedPrice) }) }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p data-testid="sell-orders-hint" class="border-t border-slate-800 px-4 py-2 text-xs text-slate-500">
      {{ t('groupIndustry.sellOrders.hint') }}
    </p>
  </section>
</template>

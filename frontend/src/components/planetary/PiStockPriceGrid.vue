<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import PiStockPin from '@/components/planetary/PiStockPin.vue'
import PiTierFilter from '@/components/planetary/PiTierFilter.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryStockTodo } from '@/composables/usePlanetaryStockTodo'
import { usePlanetaryTierFilter } from '@/composables/usePlanetaryTierFilter'
import { useTypeNames } from '@/composables/useTypeNames'
import { usePlanetaryStockPricesStore, type PlanetaryStockSignal } from '@/stores/planetaryStockPrices'

type Tone = 'ok' | 'warn' | 'bad'

const ICON_SIZE = 32
const PRICE_DECIMALS = 2
const PERCENT_DECIMALS = 1
const GOOD_GAP_PERCENT = -10
const FAIR_GAP_PERCENT = -3

const TONE_CLASSES: Record<Tone, string> = {
  ok: 'text-emerald-400',
  warn: 'text-amber-400',
  bad: 'text-red-400',
}

const SIGNAL_CLASSES: Record<PlanetaryStockSignal, string> = {
  buy_jita: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  buy_hub: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  outbid: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  place_order: 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300',
}

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { typeNameLabel } = useTypeNames()
const { getTypeIconUrl, onImageError } = useEveImages()
const store = usePlanetaryStockPricesStore()
const todo = usePlanetaryStockTodo()
const { selectedTier, matchesTier } = usePlanetaryTierFilter()

const visibleRows = computed(() => store.rows.filter(row => matchesTier(row.typeId)))

const hasUnsyncedWallets = computed(() => (store.prices?.unsyncedWallets.length ?? 0) > 0)
const isMarketIncomplete = computed(() => store.prices !== null && !store.prices.complete)

const paidPriceHint = computed(() => (store.prices === null ? undefined : t('pi.stock.prices.paidPriceHint', { days: store.prices.paidPriceDays })))

function toneOf(gapPercent: number): Tone {
  if (gapPercent < GOOD_GAP_PERCENT) return 'ok'
  if (gapPercent < FAIR_GAP_PERCENT) return 'warn'

  return 'bad'
}

function percentText(percent: number): string {
  return `${formatNumber(percent, PERCENT_DECIMALS)} %`
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="pi-stock-price-grid">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.stock.prices.title') }}</h3>
    <PiTierFilter v-model="selectedTier" test-id="price-tier-filter" class="mb-4" />
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs uppercase tracking-wider text-slate-500">
            <th class="pb-3 text-left font-medium">{{ t('pi.stock.prices.type') }}</th>
            <th class="pb-3 text-right font-medium" :title="paidPriceHint">{{ t('pi.stock.prices.paidPrice') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.prices.hubSell') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.prices.jitaSell') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.prices.paidVsHub') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.prices.myBuyOrder') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.prices.buyOrderVsHub') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.prices.trend') }}</th>
            <th class="pb-3 text-left font-medium">{{ t('pi.stock.prices.signalColumn') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr v-for="row in visibleRows" :key="row.typeId" :data-testid="`price-row-${row.typeId}`" class="transition-colors hover:bg-slate-800/30">
            <td class="py-2.5">
              <div class="flex items-center gap-2">
                <img :src="getTypeIconUrl(row.typeId, ICON_SIZE)" alt="" class="h-6 w-6 shrink-0 rounded-sm" @error="onImageError" />
                <span class="text-slate-100">{{ typeNameLabel(row.typeName, row.typeId) }}</span>
              </div>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`price-cell-paidPrice-${row.typeId}`">
              <UnknownValue v-if="row.paidPrice === null" />
              <template v-else>{{ formatNumber(row.paidPrice, PRICE_DECIMALS) }}</template>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`price-cell-hubSell-${row.typeId}`">
              <UnknownValue v-if="row.hubSell === null" />
              <template v-else>{{ formatNumber(row.hubSell, PRICE_DECIMALS) }}</template>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`price-cell-jitaSell-${row.typeId}`">
              <UnknownValue v-if="row.jitaSell === null" />
              <template v-else>{{ formatNumber(row.jitaSell, PRICE_DECIMALS) }}</template>
            </td>
            <td
              :class="['py-2.5 text-right font-mono-tech', row.paidVsHubPercent === null ? 'text-slate-300' : TONE_CLASSES[toneOf(row.paidVsHubPercent)]]"
              :data-tone="row.paidVsHubPercent === null ? undefined : toneOf(row.paidVsHubPercent)"
              :data-testid="`price-cell-paidVsHub-${row.typeId}`"
            >
              <UnknownValue v-if="row.paidVsHubPercent === null" />
              <template v-else>{{ percentText(row.paidVsHubPercent) }}</template>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`price-cell-myBuyOrder-${row.typeId}`">
              <span v-if="row.myBuyOrder === null" class="text-slate-500">{{ t('pi.stock.prices.noOrder') }}</span>
              <template v-else>{{ formatNumber(row.myBuyOrder, PRICE_DECIMALS) }}</template>
            </td>
            <td
              :class="['py-2.5 text-right font-mono-tech', row.buyOrderVsHubPercent === null ? 'text-slate-300' : TONE_CLASSES[toneOf(row.buyOrderVsHubPercent)]]"
              :data-tone="row.buyOrderVsHubPercent === null ? undefined : toneOf(row.buyOrderVsHubPercent)"
              :data-testid="`price-cell-buyOrderVsHub-${row.typeId}`"
            >
              <UnknownValue v-if="row.buyOrderVsHubPercent === null" />
              <template v-else>{{ percentText(row.buyOrderVsHubPercent) }}</template>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`price-cell-trend-${row.typeId}`">
              <UnknownValue v-if="row.trend7dPercent === null" />
              <span v-else-if="row.trend7dPercent > 0" class="text-red-400">▲ {{ percentText(row.trend7dPercent) }}</span>
              <span v-else-if="row.trend7dPercent < 0" class="text-emerald-400">▼ {{ percentText(row.trend7dPercent) }}</span>
              <span v-else>= {{ percentText(row.trend7dPercent) }}</span>
            </td>
            <td class="py-2.5" :data-testid="`price-cell-signal-${row.typeId}`">
              <span v-if="row.signal === null" class="text-slate-500">—</span>
              <span v-else :class="['inline-block rounded-full border px-2 py-0.5 text-xs font-medium', SIGNAL_CLASSES[row.signal]]">{{ t(`pi.stock.prices.signal.${row.signal}`) }}</span>
              <PiStockPin kind="signal" :type-id="row.typeId" :number="todo.pins.signal[row.typeId]" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="hasUnsyncedWallets || isMarketIncomplete" class="mt-3 space-y-1 text-xs text-amber-300" data-testid="price-grid-note">
      <p v-if="isMarketIncomplete">{{ t('pi.stock.prices.noteMarketIncomplete') }}</p>
      <p v-if="hasUnsyncedWallets">{{ t('pi.stock.prices.noteWalletIncomplete') }}</p>
    </div>
  </section>
</template>

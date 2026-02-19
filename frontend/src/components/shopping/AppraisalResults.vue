<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'

export interface AppraisalItem {
  typeId: number
  typeName: string
  quantity: number
  volume: number
  totalVolume: number
  sellPrice: number | null
  sellTotal: number | null
  buyPrice: number | null
  buyTotal: number | null
  splitPrice: number | null
  splitTotal: number | null
  sellPriceWeighted: number | null
  sellTotalWeighted: number | null
  buyPriceWeighted: number | null
  buyTotalWeighted: number | null
  splitPriceWeighted: number | null
  splitTotalWeighted: number | null
  sellCoverage: number | null
  buyCoverage: number | null
  avgDailyVolume?: number | null
}

export interface AppraisalTotals {
  sellTotal: number
  buyTotal: number
  splitTotal: number
  volume: number
  sellTotalWeighted: number | null
  buyTotalWeighted: number | null
  splitTotalWeighted: number | null
}

const props = defineProps<{
  items: AppraisalItem[]
  totals: AppraisalTotals
  notFound: string[]
  priceError?: string | null
  structureId?: number | null
  structureName?: string | null
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const copied = ref(false)

function coverageClass(coverage: number | null): string {
  if (coverage === null || coverage >= 1.0) return ''
  if (coverage >= 0.5) return 'text-amber-400'
  return 'text-red-400'
}

function displayPrice(weighted: number | null, best: number | null): number | null {
  return weighted ?? best
}

function copyTable() {
  const header = ['Item', 'Qty', 'Volume', 'Sell (unit)', 'Sell Total', 'Buy (unit)', 'Buy Total', 'Split (unit)', 'Split Total'].join('\t')
  const rows = props.items.map(item => [
    item.typeName,
    item.quantity,
    formatNumber(item.totalVolume) + ' m3',
    displayPrice(item.sellPriceWeighted, item.sellPrice) !== null ? formatNumber(displayPrice(item.sellPriceWeighted, item.sellPrice)!) : '-',
    displayPrice(item.sellTotalWeighted, item.sellTotal) !== null ? formatNumber(displayPrice(item.sellTotalWeighted, item.sellTotal)!) : '-',
    displayPrice(item.buyPriceWeighted, item.buyPrice) !== null ? formatNumber(displayPrice(item.buyPriceWeighted, item.buyPrice)!) : '-',
    displayPrice(item.buyTotalWeighted, item.buyTotal) !== null ? formatNumber(displayPrice(item.buyTotalWeighted, item.buyTotal)!) : '-',
    displayPrice(item.splitPriceWeighted, item.splitPrice) !== null ? formatNumber(displayPrice(item.splitPriceWeighted, item.splitPrice)!) : '-',
    displayPrice(item.splitTotalWeighted, item.splitTotal) !== null ? formatNumber(displayPrice(item.splitTotalWeighted, item.splitTotal)!) : '-',
  ].join('\t'))

  const footer = [
    'Total', '--', formatNumber(props.totals.volume) + ' m3',
    '--', formatNumber(props.totals.sellTotalWeighted ?? props.totals.sellTotal),
    '--', formatNumber(props.totals.buyTotalWeighted ?? props.totals.buyTotal),
    '--', formatNumber(props.totals.splitTotalWeighted ?? props.totals.splitTotal),
  ].join('\t')

  const text = [header, ...rows, footer].join('\n')
  navigator.clipboard.writeText(text)
  copied.value = true
  setTimeout(() => copied.value = false, 2000)
}
</script>

<template>
  <div class="space-y-4">
    <!-- Not Found Items -->
    <div v-if="notFound && notFound.length > 0" class="bg-yellow-900/30 border border-yellow-500/30 rounded-xl p-4">
      <h3 class="text-yellow-400 font-medium mb-2">{{ t('shopping.notFound', { count: notFound.length }) }}</h3>
      <p class="text-yellow-300/70 text-sm">
        {{ notFound.join(', ') }}
      </p>
    </div>

    <!-- Price Error -->
    <div v-if="priceError" class="bg-yellow-900/30 border border-yellow-500/30 rounded-xl p-4 text-yellow-400">
      {{ priceError }}
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <!-- Sell Value -->
      <div class="bg-slate-900 rounded-xl p-5 border border-slate-800 border-t-2 border-t-cyan-400 relative overflow-hidden">
        <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1.5">
          <svg class="w-3 h-3 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
          {{ t('appraisal.sellValue') }}
        </p>
        <p class="text-[22px] font-bold text-cyan-400 font-mono">{{ formatIsk(totals.sellTotalWeighted ?? totals.sellTotal) }}</p>
        <p v-if="totals.sellTotalWeighted !== null && totals.sellTotalWeighted !== undefined" class="text-[11px] text-slate-600 font-mono mt-0.5">
          {{ t('appraisal.bestPrice') }}: {{ formatIsk(totals.sellTotal) }}
        </p>
        <p class="text-[11px] text-slate-500 mt-1">{{ t('appraisal.sellValueDesc') }}</p>
      </div>

      <!-- Buy Value -->
      <div class="bg-slate-900 rounded-xl p-5 border border-slate-800 border-t-2 border-t-amber-400 relative overflow-hidden">
        <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1.5">
          <svg class="w-3 h-3 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" />
          </svg>
          {{ t('appraisal.buyValue') }}
        </p>
        <p class="text-[22px] font-bold text-amber-400 font-mono">{{ formatIsk(totals.buyTotalWeighted ?? totals.buyTotal) }}</p>
        <p v-if="totals.buyTotalWeighted !== null && totals.buyTotalWeighted !== undefined" class="text-[11px] text-slate-600 font-mono mt-0.5">
          {{ t('appraisal.bestPrice') }}: {{ formatIsk(totals.buyTotal) }}
        </p>
        <p class="text-[11px] text-slate-500 mt-1">{{ t('appraisal.buyValueDesc') }}</p>
      </div>

      <!-- Total Volume -->
      <div class="bg-slate-900 rounded-xl p-5 border border-slate-800 border-t-2 border-t-indigo-400 relative overflow-hidden">
        <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1.5">
          <svg class="w-3 h-3 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          {{ t('appraisal.totalVolume') }}
        </p>
        <p class="text-[22px] font-bold text-indigo-400 font-mono">{{ formatNumber(totals.volume) }} m³</p>
        <p class="text-[11px] text-slate-500 mt-1">{{ t('appraisal.itemsAppraised', { count: items.length }) }}</p>
      </div>

      <!-- Split Value -->
      <div class="bg-slate-900 rounded-xl p-5 border border-slate-800 border-t-2 border-t-emerald-400 relative overflow-hidden">
        <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1.5">
          <svg class="w-3 h-3 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
          </svg>
          {{ t('appraisal.splitValue') }}
        </p>
        <p class="text-[22px] font-bold text-emerald-400 font-mono">{{ formatIsk(totals.splitTotalWeighted ?? totals.splitTotal) }}</p>
        <p v-if="totals.splitTotalWeighted !== null && totals.splitTotalWeighted !== undefined" class="text-[11px] text-slate-600 font-mono mt-0.5">
          {{ t('appraisal.bestPrice') }}: {{ formatIsk(totals.splitTotal) }}
        </p>
        <p class="text-[11px] text-slate-500 mt-1">{{ t('appraisal.splitValueDesc') }}</p>
      </div>
    </div>

    <!-- Items Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
      <!-- Table Header -->
      <div class="p-4 border-b border-slate-800 flex items-center justify-between">
        <h3 class="text-[15px] font-semibold text-slate-200">
          Items ({{ items.length }})
        </h3>
        <div class="flex items-center gap-2">
          <!-- Copy button -->
          <button
            @click="copyTable"
            class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-700/50 border border-slate-700 rounded-md text-slate-400 text-[11px] font-medium hover:text-slate-200 hover:bg-slate-700 transition-colors"
          >
            <svg v-if="!copied" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
            <svg v-else class="w-3 h-3 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            {{ copied ? t('appraisal.copied') : t('appraisal.copy') }}
          </button>

          <!-- Share button (placeholder) -->
          <button
            disabled
            class="flex items-center gap-1.5 px-3 py-1.5 bg-slate-700/50 border border-slate-700 rounded-md text-slate-500 text-[11px] font-medium cursor-not-allowed"
          >
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
            </svg>
            {{ t('appraisal.share') }}
          </button>
        </div>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-800/50">
            <tr>
              <th class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ t('shopping.itemColumn') }}</th>
              <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ t('shopping.quantityColumn') }}</th>
              <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ t('shopping.volumeColumn') }}</th>
              <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-cyan-300">{{ t('appraisal.sellUnit') }}</th>
              <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-cyan-300">{{ t('appraisal.sellTotal') }}</th>
              <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-amber-300">{{ t('appraisal.buyUnit') }}</th>
              <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-amber-300">{{ t('appraisal.buyTotal') }}</th>
              <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-emerald-300">{{ t('appraisal.splitUnit') }}</th>
              <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-emerald-300">{{ t('appraisal.splitTotal') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in items"
              :key="item.typeId"
              class="border-b border-slate-800/80 hover:bg-slate-800/30 transition-colors"
            >
              <td class="px-4 py-2.5">
                <div class="flex items-center gap-2.5">
                  <img
                    :src="getTypeIconUrl(item.typeId)"
                    :alt="item.typeName"
                    @error="onImageError"
                    class="w-8 h-8 rounded-sm"
                  />
                  <span class="text-slate-200">{{ item.typeName }}</span>
                  <OpenInGameButton type="market" :targetId="item.typeId" />
                </div>
              </td>
              <td class="px-4 py-2.5 text-right text-slate-300 font-mono">
                {{ formatNumber(item.quantity, 0) }}
              </td>
              <td class="px-4 py-2.5 text-right text-slate-400 font-mono text-xs">
                {{ formatNumber(item.totalVolume) }} m³
              </td>
              <!-- Sell unit -->
              <td class="px-4 py-2.5 text-right font-mono">
                <div v-if="displayPrice(item.sellPriceWeighted, item.sellPrice) !== null">
                  <div class="flex items-center justify-end gap-1">
                    <span
                      v-if="item.sellCoverage !== null && item.sellCoverage < 1.0"
                      class="shrink-0"
                      :class="coverageClass(item.sellCoverage)"
                      :title="t('appraisal.coverageTooltip', { available: Math.round((item.sellCoverage ?? 1) * item.quantity).toLocaleString(), total: item.quantity.toLocaleString() })"
                    >
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                    </span>
                    <span class="text-cyan-300">{{ formatNumber(displayPrice(item.sellPriceWeighted, item.sellPrice)!) }}</span>
                  </div>
                  <div v-if="item.sellPriceWeighted !== null && item.sellPrice !== null" class="text-[10px] text-slate-600 mt-0.5">
                    {{ formatNumber(item.sellPrice) }}
                  </div>
                  <div
                    v-if="item.avgDailyVolume != null"
                    class="text-[10px] mt-0.5"
                    :class="item.quantity > item.avgDailyVolume ? 'text-amber-400/70' : 'text-slate-500'"
                    :title="t('appraisal.avgDailyVolumeTooltip')"
                  >
                    {{ t('appraisal.avgDailyVolume', { volume: formatNumber(Math.round(item.avgDailyVolume), 0) }) }}
                  </div>
                </div>
                <span v-else class="text-cyan-300">-</span>
              </td>
              <!-- Sell total -->
              <td class="px-4 py-2.5 text-right font-mono">
                <div v-if="displayPrice(item.sellTotalWeighted, item.sellTotal) !== null">
                  <span class="text-cyan-400 font-semibold">{{ formatNumber(displayPrice(item.sellTotalWeighted, item.sellTotal)!) }}</span>
                  <div v-if="item.sellTotalWeighted !== null && item.sellTotal !== null" class="text-[10px] text-slate-600 mt-0.5">
                    {{ formatNumber(item.sellTotal) }}
                  </div>
                </div>
                <span v-else class="text-cyan-400 font-semibold">-</span>
              </td>
              <!-- Buy unit -->
              <td class="px-4 py-2.5 text-right font-mono">
                <div v-if="displayPrice(item.buyPriceWeighted, item.buyPrice) !== null">
                  <div class="flex items-center justify-end gap-1">
                    <span
                      v-if="item.buyCoverage !== null && item.buyCoverage < 1.0"
                      class="shrink-0"
                      :class="coverageClass(item.buyCoverage)"
                      :title="t('appraisal.coverageTooltip', { available: Math.round((item.buyCoverage ?? 1) * item.quantity).toLocaleString(), total: item.quantity.toLocaleString() })"
                    >
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                    </span>
                    <span class="text-amber-300">{{ formatNumber(displayPrice(item.buyPriceWeighted, item.buyPrice)!) }}</span>
                  </div>
                  <div v-if="item.buyPriceWeighted !== null && item.buyPrice !== null" class="text-[10px] text-slate-600 mt-0.5">
                    {{ formatNumber(item.buyPrice) }}
                  </div>
                </div>
                <span v-else class="text-amber-300">-</span>
              </td>
              <!-- Buy total -->
              <td class="px-4 py-2.5 text-right font-mono">
                <div v-if="displayPrice(item.buyTotalWeighted, item.buyTotal) !== null">
                  <span class="text-amber-400 font-semibold">{{ formatNumber(displayPrice(item.buyTotalWeighted, item.buyTotal)!) }}</span>
                  <div v-if="item.buyTotalWeighted !== null && item.buyTotal !== null" class="text-[10px] text-slate-600 mt-0.5">
                    {{ formatNumber(item.buyTotal) }}
                  </div>
                </div>
                <span v-else class="text-amber-400 font-semibold">-</span>
              </td>
              <!-- Split unit -->
              <td class="px-4 py-2.5 text-right font-mono">
                <div v-if="displayPrice(item.splitPriceWeighted, item.splitPrice) !== null">
                  <span class="text-emerald-300">{{ formatNumber(displayPrice(item.splitPriceWeighted, item.splitPrice)!) }}</span>
                  <div v-if="item.splitPriceWeighted !== null && item.splitPrice !== null" class="text-[10px] text-slate-600 mt-0.5">
                    {{ formatNumber(item.splitPrice) }}
                  </div>
                </div>
                <span v-else class="text-emerald-300">-</span>
              </td>
              <!-- Split total -->
              <td class="px-4 py-2.5 text-right font-mono">
                <div v-if="displayPrice(item.splitTotalWeighted, item.splitTotal) !== null">
                  <span class="text-emerald-400 font-semibold">{{ formatNumber(displayPrice(item.splitTotalWeighted, item.splitTotal)!) }}</span>
                  <div v-if="item.splitTotalWeighted !== null && item.splitTotal !== null" class="text-[10px] text-slate-600 mt-0.5">
                    {{ formatNumber(item.splitTotal) }}
                  </div>
                </div>
                <span v-else class="text-emerald-400 font-semibold">-</span>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="bg-slate-800/40 border-t border-slate-700/40">
              <td class="px-4 py-3 text-slate-200 font-semibold text-[13px]">Total</td>
              <td class="px-4 py-3 text-right text-slate-200 font-mono text-[13px]">--</td>
              <td class="px-4 py-3 text-right text-slate-400 font-mono text-[13px]">{{ formatNumber(totals.volume) }} m³</td>
              <td class="px-4 py-3 text-right text-slate-500 text-[13px]">--</td>
              <td class="px-4 py-3 text-right font-mono text-sm">
                <span class="text-cyan-400 font-bold">{{ formatNumber(totals.sellTotalWeighted ?? totals.sellTotal) }}</span>
                <div v-if="totals.sellTotalWeighted !== null && totals.sellTotalWeighted !== undefined" class="text-[10px] text-slate-600 mt-0.5">
                  {{ formatNumber(totals.sellTotal) }}
                </div>
              </td>
              <td class="px-4 py-3 text-right text-slate-500 text-[13px]">--</td>
              <td class="px-4 py-3 text-right font-mono text-sm">
                <span class="text-amber-400 font-bold">{{ formatNumber(totals.buyTotalWeighted ?? totals.buyTotal) }}</span>
                <div v-if="totals.buyTotalWeighted !== null && totals.buyTotalWeighted !== undefined" class="text-[10px] text-slate-600 mt-0.5">
                  {{ formatNumber(totals.buyTotal) }}
                </div>
              </td>
              <td class="px-4 py-3 text-right text-slate-500 text-[13px]">--</td>
              <td class="px-4 py-3 text-right font-mono text-sm">
                <span class="text-emerald-400 font-bold">{{ formatNumber(totals.splitTotalWeighted ?? totals.splitTotal) }}</span>
                <div v-if="totals.splitTotalWeighted !== null && totals.splitTotalWeighted !== undefined" class="text-[10px] text-slate-600 mt-0.5">
                  {{ formatNumber(totals.splitTotal) }}
                </div>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>

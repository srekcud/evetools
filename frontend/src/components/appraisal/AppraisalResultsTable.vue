<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'

export type MergedItem = {
  typeId: number
  typeName: string
  quantity: number
  volume: number
  totalVolume: number
  sellPrice: number | null
  sellPriceWeighted: number | null
  sellTotal: number | null
  sellTotalWeighted: number | null
  sellCoverage: number | null
  buyPrice: number | null
  buyPriceWeighted: number | null
  buyTotal: number | null
  buyTotalWeighted: number | null
  buyCoverage: number | null
  avgDailyVolume: number | null
  jitaWithImport: number | null
  structureTotal: number | null
  structureCoverage: number | null
  structureCoverageQty: number | null
  bestLocation: 'jita' | 'structure' | null
}

const props = defineProps<{
  items: MergedItem[]
  hasStructureResults: boolean
  shortStructureName: string
  itemCount: number
  jitaItemCount: number
  structureItemCount: number
  totalVolume: number
  sellValue: number
  sellValueBest: number
  buyValue: number
  buyValueBest: number
  jitaPlusTransport: number | null
  structureTotal: number | null
  bestPrice: number | null
  lowCoverageItems: number
  hasSellTotalWeighted: boolean
  hasBuyTotalWeighted: boolean
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

function coverageColorClass(coverage: number | null): string {
  if (coverage == null || coverage >= 1.0) return 'text-emerald-500'
  if (coverage >= 0.5) return 'text-amber-400'
  return 'text-red-400'
}

function structurePriceColorClass(coverage: number | null): string {
  if (coverage == null || coverage >= 1.0) return 'text-violet-400'
  if (coverage >= 0.5) return 'text-amber-500'
  return 'text-red-400'
}

function structureCoverageBarClass(coverage: number | null): string {
  if (coverage == null || coverage >= 1.0) return 'bg-emerald-500'
  if (coverage >= 0.5) return 'bg-amber-500'
  return 'bg-red-500'
}

function structureCoverageTextClass(coverage: number | null): string {
  if (coverage == null || coverage >= 1.0) return 'text-emerald-500'
  if (coverage >= 0.5) return 'text-amber-500'
  return 'text-red-400'
}

function displaySellWeighted(item: MergedItem): number | null {
  return item.sellPriceWeighted ?? item.sellPrice
}

function displayBuyWeighted(item: MergedItem): number | null {
  return item.buyPriceWeighted ?? item.buyPrice
}

function displaySellTotalWeighted(item: MergedItem): number | null {
  return item.sellTotalWeighted ?? item.sellTotal
}

function displayBuyTotalWeighted(item: MergedItem): number | null {
  return item.buyTotalWeighted ?? item.buyTotal
}
</script>

<template>
  <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
    <!-- Table header bar -->
    <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
      <h3 class="text-[15px] font-semibold text-slate-200">
        Items ({{ props.itemCount }})
      </h3>
      <div v-if="props.hasStructureResults" class="flex items-center gap-4 text-[13px]">
        <span class="flex items-center gap-1.5">
          <span class="w-2.5 h-2.5 rounded-full bg-cyan-500"></span>
          <span class="text-slate-400">Jita ({{ props.jitaItemCount }})</span>
        </span>
        <span class="flex items-center gap-1.5">
          <span class="w-2.5 h-2.5 rounded-full bg-violet-500"></span>
          <span class="text-slate-400">{{ props.shortStructureName }} ({{ props.structureItemCount }})</span>
        </span>
      </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-[13px] border-collapse">
        <thead>
          <!-- Group header row -->
          <tr v-if="props.hasStructureResults" class="bg-slate-950/80">
            <th colspan="3" class="p-0"></th>
            <!-- Jita group -->
            <th
              colspan="3"
              class="px-4 py-2 text-center border-l-2 border-r-2 border-t-2 border-cyan-500/20 bg-cyan-500/6 rounded-tl-sm rounded-tr-sm"
            >
              <div class="flex items-center justify-center gap-2">
                <div class="w-1.5 h-1.5 rounded-full bg-cyan-400"></div>
                <span class="text-[11px] font-bold uppercase tracking-widest text-cyan-400">Jita 4-4</span>
                <div class="w-1.5 h-1.5 rounded-full bg-cyan-400"></div>
              </div>
            </th>
            <!-- Structure group -->
            <th
              class="px-4 py-2 text-center border-l-2 border-r-2 border-t-2 border-violet-500/20 bg-violet-500/6"
            >
              <div class="flex items-center justify-center gap-2">
                <div class="w-1.5 h-1.5 rounded-full bg-violet-400"></div>
                <span class="text-[11px] font-bold uppercase tracking-widest text-violet-400">{{ props.shortStructureName }}</span>
                <div class="w-1.5 h-1.5 rounded-full bg-violet-400"></div>
              </div>
            </th>
            <th class="p-0"></th>
          </tr>

          <!-- Column headers row -->
          <tr class="bg-slate-800/50">
            <th class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ t('shopping.itemColumn') }}</th>
            <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ t('shopping.quantityColumn') }}</th>
            <th class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ t('shopping.volumeColumn') }}</th>
            <!-- Jita columns -->
            <th
              class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-400"
              :class="props.hasStructureResults ? 'border-l-2 border-cyan-500/15 bg-cyan-500/3' : ''"
            >
              {{ t('appraisal.unitPrice') }}
            </th>
            <th
              class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-400"
              :class="props.hasStructureResults ? 'bg-cyan-500/3' : ''"
            >
              {{ t('appraisal.weightedTotal') }}
            </th>
            <th
              v-if="props.hasStructureResults"
              class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-cyan-300 border-r-2 border-cyan-500/15 bg-cyan-500/3"
              :title="t('appraisal.importTooltip')"
            >
              + Import
            </th>
            <!-- Structure column -->
            <th
              v-if="props.hasStructureResults"
              class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-violet-300 border-l-2 border-r-2 border-violet-500/15 bg-violet-500/3"
              :title="t('appraisal.weightedSellTooltip')"
            >
              {{ t('appraisal.weightedSell') }}
            </th>
            <th v-if="props.hasStructureResults" class="px-4 py-2.5 text-center text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ t('shopping.buyAt') }}</th>
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="item in props.items"
            :key="item.typeId"
            class="border-b border-slate-800/60 hover:bg-slate-800/40 transition-colors"
          >
            <!-- Item name -->
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
            <!-- Qty -->
            <td class="px-4 py-2.5 text-right text-slate-300 font-mono">
              {{ formatNumber(item.quantity, 0) }}
            </td>
            <!-- Volume -->
            <td class="px-4 py-2.5 text-right text-slate-400 font-mono text-xs">
              {{ formatNumber(item.totalVolume) }} m<sup>3</sup>
            </td>

            <!-- Unit Price (stacked sell/buy) -->
            <td
              class="px-4 py-2.5 text-right font-mono"
              :class="props.hasStructureResults ? 'border-l-2 border-cyan-500/8 bg-cyan-500/3' : ''"
            >
              <div v-if="displaySellWeighted(item) != null">
                <!-- Sell row -->
                <div class="flex items-center justify-end gap-1.5">
                  <span
                    v-if="item.sellCoverage != null && item.sellCoverage < 1.0"
                    class="shrink-0"
                    :class="coverageColorClass(item.sellCoverage)"
                    :title="t('appraisal.coverageTooltip', { available: Math.round((item.sellCoverage ?? 1) * item.quantity).toLocaleString(), total: item.quantity.toLocaleString() })"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                  </span>
                  <span class="text-[9px] font-semibold text-cyan-700 uppercase tracking-wider">Sell</span>
                  <span class="text-cyan-300">{{ formatNumber(displaySellWeighted(item)!) }}</span>
                </div>
                <div v-if="item.sellPriceWeighted != null && item.sellPrice != null" class="text-[10px] text-slate-600 mt-0.5 text-right">
                  {{ formatNumber(item.sellPrice) }}
                </div>
                <!-- Buy row -->
                <div class="flex items-center justify-end gap-1.5 mt-1.5">
                  <span class="text-[9px] font-semibold text-amber-700 uppercase tracking-wider">Buy</span>
                  <span class="text-amber-300">{{ displayBuyWeighted(item) != null ? formatNumber(displayBuyWeighted(item)!) : '-' }}</span>
                </div>
                <div v-if="item.buyPriceWeighted != null && item.buyPrice != null" class="text-[10px] text-slate-600 mt-0.5 text-right">
                  {{ formatNumber(item.buyPrice) }}
                </div>
                <!-- Daily volume -->
                <div
                  v-if="item.avgDailyVolume != null"
                  class="text-[10px] mt-0.5"
                  :class="item.quantity > (item.avgDailyVolume ?? 0) ? 'text-amber-400/70' : 'text-slate-500'"
                >
                  {{ t('appraisal.avgDailyVolume', { volume: formatNumber(Math.round(item.avgDailyVolume), 0) }) }}
                </div>
              </div>
              <span v-else class="text-slate-500">-</span>
            </td>

            <!-- Weighted Total (stacked sell/buy) -->
            <td
              class="px-4 py-2.5 text-right font-mono"
              :class="props.hasStructureResults ? 'bg-cyan-500/3' : ''"
            >
              <div v-if="displaySellTotalWeighted(item) != null">
                <!-- Sell total -->
                <div class="flex items-center justify-end gap-1.5">
                  <span class="text-[9px] font-semibold text-cyan-700 uppercase tracking-wider">Sell</span>
                  <span class="text-cyan-400 font-semibold">{{ formatNumber(displaySellTotalWeighted(item)!) }}</span>
                </div>
                <div v-if="item.sellTotalWeighted != null && item.sellTotal != null" class="text-[10px] text-slate-600 mt-0.5 text-right">
                  {{ formatNumber(item.sellTotal) }}
                </div>
                <!-- Buy total -->
                <div class="flex items-center justify-end gap-1.5 mt-1.5">
                  <span class="text-[9px] font-semibold text-amber-700 uppercase tracking-wider">Buy</span>
                  <span class="text-amber-500 font-semibold">{{ displayBuyTotalWeighted(item) != null ? formatNumber(displayBuyTotalWeighted(item)!) : '-' }}</span>
                </div>
                <div v-if="item.buyTotalWeighted != null && item.buyTotal != null" class="text-[10px] text-slate-600 mt-0.5 text-right">
                  {{ formatNumber(item.buyTotal) }}
                </div>
              </div>
              <span v-else class="text-slate-500">-</span>
            </td>

            <!-- Jita + Import -->
            <td
              v-if="props.hasStructureResults"
              class="px-4 py-2.5 text-right font-mono border-r-2 border-cyan-500/8 bg-cyan-500/3"
            >
              <span v-if="item.jitaWithImport != null" class="text-cyan-400 font-medium">
                {{ formatNumber(item.jitaWithImport) }}
              </span>
              <span v-else class="text-slate-500">-</span>
            </td>

            <!-- Structure weighted price -->
            <td
              v-if="props.hasStructureResults"
              class="px-4 py-2.5 text-right font-mono border-l-2 border-r-2 border-violet-500/8 bg-violet-500/3"
            >
              <div v-if="item.structureTotal != null">
                <div class="flex items-center justify-end gap-1">
                  <!-- Warning icon for partial/low coverage -->
                  <svg
                    v-if="item.structureCoverage != null && item.structureCoverage < 1.0"
                    class="w-[13px] h-[13px] shrink-0"
                    :class="structurePriceColorClass(item.structureCoverage)"
                    :title="t('appraisal.coverageTooltip', { available: Math.round((item.structureCoverage ?? 1) * item.quantity).toLocaleString(), total: item.quantity.toLocaleString() })"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                  <span class="font-medium" :class="structurePriceColorClass(item.structureCoverage)">{{ formatNumber(item.structureTotal) }}</span>
                </div>
                <!-- Coverage qty display -->
                <div
                  v-if="item.structureCoverageQty != null"
                  class="flex items-center justify-end gap-1 mt-0.5"
                >
                  <span class="text-[10px]" :class="structureCoverageTextClass(item.structureCoverage)">
                    {{ formatNumber(item.structureCoverageQty, 0) }} / {{ formatNumber(item.quantity, 0) }}
                  </span>
                </div>
                <!-- Coverage bar -->
                <div
                  v-if="item.structureCoverage != null"
                  class="h-[3px] rounded-sm bg-slate-700/50 mt-1 ml-auto overflow-hidden"
                  style="width: 64px;"
                >
                  <div
                    class="h-full rounded-sm transition-all duration-300"
                    :class="structureCoverageBarClass(item.structureCoverage)"
                    :style="{ width: Math.min(item.structureCoverage * 100, 100) + '%' }"
                  ></div>
                </div>
              </div>
              <span v-else class="text-slate-600">--</span>
            </td>

            <!-- Buy at -->
            <td v-if="props.hasStructureResults" class="px-4 py-2.5 text-center">
              <span
                v-if="item.bestLocation === 'jita'"
                class="badge-best inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-cyan-500/15 text-cyan-300"
              >
                Jita
              </span>
              <span
                v-else-if="item.bestLocation === 'structure'"
                class="badge-best inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-violet-500/15 text-violet-300"
              >
                {{ props.shortStructureName }}
              </span>
              <span v-else class="text-slate-600">-</span>
            </td>
          </tr>
        </tbody>

        <!-- Footer -->
        <tfoot>
          <tr class="bg-slate-800/40 border-t border-slate-700/40">
            <td class="px-4 py-3 font-semibold text-slate-200 text-[13px]">Total</td>
            <td class="px-4 py-3 text-right text-slate-400 font-mono">--</td>
            <td class="px-4 py-3 text-right text-slate-400 font-mono text-[13px]">
              {{ formatNumber(props.totalVolume) }} m<sup>3</sup>
            </td>
            <!-- Unit Price -->
            <td
              class="px-4 py-3 text-right text-slate-500"
              :class="props.hasStructureResults ? 'border-l-2 border-cyan-500/8 bg-cyan-500/3' : ''"
            >--</td>
            <!-- Weighted Total (stacked) -->
            <td
              class="px-4 py-3 text-right font-mono"
              :class="props.hasStructureResults ? 'bg-cyan-500/3' : ''"
            >
              <div class="flex items-center justify-end gap-1.5">
                <span class="text-[9px] font-semibold text-cyan-700 uppercase tracking-wider">Sell</span>
                <span class="text-cyan-400 font-bold text-sm">{{ formatIsk(props.sellValue) }}</span>
              </div>
              <div v-if="props.hasSellTotalWeighted" class="text-[10px] text-slate-600 mt-0.5 text-right">
                {{ formatIsk(props.sellValueBest) }}
              </div>
              <div class="flex items-center justify-end gap-1.5 mt-1.5">
                <span class="text-[9px] font-semibold text-amber-700 uppercase tracking-wider">Buy</span>
                <span class="text-amber-500 font-bold text-sm">{{ formatIsk(props.buyValue) }}</span>
              </div>
              <div v-if="props.hasBuyTotalWeighted" class="text-[10px] text-slate-600 mt-0.5 text-right">
                {{ formatIsk(props.buyValueBest) }}
              </div>
            </td>
            <!-- + Import -->
            <td
              v-if="props.hasStructureResults"
              class="px-4 py-3 text-right font-mono border-r-2 border-cyan-500/8 bg-cyan-500/3"
            >
              <span class="text-cyan-400 font-bold text-sm">{{ formatIsk(props.jitaPlusTransport) }}</span>
            </td>
            <!-- Structure -->
            <td
              v-if="props.hasStructureResults"
              class="px-4 py-3 text-right font-mono border-l-2 border-r-2 border-violet-500/8 bg-violet-500/3"
            >
              <span class="text-violet-400 font-bold text-sm">{{ formatIsk(props.structureTotal) }}</span>
              <div v-if="props.lowCoverageItems > 0" class="text-[10px] text-amber-500 mt-1">
                {{ props.lowCoverageItems }} {{ t('appraisal.itemsLowCoverage') }}
              </div>
            </td>
            <!-- Best -->
            <td v-if="props.hasStructureResults" class="px-4 py-3 text-center font-mono">
              <span class="text-green-400 font-bold text-sm">{{ formatIsk(props.bestPrice) }}</span>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</template>

<style scoped>
.badge-best {
  position: relative;
  overflow: hidden;
}

.badge-best::after {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.08), transparent);
  animation: shimmer 3s infinite;
}

@keyframes shimmer {
  0% { left: -100%; }
  100% { left: 200%; }
}
</style>

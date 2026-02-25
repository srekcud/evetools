<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { StockpileShoppingItem, StockpileStage } from '@/stores/industry/types'

const props = defineProps<{
  shoppingList: StockpileShoppingItem[]
}>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()
const { formatIsk, formatNumber } = useFormatters()

const criticalOnly = ref(false)
const copied = ref(false)

const filteredItems = computed(() =>
  criticalOnly.value
    ? props.shoppingList.filter(item => item.percent < 50)
    : props.shoppingList,
)

const totalDeficitCost = computed(() =>
  filteredItems.value.reduce((sum, item) => sum + item.deficitCost, 0),
)

const deficitCount = computed(() =>
  props.shoppingList.filter(item => item.deficit > 0).length,
)

const stageBadgeClasses: Record<StockpileStage, string> = {
  raw_material: 'bg-cyan-500/10 text-cyan-400',
  intermediate: 'bg-blue-500/10 text-blue-400',
  component: 'bg-violet-500/10 text-violet-400',
  final_product: 'bg-amber-500/10 text-amber-400',
}

const stageShortNames: Record<StockpileStage, string> = {
  raw_material: 'Raw',
  intermediate: 'Intermediate',
  component: 'Component',
  final_product: 'Final',
}

function statusBadgeClasses(percent: number): string {
  if (percent < 50) return 'bg-red-500/10 text-red-400'
  if (percent < 100) return 'bg-amber-500/10 text-amber-400'
  return 'bg-emerald-500/10 text-emerald-400'
}

function deficitTextClass(percent: number): string {
  if (percent < 50) return 'text-red-400'
  return 'text-amber-400'
}

async function copyToClipboard(): Promise<void> {
  const lines = filteredItems.value
    .filter(item => item.deficit > 0)
    .map(item => `${item.typeName}\t${item.deficit}`)
    .join('\n')

  try {
    await navigator.clipboard.writeText(lines)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    // Fallback: silently fail
  }
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
        </svg>
        <h2 class="text-lg font-bold text-slate-100">{{ t('industry.stockpile.shoppingList.title') }}</h2>
        <span class="text-xs px-2 py-0.5 rounded bg-amber-500/10 text-amber-400">
          {{ t('industry.stockpile.shoppingList.itemsBelowTarget', { count: deficitCount }) }}
        </span>
      </div>
      <div class="flex items-center gap-3">
        <button
          class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg flex items-center gap-1.5 transition-colors border border-slate-700"
          @click="copyToClipboard"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
          </svg>
          <span v-if="copied">{{ t('industry.stockpile.shoppingList.copied') }}</span>
          <span v-else>{{ t('industry.stockpile.shoppingList.copyToClipboard') }}</span>
        </button>
        <button
          class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1.5 transition-colors border"
          :class="criticalOnly
            ? 'bg-red-500/10 text-red-400 border-red-500/30 hover:bg-red-500/20'
            : 'bg-slate-800 text-slate-300 border-slate-700 hover:bg-slate-700'"
          @click="criticalOnly = !criticalOnly"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
          </svg>
          {{ t('industry.stockpile.shoppingList.criticalOnly') }}
        </button>
      </div>
    </div>

    <div class="px-6 py-4">
      <table class="w-full">
        <thead>
          <tr class="text-xs uppercase tracking-wider text-slate-500">
            <th class="text-left pb-3 pl-2">{{ t('industry.stockpile.shoppingList.item') }}</th>
            <th class="text-left pb-3">{{ t('industry.stockpile.shoppingList.stage') }}</th>
            <th class="text-right pb-3">{{ t('industry.stockpile.shoppingList.have') }}</th>
            <th class="text-right pb-3">{{ t('industry.stockpile.shoppingList.need') }}</th>
            <th class="text-right pb-3">{{ t('industry.stockpile.shoppingList.deficit') }}</th>
            <th class="text-right pb-3">{{ t('industry.stockpile.shoppingList.estCost') }}</th>
            <th class="text-right pb-3 pr-2">{{ t('industry.stockpile.shoppingList.status') }}</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <tr
            v-for="item in filteredItems"
            :key="item.typeId"
            class="border-t border-slate-800/50 hover:bg-slate-800/30 transition-colors"
          >
            <td class="py-2.5 pl-2">
              <div class="flex items-center gap-2">
                <img
                  :src="getTypeIconUrl(item.typeId, 32)"
                  :alt="item.typeName"
                  class="w-6 h-6 rounded border border-slate-700"
                  @error="onImageError"
                />
                <span class="text-slate-200 font-medium">{{ item.typeName }}</span>
              </div>
            </td>
            <td>
              <span
                class="text-xs px-1.5 py-0.5 rounded"
                :class="stageBadgeClasses[item.stage]"
              >
                {{ stageShortNames[item.stage] }}
              </span>
            </td>
            <td class="text-right font-mono text-slate-400">{{ formatNumber(item.stock, 0) }}</td>
            <td class="text-right font-mono text-slate-300">{{ formatNumber(item.targetQuantity, 0) }}</td>
            <td class="text-right font-mono" :class="deficitTextClass(item.percent)">
              -{{ formatNumber(item.deficit, 0) }}
            </td>
            <td class="text-right font-mono text-slate-300">{{ formatIsk(item.deficitCost) }}</td>
            <td class="text-right pr-2">
              <span
                class="text-xs px-2 py-0.5 rounded"
                :class="statusBadgeClasses(item.percent)"
              >
                {{ item.percent }}%
              </span>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-slate-700">
            <td colspan="5" class="py-3 pl-2 text-sm font-semibold text-slate-300">
              {{ t('industry.stockpile.shoppingList.totalDeficit') }}
            </td>
            <td class="py-3 text-right font-mono text-lg font-bold text-cyan-400">
              {{ formatIsk(totalDeficitCost) }} ISK
            </td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</template>

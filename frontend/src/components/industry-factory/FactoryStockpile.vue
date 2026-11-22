<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useFormatters } from '@/composables/useFormatters'
import { useIndustryStore } from '@/stores/industry'
import { useStockpileStore } from '@/stores/industry/stockpile'
import type { StockpileItemStatus, StockpileLocation } from '@/stores/industry/types'

const FULL_PERCENT = 100

const { t } = useI18n()
const { formatNumber } = useFormatters()
const router = useRouter()
const industryStore = useIndustryStore()
const stockpileStore = useStockpileStore()

const items = computed<StockpileItemStatus[]>(() => stockpileStore.stockpileStatus?.stages.final_product.items ?? [])

function bestSuppliedStation(item: StockpileItemStatus): StockpileLocation | null {
  return item.locations.reduce<StockpileLocation | null>(
    (best, location) => (best === null || location.quantity > best.quantity ? location : best),
    null,
  )
}

function levelPercent(item: StockpileItemStatus): number {
  return Math.min(FULL_PERCENT, Math.max(0, item.percent))
}

async function createProject(item: StockpileItemStatus): Promise<void> {
  if (item.suggestedProjectRuns === null) return
  industryStore.navigationIntent = {
    target: 'projects',
    prefill: { typeId: item.typeId, typeName: item.typeName, runs: item.suggestedProjectRuns },
  }
  await router.push({ name: 'industry-projects' })
}

onMounted(() => stockpileStore.fetchStockpileStatus())
</script>

<template>
  <section data-testid="factory-stockpile" class="mb-8">
    <div class="flex items-baseline gap-3 mb-3">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('industryFactory.stockpile.title') }}</h2>
      <span class="text-sm text-slate-400">{{ t('industryFactory.stockpile.subtitle') }}</span>
    </div>

    <p v-if="stockpileStore.stockpileStatus === null && stockpileStore.error" class="text-sm text-red-400">{{ stockpileStore.error }}</p>
    <p v-else-if="stockpileStore.stockpileStatus === null" class="text-sm text-slate-400">{{ t('common.actions.loading') }}</p>
    <p v-else-if="items.length === 0" data-testid="factory-stockpile-empty" class="text-sm text-slate-400">{{ t('industryFactory.stockpile.empty') }}</p>

    <div v-else class="overflow-x-auto bg-slate-900 rounded-xl border border-slate-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
            <th class="px-3 py-2">{{ t('industryFactory.stockpile.columns.item') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.stockpile.columns.level') }}</th>
            <th class="px-3 py-2">{{ t('industryFactory.stockpile.columns.station') }}</th>
            <th class="px-3 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id" data-testid="factory-stockpile-row" class="border-t border-slate-800">
            <td class="px-3 py-2 text-slate-200">{{ item.typeName }}</td>
            <td class="px-3 py-2">
              <div class="flex items-center gap-2">
                <span class="inline-block h-1.5 w-24 rounded bg-slate-800 overflow-hidden"><i class="block h-full bg-cyan-500" :style="{ width: `${levelPercent(item)}%` }"></i></span>
                <span class="text-slate-200">{{ formatNumber(item.stock, 0) }} / {{ formatNumber(item.targetQuantity, 0) }}</span>
              </div>
            </td>
            <td class="px-3 py-2 text-slate-400">{{ bestSuppliedStation(item)?.locationName ?? t('industryFactory.stockpile.noStation') }}</td>
            <td class="px-3 py-2 text-right">
              <template v-if="item.suggestedProjectRuns !== null">
                <span class="mr-3 text-amber-400">{{ t('industryFactory.stockpile.belowTarget', { count: formatNumber(item.deficit, 0) }) }}</span>
                <button
                  type="button"
                  data-testid="factory-create-project"
                  class="px-3 py-1 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-xs font-medium transition-colors"
                  @click="createProject(item)"
                >{{ t('industryFactory.stockpile.createProject') }}</button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

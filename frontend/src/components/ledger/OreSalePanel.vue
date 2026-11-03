<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useOreSale } from '@/composables/useOreSale'
import { useToast } from '@/composables/useToast'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import IskAmount from '@/components/common/IskAmount.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const { showToast } = useToast()
const { sale, isLoading, hasError, isEmpty, summary, load } = useOreSale()

const hasStructure = computed(() => sale.value?.structureId != null)

async function copySummary(): Promise<void> {
  try {
    await navigator.clipboard.writeText(summary.value)
    showToast(t('ledger.mining.oreSale.copied'), 'success')
  } catch (e) {
    console.error('Failed to copy the ore sale summary:', e)
    showToast(t('ledger.mining.oreSale.copyFailed'), 'error')
  }
}

onMounted(load)
</script>

<template>
  <section class="space-y-3" :aria-label="t('ledger.mining.oreSale.title')">
    <div>
      <h3 class="text-lg font-semibold text-white">{{ t('ledger.mining.oreSale.title') }}</h3>
      <p class="text-sm text-slate-400">{{ t('ledger.mining.oreSale.subtitle') }}</p>
    </div>

    <ErrorBanner v-if="hasError" data-load-error :message="t('ledger.mining.oreSale.loadError')" />

    <div v-if="isLoading && sale === null" class="flex justify-center py-8">
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>

    <p v-else-if="isEmpty" data-empty-state class="text-sm text-slate-500">
      {{ t('ledger.mining.oreSale.empty') }}
    </p>

    <div v-else-if="sale !== null" class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="bg-slate-900/50">
            <tr class="text-xs font-medium text-slate-400 uppercase tracking-wider">
              <th scope="col" class="px-3 py-3 text-left">{{ t('ledger.mining.ore') }}</th>
              <th scope="col" data-column="quantity" class="px-3 py-3 text-right">{{ t('ledger.mining.oreSale.quantity') }}</th>
              <th scope="col" data-column="totalVolume" class="px-3 py-3 text-right whitespace-nowrap">{{ t('ledger.mining.oreSale.volume') }}</th>
              <th scope="col" data-column="jitaBuyPrice" class="px-3 py-3 text-right whitespace-nowrap">{{ t('ledger.mining.oreSale.jitaBuy') }}</th>
              <th scope="col" data-column="jitaSellPrice" class="px-3 py-3 text-right whitespace-nowrap">{{ t('ledger.mining.oreSale.jitaSell') }}</th>
              <th v-if="hasStructure" scope="col" data-column="structureBuyPrice" class="px-3 py-3 text-right whitespace-nowrap">{{ t('ledger.mining.oreSale.structureBuy') }}</th>
              <th v-if="hasStructure" scope="col" data-column="structureSellPrice" class="px-3 py-3 text-right whitespace-nowrap">{{ t('ledger.mining.oreSale.structureSell') }}</th>
              <th scope="col" data-column="bestPrice" class="px-3 py-3 text-right whitespace-nowrap">{{ t('ledger.mining.oreSale.bestPrice') }}</th>
              <th scope="col" data-column="bestSource" class="px-3 py-3 text-left whitespace-nowrap">{{ t('ledger.mining.oreSale.bestSource') }}</th>
              <th scope="col" data-column="totalValue" class="px-3 py-3 text-right whitespace-nowrap">{{ t('ledger.mining.oreSale.value') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr v-for="row in sale.rows" :key="row.typeId" :data-type-id="row.typeId" class="hover:bg-slate-900/30 transition-colors text-sm">
              <td class="px-3 py-2 min-w-0 max-w-[16rem]">
                <div class="flex items-center gap-2 min-w-0">
                  <img :src="getTypeIconUrl(row.typeId)" alt="" class="w-5 h-5 shrink-0" @error="onImageError" />
                  <span class="text-white truncate" :title="row.typeName ?? String(row.typeId)">{{ row.typeName ?? row.typeId }}</span>
                </div>
              </td>
              <td data-column="quantity" class="px-3 py-2 text-right text-slate-300 whitespace-nowrap">{{ formatNumber(row.quantity, 0) }}</td>
              <td data-column="totalVolume" class="px-3 py-2 text-right text-slate-300 whitespace-nowrap">
                <UnknownValue v-if="row.totalVolume === null" />
                <template v-else>{{ formatNumber(row.totalVolume) }}</template>
              </td>
              <td data-column="jitaBuyPrice" class="px-3 py-2 text-right text-slate-300 whitespace-nowrap">
                <UnknownValue v-if="row.jitaBuyPrice === null" />
                <template v-else>{{ formatNumber(row.jitaBuyPrice) }}</template>
              </td>
              <td data-column="jitaSellPrice" class="px-3 py-2 text-right text-slate-300 whitespace-nowrap">
                <UnknownValue v-if="row.jitaSellPrice === null" />
                <template v-else>{{ formatNumber(row.jitaSellPrice) }}</template>
              </td>
              <td v-if="hasStructure" data-column="structureBuyPrice" class="px-3 py-2 text-right text-slate-300 whitespace-nowrap">
                <UnknownValue v-if="row.structureBuyPrice === null" />
                <template v-else>{{ formatNumber(row.structureBuyPrice) }}</template>
              </td>
              <td v-if="hasStructure" data-column="structureSellPrice" class="px-3 py-2 text-right text-slate-300 whitespace-nowrap">
                <UnknownValue v-if="row.structureSellPrice === null" />
                <template v-else>{{ formatNumber(row.structureSellPrice) }}</template>
              </td>
              <td data-column="bestPrice" class="px-3 py-2 text-right text-cyan-300 font-semibold whitespace-nowrap">
                <UnknownValue v-if="row.bestPrice === null" />
                <template v-else>{{ formatNumber(row.bestPrice) }}</template>
              </td>
              <td data-column="bestSource" class="px-3 py-2 text-xs uppercase tracking-wider text-cyan-400 whitespace-nowrap">
                <UnknownValue v-if="row.bestSource === null" />
                <span v-else :data-best-source="row.bestSource"><span aria-hidden="true">▲ </span>{{ t(`ledger.mining.oreSale.sources.${row.bestSource}`) }}</span>
              </td>
              <td data-column="totalValue" class="px-3 py-2 text-right text-white font-semibold whitespace-nowrap">
                <IskAmount :value="row.totalValue" />
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t border-slate-700 bg-slate-900/50 text-sm">
            <tr>
              <th scope="row" class="px-3 py-2 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">{{ t('ledger.mining.oreSale.totals') }}</th>
              <td data-total="quantity" class="px-3 py-2 text-right text-slate-200 whitespace-nowrap">{{ formatNumber(sale.totals.totalQuantity, 0) }}</td>
              <td data-total="volume" class="px-3 py-2 text-right text-slate-200 whitespace-nowrap">
                <UnknownValue v-if="sale.totals.totalVolume === null" />
                <template v-else>{{ formatNumber(sale.totals.totalVolume) }}</template>
              </td>
              <td :colspan="hasStructure ? 6 : 4"></td>
              <td data-total="value" class="px-3 py-2 text-right text-white font-semibold whitespace-nowrap">
                <IskAmount :value="sale.totals.totalValue" />
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div v-if="sale !== null && !isEmpty" class="space-y-2">
      <pre data-sale-summary class="p-3 rounded-sm border border-slate-700 bg-slate-950 text-xs text-slate-300 whitespace-pre-wrap break-words">{{ summary }}</pre>
    </div>

    <button
      type="button"
      data-copy-summary
      :disabled="sale === null || isEmpty"
      class="px-3 py-1 rounded-sm border border-cyan-500/60 bg-cyan-500/10 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
      @click="copySummary"
    >
      {{ t('ledger.mining.oreSale.copy') }}
    </button>
  </section>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useStockpileStore } from '@/stores/industry/stockpile'
import type { SearchResult } from '@/stores/industry/types'
import ProductSearch from '@/components/industry/ProductSearch.vue'

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const stockpileStore = useStockpileStore()

const selectedProduct = ref<SearchResult | null>(null)
const runs = ref(1)
const me = ref(10)
const te = ref(20)

const STAGE_ORDER = ['raw_material', 'intermediate', 'component', 'final_product'] as const

const stageColors: Record<string, string> = {
  raw_material: 'bg-cyan-400',
  intermediate: 'bg-blue-400',
  component: 'bg-violet-400',
  final_product: 'bg-amber-400',
}

const stageNames = computed(() => ({
  raw_material: t('industry.stockpile.stages.raw_material'),
  intermediate: t('industry.stockpile.stages.intermediate'),
  component: t('industry.stockpile.stages.component'),
  final_product: t('industry.stockpile.stages.final_product'),
}))

const previewStageCount = computed(() => {
  if (!stockpileStore.importPreview) return 0
  return STAGE_ORDER.filter(
    s => (stockpileStore.importPreview!.stages[s]?.length ?? 0) > 0,
  ).length
})

function onProductSelect(result: SearchResult): void {
  selectedProduct.value = result
}

async function calculate(): Promise<void> {
  if (!selectedProduct.value) return
  await stockpileStore.previewImport(
    selectedProduct.value.typeId,
    runs.value,
    me.value,
    te.value,
  )
}

async function doImport(mode: 'replace' | 'merge'): Promise<void> {
  if (!selectedProduct.value) return
  await stockpileStore.importFromBlueprint(
    selectedProduct.value.typeId,
    runs.value,
    me.value,
    te.value,
    mode,
  )
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
        <h2 class="text-lg font-bold text-slate-100">{{ t('industry.stockpile.importTitle') }}</h2>
      </div>
    </div>

    <div class="p-6">
      <div class="grid grid-cols-12 gap-6">
        <!-- Left: Product selection -->
        <div class="col-span-12 lg:col-span-5">
          <div class="space-y-4">
            <div>
              <label class="block text-xs uppercase tracking-wider text-slate-500 mb-2">
                {{ t('industry.stockpile.import.product') }}
              </label>
              <ProductSearch @select="onProductSelect" />
              <div v-if="selectedProduct" class="mt-2 text-xs text-slate-400">
                {{ selectedProduct.typeName }}
                <span v-if="selectedProduct.isT2" class="ml-1 px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded-sm text-[10px] font-semibold uppercase">T2</span>
              </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="block text-xs uppercase tracking-wider text-slate-500 mb-2">
                  {{ t('industry.stockpile.import.runs') }}
                </label>
                <input
                  v-model.number="runs"
                  type="number"
                  min="1"
                  class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-sm rounded-lg px-3 py-2.5 font-mono focus:outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/30"
                />
              </div>
              <div>
                <label class="block text-xs uppercase tracking-wider text-slate-500 mb-2">
                  {{ t('industry.stockpile.import.me') }}
                </label>
                <input
                  v-model.number="me"
                  type="number"
                  min="0"
                  max="10"
                  class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-sm rounded-lg px-3 py-2.5 font-mono focus:outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/30"
                />
              </div>
              <div>
                <label class="block text-xs uppercase tracking-wider text-slate-500 mb-2">
                  {{ t('industry.stockpile.import.te') }}
                </label>
                <input
                  v-model.number="te"
                  type="number"
                  min="0"
                  max="20"
                  class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-sm rounded-lg px-3 py-2.5 font-mono focus:outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/30"
                />
              </div>
            </div>
            <button
              :disabled="!selectedProduct || stockpileStore.importLoading"
              class="w-full px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-700 disabled:text-slate-500 text-white text-sm font-semibold rounded-lg flex items-center justify-center gap-2 transition-colors"
              @click="calculate"
            >
              <svg v-if="stockpileStore.importLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
              {{ t('industry.stockpile.import.calculate') }}
            </button>
          </div>
        </div>

        <!-- Right: Calculated preview -->
        <div class="col-span-12 lg:col-span-7">
          <div class="bg-slate-800 rounded-lg p-4 h-full">
            <div v-if="stockpileStore.importPreview" class="h-full flex flex-col">
              <div class="flex items-center justify-between mb-3">
                <span class="text-xs uppercase tracking-wider text-slate-500">
                  {{ t('industry.stockpile.import.preview') }}: {{ selectedProduct?.typeName }} x{{ runs }} (ME {{ me }})
                </span>
                <span class="text-xs font-mono text-slate-500">
                  {{ t('industry.stockpile.import.itemsAcrossStages', {
                    count: stockpileStore.importPreview.totalItems,
                    stages: previewStageCount,
                  }) }}
                </span>
              </div>

              <!-- Preview table by stage -->
              <div class="space-y-3 flex-1 overflow-y-auto">
                <template v-for="(stage, idx) in STAGE_ORDER" :key="stage">
                  <div v-if="(stockpileStore.importPreview.stages[stage]?.length ?? 0) > 0">
                    <div v-if="idx > 0" class="border-t border-slate-700 mb-3"></div>
                    <div class="flex items-center gap-2 mb-2">
                      <div class="w-1.5 h-1.5 rounded-full" :class="stageColors[stage]"></div>
                      <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                        {{ stageNames[stage] }}
                      </span>
                    </div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                      <div
                        v-for="item in stockpileStore.importPreview.stages[stage]"
                        :key="item.typeId"
                        class="flex justify-between"
                      >
                        <span class="text-slate-400">{{ item.typeName }}</span>
                        <span class="font-mono text-slate-300">{{ formatNumber(item.quantity, 0) }}</span>
                      </div>
                    </div>
                  </div>
                </template>
              </div>

              <!-- Action buttons -->
              <div class="flex items-center gap-3 mt-4 pt-3 border-t border-slate-700">
                <button
                  :disabled="stockpileStore.importLoading"
                  class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-700 disabled:text-slate-500 text-white text-sm font-semibold rounded-lg flex items-center gap-2 transition-colors"
                  @click="doImport('replace')"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  {{ t('industry.stockpile.import.addToStockpile') }}
                </button>
                <button
                  :disabled="stockpileStore.importLoading"
                  class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-slate-300 text-sm font-medium rounded-lg flex items-center gap-2 transition-colors"
                  @click="doImport('merge')"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                  </svg>
                  {{ t('industry.stockpile.import.mergeWithExisting') }}
                </button>
                <div class="flex-1"></div>
                <span class="text-xs font-mono text-slate-500">
                  {{ t('industry.stockpile.import.estTotalCost') }}:
                  <span class="text-cyan-400">{{ formatIsk(stockpileStore.importPreview.estimatedCost) }} ISK</span>
                </span>
              </div>
            </div>

            <!-- Empty preview state -->
            <div v-else class="h-full flex items-center justify-center min-h-[200px]">
              <p class="text-sm text-slate-500">
                {{ t('industry.stockpile.import.selectProduct') }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

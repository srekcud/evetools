<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { StockpileStage, StockpileStageStatus } from '@/stores/industry/types'

const props = defineProps<{
  stage: StockpileStage
  stageData: StockpileStageStatus
  isLastColumn: boolean
  stageColor: string
  stageName: string
}>()

const emit = defineEmits<{
  deleteTarget: [targetId: string]
}>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()
const { formatIsk, formatNumber } = useFormatters()

const VISIBLE_ITEMS = 6
const expanded = ref(false)

const visibleItems = computed(() =>
  expanded.value ? props.stageData.items : props.stageData.items.slice(0, VISIBLE_ITEMS),
)

const hasMore = computed(() => props.stageData.items.length > VISIBLE_ITEMS)

const healthColor = computed(() => {
  const h = props.stageData.healthPercent
  if (h >= 80) return 'text-emerald-400'
  if (h >= 50) return 'text-amber-400'
  return 'text-red-400'
})

const healthBarColor = computed(() => {
  const h = props.stageData.healthPercent
  if (h >= 80) return 'bg-emerald-400'
  if (h >= 50) return 'bg-amber-400'
  return 'bg-red-400'
})

const dotColor = computed(() => {
  const colorMap: Record<string, string> = {
    cyan: 'bg-cyan-400',
    blue: 'bg-blue-400',
    violet: 'bg-violet-400',
    amber: 'bg-amber-400',
  }
  return colorMap[props.stageColor] ?? 'bg-slate-400'
})

function itemBadgeClasses(percent: number): string {
  if (percent >= 100) return 'bg-emerald-500/10 text-emerald-400'
  if (percent >= 50) return 'bg-amber-500/10 text-amber-400'
  return 'bg-red-500/10 text-red-400'
}

function itemBarColor(percent: number): string {
  if (percent >= 100) return 'bg-emerald-400'
  if (percent >= 50) return 'bg-amber-400'
  return 'bg-red-400'
}

const isFinalProduct = computed(() => props.stage === 'final_product')

function onDeleteTarget(targetId: string): void {
  emit('deleteTarget', targetId)
}
</script>

<template>
  <div
    class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden"
    :class="{ 'pipeline-arrow': !isLastColumn }"
  >
    <!-- Column header -->
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <div class="w-2 h-2 rounded-full" :class="dotColor"></div>
        <h2 class="text-sm font-bold text-slate-100 uppercase tracking-wider">{{ stageName }}</h2>
      </div>
      <span class="text-xs font-mono text-slate-500">{{ formatIsk(stageData.totalValue) }} ISK</span>
    </div>

    <!-- Stage health bar -->
    <div class="px-4 pt-3 pb-1">
      <div class="flex items-center justify-between mb-1">
        <span class="text-xs text-slate-500">{{ t('industry.stockpile.stages.stageCompletion') }}</span>
        <span class="text-xs font-mono" :class="healthColor">{{ stageData.healthPercent }}%</span>
      </div>
      <div class="h-1.5 rounded-full bg-slate-800 overflow-hidden">
        <div
          class="h-full rounded-full transition-all duration-600"
          :class="healthBarColor"
          :style="{ width: `${Math.min(stageData.healthPercent, 100)}%` }"
        ></div>
      </div>
    </div>

    <!-- Items list -->
    <div class="p-3 space-y-1.5">
      <div
        v-for="item in visibleItems"
        :key="item.typeId"
        class="group/item rounded-lg px-3 py-2.5 transition-colors cursor-default hover:bg-slate-800/50"
      >
        <div class="flex items-center gap-3">
          <img
            :src="getTypeIconUrl(item.typeId, 32)"
            :alt="item.typeName"
            class="w-8 h-8 rounded border border-slate-700 bg-slate-800"
            @error="onImageError"
          />
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between mb-1">
              <span class="text-sm font-semibold text-slate-200 truncate" :title="item.typeName">
                {{ item.typeName }}
              </span>
              <div class="flex items-center gap-1.5">
                <button
                  class="text-slate-600 hover:text-red-400 opacity-0 group-hover/item:opacity-100 lg:opacity-0 max-lg:opacity-100 transition-opacity p-0.5"
                  :title="t('common.actions.delete')"
                  @click="onDeleteTarget(item.id)"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
                <span
                  class="text-xs px-1.5 py-0.5 rounded font-mono"
                  :class="itemBadgeClasses(item.percent)"
                >
                  {{ item.percent }}%
                </span>
              </div>
            </div>
            <div class="h-2 rounded-full bg-slate-800 overflow-hidden mb-1">
              <div
                class="h-full rounded-full transition-all duration-600"
                :class="itemBarColor(item.percent)"
                :style="{ width: `${Math.min(item.percent, 100)}%` }"
              ></div>
            </div>
            <div class="flex items-center justify-between">
              <div class="relative group/loc font-mono text-xs text-slate-500" :class="{ 'cursor-help underline decoration-dotted decoration-slate-600': item.locations?.length > 1 }">
                {{ formatNumber(item.stock, 0) }} / {{ formatNumber(item.targetQuantity, 0) }}
                <!-- Location tooltip -->
                <div
                  v-if="item.locations?.length > 0"
                  class="hidden group-hover/loc:block absolute bottom-full left-0 mb-1 z-50 bg-slate-800 border border-slate-700 rounded-lg shadow-xl p-2.5 min-w-[200px] max-w-[300px]"
                >
                  <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1.5">{{ t('industry.stockpile.locationBreakdown') }}</p>
                  <div
                    v-for="loc in item.locations"
                    :key="loc.locationId"
                    class="flex items-center justify-between gap-3 py-0.5 text-xs"
                  >
                    <span class="text-slate-300 truncate">{{ loc.locationName }}</span>
                    <span class="text-slate-400 font-mono shrink-0">{{ formatNumber(loc.quantity, 0) }}</span>
                  </div>
                </div>
              </div>
              <!-- Final product status -->
              <span
                v-if="isFinalProduct && item.percent >= 100"
                class="text-xs text-emerald-400 flex items-center gap-1"
              >
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                {{ t('industry.stockpile.finalProduct.ready') }}
              </span>
              <span
                v-else-if="isFinalProduct"
                class="text-xs text-red-400 flex items-center gap-1"
              >
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01" />
                </svg>
                {{ t('industry.stockpile.finalProduct.missingMaterials') }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Show more link -->
      <div v-if="hasMore" class="pt-1 px-3">
        <button
          class="text-xs text-cyan-400 hover:text-cyan-300 flex items-center gap-1"
          @click="expanded = !expanded"
        >
          <span>
            {{ t('industry.stockpile.stages.showAll', { count: stageData.items.length }) }}
          </span>
          <svg
            class="w-3 h-3 transition-transform"
            :class="{ 'rotate-180': expanded }"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pipeline-arrow {
  position: relative;
}
.pipeline-arrow::after {
  content: '';
  position: absolute;
  top: 50%;
  right: -14px;
  width: 28px;
  height: 2px;
  background: linear-gradient(90deg, #1e293b, #0891b2, #1e293b);
  transform: translateY(-50%);
}
.pipeline-arrow::before {
  content: '';
  position: absolute;
  top: 50%;
  right: -18px;
  width: 8px;
  height: 8px;
  border-top: 2px solid #0891b2;
  border-right: 2px solid #0891b2;
  transform: translateY(-50%) rotate(45deg);
}

@media (max-width: 1024px) {
  .pipeline-arrow::after,
  .pipeline-arrow::before {
    display: none;
  }
}
</style>

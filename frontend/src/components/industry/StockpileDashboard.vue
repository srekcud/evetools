<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useStockpileStore } from '@/stores/industry/stockpile'
import type { StockpileStage } from '@/stores/industry/types'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import StockpileColumn from '@/components/industry/StockpileColumn.vue'
import StockpileImportPanel from '@/components/industry/StockpileImportPanel.vue'
import StockpileShoppingList from '@/components/industry/StockpileShoppingList.vue'

const { t } = useI18n()
const { formatIsk } = useFormatters()
const stockpileStore = useStockpileStore()

const STAGE_ORDER: StockpileStage[] = ['raw_material', 'intermediate', 'component', 'final_product']

const stageNames = computed(() => ({
  raw_material: t('industry.stockpile.stages.raw_material'),
  intermediate: t('industry.stockpile.stages.intermediate'),
  component: t('industry.stockpile.stages.component'),
  final_product: t('industry.stockpile.stages.final_product'),
}))

const stageColors: Record<StockpileStage, string> = {
  raw_material: 'cyan',
  intermediate: 'blue',
  component: 'violet',
  final_product: 'amber',
}

// Health ring SVG constants
const RING_RADIUS = 24
const CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS // ~150.8

const pipelineHealth = computed(() => stockpileStore.stockpileStatus?.kpis.pipelineHealth ?? 0)

const dashOffset = computed(() => CIRCUMFERENCE * (1 - pipelineHealth.value / 100))

const healthRingColor = computed(() => {
  if (pipelineHealth.value >= 80) return '#34d399' // emerald-400
  if (pipelineHealth.value >= 50) return '#fbbf24' // amber-400
  return '#f87171' // red-400
})

const healthTextColor = computed(() => {
  if (pipelineHealth.value >= 80) return 'text-emerald-400'
  if (pipelineHealth.value >= 50) return 'text-amber-400'
  return 'text-red-400'
})

const kpis = computed(() => stockpileStore.stockpileStatus?.kpis)
const targetCount = computed(() => stockpileStore.stockpileStatus?.targetCount ?? 0)
const hasTargets = computed(() => targetCount.value > 0)

const targetsMet = computed(() => {
  if (!stockpileStore.stockpileStatus) return 0
  return Object.values(stockpileStore.stockpileStatus.stages)
    .flatMap(s => s.items)
    .filter(i => i.percent >= 100).length
})

const totalTargets = computed(() => {
  if (!stockpileStore.stockpileStatus) return 0
  return Object.values(stockpileStore.stockpileStatus.stages)
    .flatMap(s => s.items).length
})

const hasBottleneck = computed(() => kpis.value?.bottleneck != null)

const shoppingItems = computed(() =>
  stockpileStore.stockpileStatus?.shoppingList.filter(i => i.deficit > 0) ?? [],
)

const clearing = ref(false)
const showConfirmModal = ref(false)
const pendingAction = ref<(() => Promise<void>) | null>(null)
const confirmTitle = ref('')
const confirmMessage = ref<string | undefined>(undefined)

function requestDeleteTarget(targetId: string): void {
  confirmTitle.value = t('industry.stockpile.confirmDelete')
  confirmMessage.value = undefined
  pendingAction.value = async () => {
    await stockpileStore.deleteTarget(targetId)
  }
  showConfirmModal.value = true
}

function requestClearAll(): void {
  confirmTitle.value = t('industry.stockpile.confirmClearAll', { count: targetCount.value })
  confirmMessage.value = undefined
  pendingAction.value = async () => {
    clearing.value = true
    await stockpileStore.clearAllTargets()
    clearing.value = false
  }
  showConfirmModal.value = true
}

async function onConfirmAction(): Promise<void> {
  await pendingAction.value?.()
  showConfirmModal.value = false
  pendingAction.value = null
}

function onCancelAction(): void {
  showConfirmModal.value = false
  pendingAction.value = null
}

onMounted(async () => {
  await stockpileStore.fetchStockpileStatus()
})
</script>

<template>
  <!-- Loading state -->
  <div v-if="stockpileStore.statusLoading && !stockpileStore.stockpileStatus" class="space-y-6">
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-8 flex items-center justify-center">
      <div class="flex items-center gap-3 text-slate-400">
        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span class="text-sm">{{ t('common.actions.loading') }}</span>
      </div>
    </div>
  </div>

  <!-- Error state -->
  <div v-else-if="stockpileStore.error && !stockpileStore.stockpileStatus" class="space-y-6">
    <div class="bg-slate-900 rounded-xl border border-red-800/30 p-6 text-center">
      <p class="text-red-400 text-sm">{{ stockpileStore.error }}</p>
      <button
        class="mt-3 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm rounded-lg transition-colors"
        @click="stockpileStore.fetchStockpileStatus()"
      >
        {{ t('common.actions.retry') }}
      </button>
    </div>
  </div>

  <!-- Empty state: no targets -->
  <div v-else-if="!hasTargets && !stockpileStore.statusLoading" class="space-y-6">
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-8 text-center">
      <svg class="w-12 h-12 text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
      </svg>
      <h3 class="text-lg font-semibold text-slate-300 mb-2">{{ t('industry.stockpile.empty.title') }}</h3>
      <p class="text-sm text-slate-500 mb-4">{{ t('industry.stockpile.empty.description') }}</p>
    </div>

    <!-- Import panel always shown to allow importing -->
    <StockpileImportPanel />
  </div>

  <!-- Full dashboard with targets -->
  <div v-else class="space-y-6">
    <!-- Page Header Card -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          <h1 class="text-2xl font-bold text-slate-100">{{ t('industry.stockpile.title') }}</h1>
          <span class="text-xs px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-400 font-medium">
            {{ targetCount }} {{ t('industry.stockpile.targets') }}
          </span>
        </div>
        <button
          class="text-xs text-slate-500 hover:text-red-400 transition-colors"
          :disabled="clearing"
          @click="requestClearAll"
        >
          {{ clearing ? t('common.actions.loading') : t('industry.stockpile.clearAll') }}
        </button>
      </div>

      <!-- KPI Summary Bar -->
      <div class="px-6 py-4" v-if="kpis">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- KPI 1: Pipeline Health -->
          <div class="bg-slate-800 rounded-lg p-4 flex items-center gap-4">
            <div class="flex-shrink-0 relative">
              <svg viewBox="0 0 56 56" class="w-14 h-14" style="transform: rotate(-90deg)">
                <circle cx="28" cy="28" :r="RING_RADIUS" fill="none" stroke="#1e293b" stroke-width="4" />
                <circle
                  cx="28" cy="28" :r="RING_RADIUS" fill="none"
                  :stroke="healthRingColor" stroke-width="4"
                  :stroke-dasharray="CIRCUMFERENCE"
                  :stroke-dashoffset="dashOffset"
                  stroke-linecap="round"
                />
              </svg>
              <div class="absolute inset-0 flex items-center justify-center">
                <span class="font-mono text-sm font-bold" :class="healthTextColor">
                  {{ pipelineHealth }}%
                </span>
              </div>
            </div>
            <div>
              <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">
                {{ t('industry.stockpile.kpi.pipelineHealth') }}
              </div>
              <div class="text-lg font-bold" :class="healthTextColor">{{ pipelineHealth }}%</div>
              <div class="text-xs text-slate-500">
                {{ t('industry.stockpile.kpi.targetsMet', { met: targetsMet, total: totalTargets }) }}
              </div>
            </div>
          </div>

          <!-- KPI 2: Total Invested -->
          <div class="bg-slate-800 rounded-lg p-4 flex items-center gap-4">
            <div class="w-14 h-14 rounded-lg bg-cyan-500/10 flex items-center justify-center flex-shrink-0">
              <svg class="w-7 h-7 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div>
              <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">
                {{ t('industry.stockpile.kpi.totalInvested') }}
              </div>
              <div class="text-lg font-bold text-cyan-400 font-mono">{{ formatIsk(kpis.totalInvested) }} ISK</div>
              <div class="text-xs text-slate-500">{{ t('industry.stockpile.kpi.acrossStages') }}</div>
            </div>
          </div>

          <!-- KPI 3: Bottleneck -->
          <div
            class="bg-slate-800 rounded-lg p-4 flex items-center gap-4"
            :class="{ 'bottleneck-highlight': hasBottleneck }"
          >
            <div
              class="w-14 h-14 rounded-lg flex items-center justify-center flex-shrink-0"
              :class="hasBottleneck ? 'bg-red-500/10' : 'bg-emerald-500/10'"
            >
              <svg
                v-if="hasBottleneck"
                class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
              </svg>
              <svg
                v-else
                class="w-7 h-7 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <div>
              <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">
                {{ t('industry.stockpile.kpi.bottleneck') }}
              </div>
              <div v-if="hasBottleneck" class="text-lg font-bold text-red-400">
                {{ kpis!.bottleneck!.typeName }}
              </div>
              <div v-if="hasBottleneck" class="text-xs text-red-400/70">
                {{ t('industry.stockpile.kpi.blocksProducts', {
                  percent: kpis!.bottleneck!.percent,
                  count: kpis!.bottleneck!.blocksProducts,
                }) }}
              </div>
              <div v-else class="text-lg font-bold text-emerald-400">
                {{ t('industry.stockpile.kpi.noBottleneck') }}
              </div>
            </div>
          </div>

          <!-- KPI 4: Est. Output -->
          <div class="bg-slate-800 rounded-lg p-4 flex items-center gap-4">
            <div class="w-14 h-14 rounded-lg bg-emerald-500/10 flex items-center justify-center flex-shrink-0">
              <svg class="w-7 h-7 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
              </svg>
            </div>
            <div>
              <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">
                {{ t('industry.stockpile.kpi.estOutput') }}
              </div>
              <div class="text-lg font-bold text-emerald-400 font-mono">
                {{ kpis!.estOutput.ready }} / {{ kpis!.estOutput.total }}
              </div>
              <div class="text-xs text-slate-500">
                {{ kpis!.estOutput.readyNames?.length > 0
                  ? kpis!.estOutput.readyNames.join(', ') + ' ' + t('industry.stockpile.kpi.buildable')
                  : t('industry.stockpile.kpi.buildable')
                }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Pipeline Columns -->
    <div
      v-if="stockpileStore.stockpileStatus"
      class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5"
    >
      <StockpileColumn
        v-for="(stage, idx) in STAGE_ORDER"
        :key="stage"
        :stage="stage"
        :stage-data="stockpileStore.stockpileStatus.stages[stage]"
        :is-last-column="idx === STAGE_ORDER.length - 1"
        :stage-color="stageColors[stage]"
        :stage-name="stageNames[stage]"
        @delete-target="requestDeleteTarget"
      />
    </div>

    <!-- Import Panel -->
    <StockpileImportPanel />

    <!-- Shopping List -->
    <StockpileShoppingList
      v-if="shoppingItems.length > 0"
      :shopping-list="shoppingItems"
    />

    <!-- Confirm Modal (delete target / clear all) -->
    <ConfirmModal
      :show="showConfirmModal"
      :title="confirmTitle"
      :message="confirmMessage"
      :confirm-label="t('common.actions.delete')"
      confirm-color="red"
      icon="delete"
      @confirm="onConfirmAction"
      @cancel="onCancelAction"
    />
  </div>
</template>

<style scoped>
@keyframes bottleneck-pulse {
  0%, 100% { box-shadow: inset 0 0 0 1px rgba(248,113,113,0.15); }
  50% { box-shadow: inset 0 0 0 1px rgba(248,113,113,0.35); }
}
.bottleneck-highlight {
  animation: bottleneck-pulse 3s ease-in-out infinite;
}
</style>

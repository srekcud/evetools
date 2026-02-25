<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useScannerStore } from '@/stores/industry/scanner'
import { useIndustryStore, type SearchResult } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import type { BuyVsBuildComponent } from '@/stores/industry/types'

defineProps<{
  initialTypeId?: number
}>()

const { t } = useI18n()
const scannerStore = useScannerStore()
const industryStore = useIndustryStore()
const { formatIsk, formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

// Product selector
const selectedProduct = ref<SearchResult | null>(null)
const productSearchRef = ref<{ clear: () => void } | null>(null)
const runs = ref(1)
const meLevel = ref(2)
const selectedSystemId = ref<number | null>(null)

// UI state
const stageFilter = ref<'all' | 'T2 Component' | 'T1 Component' | 'Reaction'>('all')
const userOverrides = ref<Map<number, 'build' | 'buy'>>(new Map())

// Favorite systems from user settings
const favoriteSystems = computed(() => {
  const settings = industryStore.userSettings
  if (!settings) return []
  const systems: { id: number; name: string }[] = []
  if (settings.favoriteManufacturingSystemId != null && settings.favoriteManufacturingSystemName != null) {
    systems.push({ id: settings.favoriteManufacturingSystemId, name: settings.favoriteManufacturingSystemName })
  }
  if (settings.favoriteReactionSystemId != null && settings.favoriteReactionSystemName != null) {
    if (!systems.find(s => s.id === settings.favoriteReactionSystemId)) {
      systems.push({ id: settings.favoriteReactionSystemId!, name: settings.favoriteReactionSystemName! })
    }
  }
  return systems
})

function onProductSelect(result: SearchResult): void {
  selectedProduct.value = result
  if (result.isT2) {
    meLevel.value = 2
  }
}

async function onAnalyze(): Promise<void> {
  if (!selectedProduct.value) return
  await scannerStore.fetchBuyVsBuild(
    selectedProduct.value.typeId,
    runs.value,
    meLevel.value,
    selectedSystemId.value,
  )
}

// Result data
const result = computed(() => scannerStore.buyVsBuildResult)
const loading = computed(() => scannerStore.buyVsBuildLoading)
const error = computed(() => scannerStore.buyVsBuildError)

watch(result, () => {
  userOverrides.value = new Map()
})

function effectiveVerdict(comp: BuyVsBuildComponent): 'build' | 'buy' | 'loss' {
  return userOverrides.value.get(comp.typeId) ?? comp.verdict
}

function setOverride(comp: BuyVsBuildComponent, choice: 'build' | 'buy'): void {
  const newMap = new Map(userOverrides.value)
  if (choice === comp.verdict) {
    newMap.delete(comp.typeId)
  } else {
    newMap.set(comp.typeId, choice)
  }
  userOverrides.value = newMap
}

function isOverridden(typeId: number): boolean {
  return userOverrides.value.has(typeId)
}

// Filtered components by stage
const filteredComponents = computed((): BuyVsBuildComponent[] => {
  if (!result.value) return []
  if (stageFilter.value === 'all') return result.value.components
  return result.value.components.filter(c => c.stage === stageFilter.value)
})

// Stage counts
const stageCounts = computed(() => {
  if (!result.value) return { all: 0, t2: 0, t1: 0, reaction: 0 }
  const comps = result.value.components
  return {
    all: comps.length,
    t2: comps.filter(c => c.stage === 'T2 Component').length,
    t1: comps.filter(c => c.stage === 'T1 Component').length,
    reaction: comps.filter(c => c.stage === 'Reaction').length,
  }
})

// Verdict counts
const verdictCounts = computed(() => {
  if (!result.value) return { build: 0, buy: 0, loss: 0 }
  const comps = result.value.components
  return {
    build: comps.filter(c => effectiveVerdict(c) === 'build').length,
    buy: comps.filter(c => effectiveVerdict(c) === 'buy').length,
    loss: comps.filter(c => effectiveVerdict(c) === 'loss').length,
  }
})

// Group components by stage for section headers
const groupedComponents = computed(() => {
  const groups: { stage: string; components: BuyVsBuildComponent[] }[] = []
  const stageOrder = ['T2 Component', 'T1 Component', 'Reaction']

  for (const stage of stageOrder) {
    const comps = filteredComponents.value.filter(c => c.stage === stage)
    if (comps.length > 0) {
      groups.push({ stage, components: comps })
    }
  }
  return groups
})

// Build/Buy lists for summary
const buildList = computed(() => {
  if (!result.value) return []
  return result.value.components
    .filter(c => effectiveVerdict(c) === 'build')
    .sort((a, b) => b.savings - a.savings)
})

const buyList = computed(() => {
  if (!result.value) return []
  return result.value.components
    .filter(c => effectiveVerdict(c) === 'buy' || effectiveVerdict(c) === 'loss')
    .sort((a, b) => b.savings - a.savings)
})

const buildTotalSavings = computed(() => buildList.value.reduce((sum, c) => sum + c.savings, 0))
const buyTotalSavings = computed(() => buyList.value.reduce((sum, c) => sum + Math.abs(c.savings), 0))

const hasOverrides = computed(() => userOverrides.value.size > 0)

const overrideCounts = computed(() => {
  let build = 0, buy = 0
  for (const v of userOverrides.value.values()) {
    if (v === 'build') build++; else buy++
  }
  return { total: userOverrides.value.size, build, buy }
})

const computedOptimalMixCost = computed(() => {
  if (!result.value) return 0
  return result.value.components.reduce((sum, c) => {
    const v = effectiveVerdict(c)
    const buyCost = c.buyCostStructure ?? c.buyCostJita ?? 0
    return sum + (v === 'build' ? c.buildCost : buyCost)
  }, 0)
})

const excludedComponentNames = computed(() => {
  if (!result.value) return []
  return result.value.components
    .filter(c => effectiveVerdict(c) !== 'build')
    .map(c => c.typeName)
})

// Savings comparison
const savingsVsBuildAll = computed(() => {
  if (!result.value) return { amount: 0, percent: 0 }
  const diff = result.value.buildAllCost - computedOptimalMixCost.value
  const pct = result.value.buildAllCost > 0 ? (diff / result.value.buildAllCost) * 100 : 0
  return { amount: diff, percent: pct }
})

const savingsVsBuyAll = computed(() => {
  if (!result.value) return { amount: 0, percent: 0 }
  const diff = result.value.buyAllCost - computedOptimalMixCost.value
  const pct = result.value.buyAllCost > 0 ? (diff / result.value.buyAllCost) * 100 : 0
  return { amount: diff, percent: pct }
})

function formatMargin(percent: number): string {
  const sign = percent >= 0 ? '+' : ''
  return `${sign}${percent.toFixed(1)}%`
}

function stageBadgeClasses(stage: string): string {
  if (stage.includes('T2')) return 'bg-blue-500/20 text-blue-400'
  if (stage.includes('Reaction')) return 'bg-purple-500/20 text-purple-400'
  return 'bg-slate-700 text-slate-400'
}

function stageLabelColor(stage: string): string {
  if (stage.includes('T2')) return 'text-blue-400'
  if (stage.includes('Reaction')) return 'text-purple-400'
  return 'text-slate-400'
}

function verdictClasses(verdict: 'build' | 'buy' | 'loss'): string {
  switch (verdict) {
    case 'build': return 'bg-emerald-500/10 border border-emerald-500/20'
    case 'buy': return 'bg-amber-500/10 border border-amber-500/20'
    case 'loss': return 'bg-red-500/10 border border-red-500/20'
  }
}

function verdictSavingsColor(verdict: 'build' | 'buy' | 'loss'): string {
  switch (verdict) {
    case 'build': return 'text-emerald-300/80'
    case 'buy': return 'text-amber-300/80'
    case 'loss': return 'text-red-300/80'
  }
}

function onCreateOptimalProject(): void {
  if (!result.value) return
  const excludedTypeIds = result.value.components
    .filter(c => effectiveVerdict(c) !== 'build')
    .map(c => c.typeId)
  industryStore.navigationIntent = {
    target: 'projects',
    prefill: {
      typeId: result.value.typeId,
      typeName: result.value.typeName,
      runs: result.value.runs,
      me: meLevel.value,
      excludedTypeIds,
    },
  }
}

function onRefreshPrices(): void {
  if (!selectedProduct.value) return
  scannerStore.fetchBuyVsBuild(
    selectedProduct.value.typeId,
    runs.value,
    meLevel.value,
    selectedSystemId.value,
  )
}

const SUMMARY_LIST_LIMIT = 5
</script>

<template>
  <div class="space-y-5">
    <!-- Header & Product Selector -->
    <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-5">
      <div class="flex items-start justify-between mb-4">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
            </svg>
            <h3 class="text-lg font-bold text-slate-100">{{ t('industry.scanner.buyVsBuild.title') }}</h3>
          </div>
          <p class="text-sm text-slate-500">{{ t('industry.scanner.buyVsBuild.subtitle') }}</p>
        </div>
      </div>

      <div class="flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[280px]">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.buyVsBuild.product') }}</label>
          <ProductSearch ref="productSearchRef" @select="onProductSelect" />
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.buyVsBuild.runs') }}</label>
          <input
            v-model.number="runs"
            type="number"
            min="1"
            class="w-20 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500"
          />
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.buyVsBuild.me') }}</label>
          <input
            v-model.number="meLevel"
            type="number"
            min="0"
            max="10"
            class="w-20 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500"
          />
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.buyVsBuild.systemLabel') }}</label>
          <select
            v-model.number="selectedSystemId"
            class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-cyan-500"
          >
            <option :value="null">--</option>
            <option v-for="sys in favoriteSystems" :key="sys.id" :value="sys.id">{{ sys.name }}</option>
          </select>
        </div>
        <button
          @click="onAnalyze"
          :disabled="!selectedProduct || loading"
          class="px-5 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-colors disabled:opacity-50"
        >
          <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
          </svg>
          {{ t('industry.scanner.buyVsBuild.analyze') }}
        </button>
      </div>
    </div>

    <!-- Error -->
    <div v-if="error" class="p-4 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400">
      {{ error }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="p-12 text-center">
      <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      <p class="text-slate-500">{{ t('industry.scanner.buyVsBuild.analyzing') }}...</p>
    </div>

    <!-- Results -->
    <template v-else-if="result">
      <!-- Product Context Bar -->
      <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-4">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-lg border border-slate-700 bg-slate-800 overflow-hidden shrink-0">
              <img
                :src="getTypeIconUrl(result.typeId, 64)"
                :alt="result.typeName"
                class="w-full h-full object-cover"
                @error="onImageError"
              />
            </div>
            <div>
              <div class="flex items-center gap-2">
                <h3 class="text-lg font-bold text-slate-100">{{ result.typeName }}</h3>
                <span v-if="result.isT2" class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded-sm">T2</span>
              </div>
              <p class="text-xs text-slate-500 mt-0.5">
                {{ t('industry.scanner.buyVsBuild.analyzing') }}
                <span class="text-slate-400">{{ stageCounts.all }} {{ t('industry.scanner.buyVsBuild.components') }}</span>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-6">
            <div class="text-right">
              <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.buyVsBuild.totalProductionCost') }}</p>
              <p class="text-lg font-mono text-slate-100 font-semibold">{{ formatIsk(result.totalProductionCost) }}</p>
            </div>
            <div class="w-px h-10 bg-slate-700"></div>
            <div class="text-right">
              <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.buyVsBuild.sellPriceLabel') }}</p>
              <p class="text-lg font-mono text-slate-100 font-semibold">{{ formatIsk(result.sellPrice) }}</p>
            </div>
            <div class="w-px h-10 bg-slate-700"></div>
            <div class="text-right">
              <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.buyVsBuild.margin') }}</p>
              <p class="text-lg font-mono font-bold" :class="result.marginPercent >= 0 ? 'text-emerald-400' : 'text-red-400'">
                {{ formatMargin(result.marginPercent) }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Stage Filter Tabs -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex gap-2">
          <button
            @click="stageFilter = 'all'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
              stageFilter === 'all' ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.scanner.buyVsBuild.allStages') }}
            <span class="ml-1 text-xs font-mono" :class="stageFilter === 'all' ? 'text-cyan-200' : 'text-slate-500'">{{ stageCounts.all }}</span>
          </button>
          <button
            v-if="stageCounts.t2 > 0"
            @click="stageFilter = 'T2 Component'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
              stageFilter === 'T2 Component' ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.scanner.buyVsBuild.t2Components') }}
            <span class="ml-1 text-xs font-mono text-slate-500">{{ stageCounts.t2 }}</span>
          </button>
          <button
            v-if="stageCounts.t1 > 0"
            @click="stageFilter = 'T1 Component'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
              stageFilter === 'T1 Component' ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.scanner.buyVsBuild.t1Components') }}
            <span class="ml-1 text-xs font-mono text-slate-500">{{ stageCounts.t1 }}</span>
          </button>
          <button
            v-if="stageCounts.reaction > 0"
            @click="stageFilter = 'Reaction'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
              stageFilter === 'Reaction' ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.scanner.buyVsBuild.reactions') }}
            <span class="ml-1 text-xs font-mono text-slate-500">{{ stageCounts.reaction }}</span>
          </button>
        </div>
        <div class="flex items-center gap-3 text-xs text-slate-500">
          <span class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
            {{ t('industry.scanner.buyVsBuild.buildRecommended') }}: <span class="text-emerald-400 font-mono">{{ verdictCounts.build }}</span>
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-amber-400"></span>
            {{ t('industry.scanner.buyVsBuild.buyRecommended') }}: <span class="text-amber-400 font-mono">{{ verdictCounts.buy }}</span>
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-red-400"></span>
            {{ t('industry.scanner.buyVsBuild.buildingAtLoss') }}: <span class="text-red-400 font-mono">{{ verdictCounts.loss }}</span>
          </span>
        </div>
      </div>

      <!-- Component Cards Grid -->
      <template v-for="group in groupedComponents" :key="group.stage">
        <!-- Stage label -->
        <div class="flex items-center gap-3">
          <span :class="['text-xs font-semibold uppercase tracking-wider', stageLabelColor(group.stage)]">{{ group.stage }}s</span>
          <div class="flex-1 border-t border-slate-800"></div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div
            v-for="comp in group.components"
            :key="comp.typeId"
            :class="['bg-slate-900/50 rounded-xl overflow-hidden transition-all', isOverridden(comp.typeId) ? 'border border-cyan-500/30' : 'border border-slate-800 hover:border-cyan-500/20']"
          >
            <div class="p-4">
              <!-- Card header -->
              <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded-lg border border-slate-700 bg-slate-800 overflow-hidden shrink-0">
                    <img
                      :src="getTypeIconUrl(comp.typeId, 64)"
                      :alt="comp.typeName"
                      class="w-full h-full object-cover"
                      @error="onImageError"
                    />
                  </div>
                  <div>
                    <div class="flex items-center">
                      <p class="text-sm font-semibold text-slate-100">{{ comp.typeName }}</p>
                      <span v-if="isOverridden(comp.typeId)" class="text-[10px] px-1.5 py-0.5 rounded bg-cyan-500/20 text-cyan-400 font-medium ml-2">
                        {{ t('industry.scanner.buyVsBuild.custom') }}
                      </span>
                    </div>
                    <p class="text-xs text-slate-500 font-mono">x {{ formatNumber(comp.quantity, 0) }} {{ t('industry.scanner.buyVsBuild.needed') }}</p>
                  </div>
                </div>
                <span :class="['text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded-sm', stageBadgeClasses(comp.stage)]">
                  {{ comp.stage }}
                </span>
              </div>

              <!-- Build vs Buy columns -->
              <div class="grid grid-cols-2 gap-3 mb-3">
                <!-- BUILD column -->
                <div class="bg-slate-800 rounded-lg p-3">
                  <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" /></svg>
                    {{ t('industry.scanner.buyVsBuild.buildCost') }}
                  </p>
                  <p class="text-lg font-mono text-slate-100 font-semibold mb-2">{{ formatIsk(comp.buildCost) }}</p>
                  <div class="space-y-1 text-xs">
                    <div
                      v-for="mat in comp.buildMaterials.slice(0, 3)"
                      :key="mat.typeId"
                      class="flex justify-between text-slate-400"
                    >
                      <span class="truncate mr-2">{{ mat.typeName }}</span>
                      <span class="font-mono text-slate-300 shrink-0">{{ formatIsk(mat.totalPrice) }}</span>
                    </div>
                    <div class="border-t border-slate-700 pt-1 flex justify-between text-slate-500">
                      <span>{{ t('industry.scanner.buyVsBuild.jobInstall') }}</span>
                      <span class="font-mono text-slate-400">{{ formatIsk(comp.buildJobInstallCost) }}</span>
                    </div>
                  </div>
                </div>

                <!-- BUY column -->
                <div class="bg-slate-800 rounded-lg p-3">
                  <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" /></svg>
                    {{ t('industry.scanner.buyVsBuild.buyCost') }}
                  </p>
                  <p class="text-lg font-mono text-slate-100 font-semibold mb-1">
                    {{ (comp.buyCostStructure ?? comp.buyCostJita) != null ? formatIsk(comp.buyCostStructure ?? comp.buyCostJita) : '---' }}
                  </p>
                  <p v-if="comp.buyCostStructure != null || comp.buyCostJita != null" class="text-[10px] text-slate-500 mb-2">
                    {{ t('industry.scanner.buyVsBuild.bestPrice') }}: {{ comp.buyCostStructure != null ? t('industry.scanner.buyVsBuild.structureSell') : t('industry.scanner.buyVsBuild.jitaSell') }}
                  </p>
                  <div class="space-y-1 text-xs">
                    <div v-if="comp.buyCostJita != null" class="flex justify-between text-slate-400">
                      <span>{{ t('industry.scanner.buyVsBuild.jitaSell') }}</span>
                      <span class="font-mono text-slate-300">{{ formatIsk(comp.buyCostJita / comp.quantity, 2) }} /u</span>
                    </div>
                    <div v-if="comp.buyCostStructure != null" class="flex justify-between text-slate-400">
                      <span>{{ t('industry.scanner.buyVsBuild.structureSell') }}</span>
                      <span class="font-mono text-slate-300">{{ formatIsk(comp.buyCostStructure / comp.quantity, 2) }} /u</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- ME/TE + Runs -->
              <div class="flex items-center gap-4 text-xs text-slate-500 mb-3">
                <span class="font-mono">ME {{ comp.meUsed }} / TE 20</span>
                <span class="font-mono">{{ comp.runs }} runs</span>
              </div>

              <!-- Verdict bar -->
              <div :class="['rounded-lg px-4 py-2.5 flex items-center justify-between', verdictClasses(effectiveVerdict(comp))]">
                <div class="flex items-center gap-2">
                  <template v-if="!isOverridden(comp.typeId)">
                    <span :class="['text-sm', verdictSavingsColor(effectiveVerdict(comp))]">
                      <template v-if="comp.verdict === 'loss'">
                        {{ t('industry.scanner.buyVsBuild.verdictLoss') }}
                        <span class="font-mono font-semibold">({{ formatIsk(-comp.savings) }})</span>
                      </template>
                      <template v-else>
                        {{ t('industry.scanner.buyVsBuild.save') }}
                        <span class="font-mono font-semibold">{{ formatIsk(Math.abs(comp.savings)) }}</span>
                        <span class="opacity-60">({{ comp.savingsPercent.toFixed(1) }}%)</span>
                      </template>
                    </span>
                  </template>
                  <span v-else class="text-sm text-cyan-400">
                    {{ t('industry.scanner.buyVsBuild.manualChoice') }}
                  </span>
                </div>
                <div class="flex items-center bg-slate-800 rounded-lg p-0.5">
                  <button
                    @click.stop="setOverride(comp, 'build')"
                    :class="[
                      'px-3 py-1 rounded-md text-xs font-medium flex items-center gap-1 transition-colors',
                      effectiveVerdict(comp) === 'build' ? 'bg-emerald-500/20 text-emerald-400' : 'text-slate-500 hover:text-slate-300'
                    ]"
                  >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                    </svg>
                    Build
                  </button>
                  <button
                    @click.stop="setOverride(comp, 'buy')"
                    :class="[
                      'px-3 py-1 rounded-md text-xs font-medium flex items-center gap-1 transition-colors',
                      effectiveVerdict(comp) !== 'build' ? 'bg-amber-500/20 text-amber-400' : 'text-slate-500 hover:text-slate-300'
                    ]"
                  >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                    </svg>
                    Buy
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- Summary Panel -->
      <div class="bg-slate-900/50 border border-slate-800 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.scanner.buyVsBuild.summaryTitle') }}</h4>
            <span v-if="hasOverrides" class="text-xs text-cyan-400">
              {{ t('industry.scanner.buyVsBuild.overrides', { count: overrideCounts.total }) }}
            </span>
          </div>
          <button
            v-if="hasOverrides"
            @click="userOverrides = new Map()"
            class="text-xs text-slate-500 hover:text-slate-300 transition-colors"
          >
            {{ t('industry.scanner.buyVsBuild.resetAll') }}
          </button>
        </div>

        <div class="p-5">
          <!-- Strategy comparison: 3 columns -->
          <div class="grid grid-cols-3 gap-6 mb-6">
            <!-- Build All -->
            <div class="bg-slate-800 rounded-xl p-4 text-center">
              <div class="flex items-center justify-center gap-2 mb-3">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                </svg>
                <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold">{{ t('industry.scanner.buyVsBuild.buildEverything') }}</p>
              </div>
              <p class="text-2xl font-mono text-slate-100 font-bold mb-1">{{ formatIsk(result.buildAllCost) }}</p>
              <p class="text-xs text-slate-500">{{ t('industry.scanner.buyVsBuild.iskTotalProduction') }}</p>
            </div>
            <!-- Buy All -->
            <div class="bg-slate-800 rounded-xl p-4 text-center">
              <div class="flex items-center justify-center gap-2 mb-3">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                </svg>
                <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold">{{ t('industry.scanner.buyVsBuild.buyEverything') }}</p>
              </div>
              <p class="text-2xl font-mono text-slate-100 font-bold mb-1">{{ formatIsk(result.buyAllCost) }}</p>
              <p class="text-xs text-slate-500">{{ t('industry.scanner.buyVsBuild.iskTotalMarket') }}</p>
            </div>
            <!-- Optimal Mix -->
            <div class="bg-slate-800 rounded-xl p-4 text-center border border-cyan-500/30">
              <div class="flex items-center justify-center gap-2 mb-3">
                <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
                </svg>
                <p class="text-xs text-cyan-400 uppercase tracking-wider font-semibold">{{ hasOverrides ? t('industry.scanner.buyVsBuild.yourMix') : t('industry.scanner.buyVsBuild.optimalMix') }}</p>
              </div>
              <p class="text-2xl font-mono text-cyan-400 font-bold mb-1">{{ formatIsk(computedOptimalMixCost) }}</p>
              <p class="text-xs text-slate-500">{{ t('industry.scanner.buyVsBuild.iskOptimized') }}</p>
            </div>
          </div>

          <!-- Savings bar -->
          <div class="bg-emerald-500/5 border border-emerald-500/20 rounded-xl p-4 mb-5">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                  <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                  </svg>
                </div>
                <div>
                  <p class="text-sm text-slate-200 font-semibold">{{ t('industry.scanner.buyVsBuild.optimalSaves') }}</p>
                  <p class="text-xs text-slate-500">
                    {{ t('industry.scanner.buyVsBuild.vsBuildAll') }}:
                    <span class="text-emerald-400 font-mono">{{ formatIsk(savingsVsBuildAll.amount) }} ({{ savingsVsBuildAll.percent.toFixed(1) }}%)</span>
                    &middot;
                    {{ t('industry.scanner.buyVsBuild.vsBuyAll') }}:
                    <span class="text-emerald-400 font-mono">{{ formatIsk(savingsVsBuyAll.amount) }} ({{ savingsVsBuyAll.percent.toFixed(1) }}%)</span>
                  </p>
                </div>
              </div>
              <p class="text-3xl font-mono text-emerald-400 font-bold">{{ formatIsk(savingsVsBuyAll.amount) }}</p>
            </div>
          </div>

          <!-- Recommended lists -->
          <div class="grid grid-cols-2 gap-4">
            <!-- Build list -->
            <div class="bg-slate-800 rounded-xl p-4">
              <div class="flex items-center gap-2 mb-3">
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <p class="text-xs text-emerald-400 uppercase tracking-wider font-semibold">{{ t('industry.scanner.buyVsBuild.recommendedBuild') }}</p>
                <span class="text-xs text-slate-600 font-mono">{{ buildList.length }} {{ t('industry.scanner.buyVsBuild.items') }}</span>
              </div>
              <div class="space-y-2">
                <div
                  v-for="comp in buildList.slice(0, SUMMARY_LIST_LIMIT)"
                  :key="comp.typeId"
                  class="flex items-center justify-between"
                >
                  <div class="flex items-center gap-2">
                    <div class="w-5 h-5 rounded-sm bg-slate-700 border border-slate-600 overflow-hidden shrink-0">
                      <img :src="getTypeIconUrl(comp.typeId, 32)" alt="" class="w-full h-full" @error="onImageError" />
                    </div>
                    <span class="text-sm text-slate-300 truncate">{{ comp.typeName }}</span>
                  </div>
                  <span class="text-xs font-mono text-emerald-400 shrink-0">-{{ formatIsk(comp.savings) }}</span>
                </div>
                <div v-if="buildList.length > SUMMARY_LIST_LIMIT" class="flex items-center justify-between text-slate-500">
                  <span class="text-xs">+{{ buildList.length - SUMMARY_LIST_LIMIT }} {{ t('industry.scanner.buyVsBuild.more') }}</span>
                </div>
              </div>
              <div class="border-t border-slate-700 pt-2 mt-3 flex justify-between">
                <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.buyVsBuild.totalSavings') }}</span>
                <span class="text-sm font-mono text-emerald-400 font-bold">{{ formatIsk(buildTotalSavings) }}</span>
              </div>
            </div>

            <!-- Buy list -->
            <div class="bg-slate-800 rounded-xl p-4">
              <div class="flex items-center gap-2 mb-3">
                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                </svg>
                <p class="text-xs text-amber-400 uppercase tracking-wider font-semibold">{{ t('industry.scanner.buyVsBuild.recommendedBuy') }}</p>
                <span class="text-xs text-slate-600 font-mono">{{ buyList.length }} {{ t('industry.scanner.buyVsBuild.items') }}</span>
              </div>
              <div class="space-y-2">
                <div
                  v-for="comp in buyList.slice(0, SUMMARY_LIST_LIMIT)"
                  :key="comp.typeId"
                  class="flex items-center justify-between"
                >
                  <div class="flex items-center gap-2">
                    <div class="w-5 h-5 rounded-sm bg-slate-700 border border-slate-600 overflow-hidden shrink-0">
                      <img :src="getTypeIconUrl(comp.typeId, 32)" alt="" class="w-full h-full" @error="onImageError" />
                    </div>
                    <span class="text-sm text-slate-300 truncate">{{ comp.typeName }}</span>
                  </div>
                  <div class="flex items-center gap-2 shrink-0">
                    <span v-if="comp.verdict === 'loss'" class="text-[10px] px-1.5 py-0.5 rounded-sm bg-red-500/10 text-red-400">{{ t('industry.scanner.buyVsBuild.loss') }}</span>
                    <span class="text-xs font-mono" :class="comp.verdict === 'loss' ? 'text-red-400' : 'text-amber-400'">-{{ formatIsk(Math.abs(comp.savings)) }}</span>
                  </div>
                </div>
                <div v-if="buyList.length > SUMMARY_LIST_LIMIT" class="flex items-center justify-between text-slate-500">
                  <span class="text-xs">+{{ buyList.length - SUMMARY_LIST_LIMIT }} {{ t('industry.scanner.buyVsBuild.more') }}</span>
                </div>
              </div>
              <div class="border-t border-slate-700 pt-2 mt-3 flex justify-between">
                <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.buyVsBuild.totalSavings') }}</span>
                <span class="text-sm font-mono text-amber-400 font-bold">{{ formatIsk(buyTotalSavings) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Info note -->
        <div class="px-5 py-3 bg-cyan-900/10 border-t border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-xs text-cyan-300/70">{{ t('industry.scanner.buyVsBuild.infoNote') }}</p>
        </div>
      </div>

      <!-- Bottom action bar -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
          <div>
            <button
              @click="onCreateOptimalProject"
              class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-colors"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
              </svg>
              {{ t('industry.scanner.buyVsBuild.createProject') }}
            </button>
            <p v-if="excludedComponentNames.length > 0" class="text-xs text-slate-500 mt-1">
              {{ t('industry.scanner.buyVsBuild.excludes', { items: excludedComponentNames.join(', ') }) }}
            </p>
          </div>
          <button
            @click="onRefreshPrices"
            :disabled="loading"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium flex items-center gap-2 transition-colors disabled:opacity-50"
          >
            <svg :class="['w-4 h-4', loading ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('industry.scanner.buyVsBuild.refreshPrices') }}
          </button>
          <button
            class="px-4 py-2 bg-slate-700 rounded-lg text-slate-500 text-sm font-medium flex items-center gap-2 cursor-not-allowed opacity-50"
            disabled
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
            </svg>
            {{ t('industry.scanner.buyVsBuild.export') }}
          </button>
        </div>
      </div>
    </template>

    <!-- Empty state (no result yet) -->
    <div v-else-if="!loading" class="bg-slate-900 rounded-xl border border-slate-800 p-8 text-center">
      <svg class="w-12 h-12 mx-auto mb-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
      </svg>
      <p class="text-slate-500">{{ t('industry.scanner.buyVsBuild.noResult') }}</p>
    </div>
  </div>
</template>

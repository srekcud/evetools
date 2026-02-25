<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useScannerStore } from '@/stores/industry/scanner'
import { useIndustryStore, type SearchResult } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import type { PivotCandidate } from '@/stores/industry/types'

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
const runs = ref(1)
const selectedSystemId = ref<number | null>(null)
const sortBy = ref<string>('score')

// Favorite systems from user settings (same pattern as BuyVsBuildTab)
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

const result = computed(() => scannerStore.pivotResult)
const loading = computed(() => scannerStore.pivotLoading)
const error = computed(() => scannerStore.pivotError)

const sortedCandidates = computed((): PivotCandidate[] => {
  if (!result.value) return []
  const candidates = [...result.value.candidates]
  switch (sortBy.value) {
    case 'margin':
      return candidates.sort((a, b) => (b.marginPercent ?? -999) - (a.marginPercent ?? -999))
    case 'coverage':
      return candidates.sort((a, b) => b.coveragePercent - a.coveragePercent)
    case 'volume':
      return candidates.sort((a, b) => b.dailyVolume - a.dailyVolume)
    case 'profit':
      return candidates.sort((a, b) => b.estimatedProfit - a.estimatedProfit)
    default: // score
      return candidates.sort((a, b) => b.score - a.score)
  }
})

function onProductSelect(result: SearchResult): void {
  selectedProduct.value = result
}

async function onAnalyze(): Promise<void> {
  if (!selectedProduct.value) return
  await scannerStore.fetchPivotAnalysis(
    selectedProduct.value.typeId,
    runs.value,
    selectedSystemId.value,
  )
}

function onPivot(candidate: PivotCandidate): void {
  industryStore.navigationIntent = {
    target: 'projects',
    prefill: {
      typeId: candidate.typeId,
      typeName: candidate.typeName,
    },
  }
}

function onCompare(candidate: PivotCandidate): void {
  industryStore.navigationIntent = {
    target: 'buy-vs-build',
    typeId: candidate.typeId,
  }
}

function formatMargin(percent: number | null): string {
  if (percent == null) return 'N/A'
  const sign = percent >= 0 ? '+' : ''
  return `${sign}${percent.toFixed(1)}%`
}

function coverageBarColor(percent: number): string {
  return percent >= 60 ? 'bg-emerald-400' : 'bg-amber-400'
}

function coverageTextColor(percent: number): string {
  return percent >= 60 ? 'text-emerald-400' : 'text-amber-400'
}

function marginColor(percent: number | null): string {
  if (percent == null) return 'text-gray-400'
  return percent >= 0 ? 'text-emerald-400' : 'text-red-400'
}

function rankBadgeClasses(rank: number): string {
  if (rank <= 3) return 'bg-emerald-400/20 text-emerald-400 border border-emerald-400/30'
  return 'bg-cyan-400/20 text-cyan-400 border border-cyan-400/30'
}

// Resolve the candidate data for a product column in the matrix
function getMatrixCellData(row: { candidates: Record<number, { needed: number; status: 'covered' | 'partial' | 'none' }> }, productId: number): { needed: number; status: 'covered' | 'partial' | 'none' } | null {
  return row.candidates[productId] ?? null
}
</script>

<template>
  <div class="space-y-5">
    <!-- Header & Product Selector -->
    <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-5">
      <div class="flex items-start justify-between mb-4">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
            </svg>
            <h3 class="text-lg font-bold text-slate-100">{{ t('industry.scanner.pivotAdvisor.title') }}</h3>
          </div>
          <p class="text-sm text-slate-500">{{ t('industry.scanner.pivotAdvisor.subtitle') }}</p>
        </div>
      </div>

      <div class="flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[280px]">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.pivotAdvisor.selectProduct') }}</label>
          <ProductSearch @select="onProductSelect" />
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.pivotAdvisor.runs') }}</label>
          <input
            v-model.number="runs"
            type="number"
            min="1"
            class="w-20 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500"
          />
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.pivotAdvisor.systemLabel') }}</label>
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
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
          </svg>
          {{ t('industry.scanner.pivotAdvisor.analyze') }}
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
      <p class="text-slate-500">{{ t('industry.scanner.pivotAdvisor.analyzing') }}...</p>
    </div>

    <!-- Results -->
    <template v-if="result && !loading">
      <!-- Current Product Card (amber warning) -->
      <div
        class="bg-[#0f172a] border rounded-xl p-5"
        :class="result.sourceProduct.marginPercent != null && result.sourceProduct.marginPercent < 0
          ? 'border-amber-500/40 shadow-[0_0_15px_rgba(251,191,36,0.05)]'
          : 'border-slate-800'"
      >
        <div class="flex items-start gap-5 flex-wrap lg:flex-nowrap">
          <!-- Product icon -->
          <div class="flex-shrink-0">
            <img
              :src="getTypeIconUrl(result.sourceProduct.typeId, 64)"
              :alt="result.sourceProduct.typeName"
              class="w-16 h-16 rounded-lg border"
              :class="result.sourceProduct.marginPercent != null && result.sourceProduct.marginPercent < 0 ? 'border-amber-500/30' : 'border-slate-700'"
              @error="onImageError"
            />
          </div>

          <!-- Product info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3 mb-2 flex-wrap">
              <h2 class="text-lg font-bold text-slate-100">{{ result.sourceProduct.typeName }}</h2>
              <span class="text-xs px-2 py-0.5 rounded bg-amber-400/10 text-amber-400 border border-amber-400/20">
                {{ result.sourceProduct.groupName }}
              </span>
              <span
                v-if="result.sourceProduct.marginPercent != null && result.sourceProduct.marginPercent < 0"
                class="text-xs px-2 py-0.5 rounded bg-red-400/10 text-red-400 border border-red-400/20"
              >
                {{ t('industry.scanner.pivotAdvisor.profitabilityAlert') }}
              </span>
            </div>

            <div class="flex items-center gap-6 mb-3 flex-wrap">
              <div>
                <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.pivotAdvisor.marginPercent') }}</span>
                <div class="font-mono text-lg font-bold" :class="marginColor(result.sourceProduct.marginPercent)">
                  {{ formatMargin(result.sourceProduct.marginPercent) }}
                </div>
              </div>
              <div>
                <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.pivotAdvisor.dailyVolume') }}</span>
                <div class="font-mono text-sm text-slate-300">{{ formatNumber(result.sourceProduct.dailyVolume, 0) }} {{ t('industry.scanner.pivotAdvisor.unitsDay') }}</div>
              </div>
              <div>
                <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.buyVsBuild.sellPriceLabel') }}</span>
                <div class="font-mono text-sm text-slate-300">{{ formatIsk(result.sourceProduct.sellPrice) }}</div>
              </div>
            </div>

            <!-- Warning message when margin < 0 -->
            <div
              v-if="result.sourceProduct.marginPercent != null && result.sourceProduct.marginPercent < 0"
              class="flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-400/10 border border-amber-400/20"
            >
              <svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
              </svg>
              <span class="text-sm text-amber-300">{{ t('industry.scanner.pivotAdvisor.marginWarning') }}</span>
            </div>
          </div>

          <!-- Key components in stock -->
          <div v-if="result.sourceProduct.keyComponents.length > 0" class="flex-shrink-0 w-72">
            <div class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.scanner.pivotAdvisor.keyComponents') }}</div>
            <div class="bg-[#1e293b] rounded-lg p-3 space-y-1.5">
              <div
                v-for="comp in result.sourceProduct.keyComponents"
                :key="comp.typeId"
                class="flex items-center justify-between text-sm"
              >
                <span class="text-slate-300 truncate mr-2">{{ comp.typeName }}</span>
                <span class="font-mono text-cyan-400 shrink-0">{{ formatNumber(comp.inStock, 0) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Shared Components Matrix -->
      <div v-if="result.matrix.length > 0" class="bg-[#0f172a] border border-slate-800 rounded-xl p-5">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
            </svg>
            <h3 class="text-base font-semibold text-slate-100">{{ t('industry.scanner.pivotAdvisor.componentMatrix') }}</h3>
          </div>
          <div class="flex items-center gap-4 text-xs text-slate-500">
            <span class="flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-sm bg-emerald-400/20 border border-emerald-400/30"></span>
              {{ t('industry.scanner.pivotAdvisor.fullyCovered') }}
            </span>
            <span class="flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-sm bg-amber-400/20 border border-amber-400/30"></span>
              {{ t('industry.scanner.pivotAdvisor.partialCoverage') }}
            </span>
            <span class="flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-sm bg-slate-700 border border-slate-600"></span>
              {{ t('industry.scanner.pivotAdvisor.notRequired') }}
            </span>
          </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-800">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-700">
                <th class="text-left text-xs uppercase tracking-wider text-slate-500 px-4 py-3 bg-slate-900/50 w-56">
                  {{ t('industry.scanner.pivotAdvisor.component') }}
                </th>
                <th class="text-center text-xs uppercase tracking-wider text-slate-500 px-3 py-3 bg-slate-900/50 w-24">
                  {{ t('industry.scanner.pivotAdvisor.inStock') }}
                </th>
                <!-- Source product + candidates columns -->
                <th
                  v-for="productId in result.matrixProductIds"
                  :key="productId"
                  class="text-center px-3 py-3 bg-slate-900/50 border-l border-slate-700"
                >
                  <div class="flex flex-col items-center gap-0.5">
                    <img
                      :src="getTypeIconUrl(productId, 32)"
                      :alt="String(productId)"
                      class="w-6 h-6 rounded"
                      :class="productId === result.sourceProduct.typeId ? 'opacity-50' : ''"
                      @error="onImageError"
                    />
                    <span class="text-xs" :class="productId === result.sourceProduct.typeId ? 'text-slate-500' : 'text-slate-400'">
                      {{ productId === result.sourceProduct.typeId
                        ? result.sourceProduct.typeName
                        : (result.candidates.find(c => c.typeId === productId)?.typeName ?? String(productId))
                      }}
                    </span>
                    <span
                      class="font-mono text-xs"
                      :class="marginColor(
                        productId === result.sourceProduct.typeId
                          ? result.sourceProduct.marginPercent
                          : (result.candidates.find(c => c.typeId === productId)?.marginPercent ?? null)
                      )"
                    >
                      {{ formatMargin(
                        productId === result.sourceProduct.typeId
                          ? result.sourceProduct.marginPercent
                          : (result.candidates.find(c => c.typeId === productId)?.marginPercent ?? null)
                      ) }}
                    </span>
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, rowIdx) in result.matrix"
                :key="row.typeId"
                class="border-b border-slate-800/50"
                :class="rowIdx % 2 === 1 ? 'bg-slate-800/30' : ''"
              >
                <td class="px-4 py-2.5 text-slate-300 font-medium">{{ row.typeName }}</td>
                <td class="px-3 py-2.5 text-center font-mono text-cyan-400">{{ formatNumber(row.inStock, 0) }}</td>
                <td
                  v-for="productId in result.matrixProductIds"
                  :key="productId"
                  class="px-3 py-2.5 text-center border-l border-slate-800/50 transition-colors hover:bg-cyan-400/5"
                  :class="{
                    'bg-emerald-400/[0.08]': getMatrixCellData(row, productId)?.status === 'covered',
                    'bg-amber-400/[0.08]': getMatrixCellData(row, productId)?.status === 'partial',
                  }"
                >
                  <!-- Covered or Partial: show check + quantity -->
                  <div
                    v-if="getMatrixCellData(row, productId)?.status === 'covered' || getMatrixCellData(row, productId)?.status === 'partial'"
                    class="flex items-center justify-center gap-1"
                  >
                    <svg
                      class="w-3.5 h-3.5"
                      :class="getMatrixCellData(row, productId)?.status === 'covered' ? 'text-emerald-400' : 'text-amber-400'"
                      fill="currentColor"
                      viewBox="0 0 20 20"
                    >
                      <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    <span
                      class="font-mono text-xs"
                      :class="getMatrixCellData(row, productId)?.status === 'covered' ? 'text-emerald-400' : 'text-amber-400'"
                    >
                      {{ formatNumber(getMatrixCellData(row, productId)!.needed, 0) }}
                    </span>
                  </div>
                  <!-- None / Not required -->
                  <span v-else class="text-slate-600">&mdash;</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pivot Candidates -->
      <div>
        <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
            <h3 class="text-base font-semibold text-slate-100">{{ t('industry.scanner.pivotAdvisor.pivotCandidates') }}</h3>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-xs text-slate-500">{{ t('industry.scanner.pivotAdvisor.sortBy') }}:</span>
            <select
              v-model="sortBy"
              class="text-xs bg-slate-800 border border-slate-700 text-slate-300 rounded-lg px-2 py-1 focus:outline-none focus:border-cyan-500"
            >
              <option value="score">{{ t('industry.scanner.pivotAdvisor.coverageMargin') }}</option>
              <option value="margin">{{ t('industry.scanner.pivotAdvisor.marginPercent') }}</option>
              <option value="coverage">{{ t('industry.scanner.pivotAdvisor.componentCoverage') }}</option>
              <option value="volume">{{ t('industry.scanner.pivotAdvisor.dailyVolume') }}</option>
              <option value="profit">{{ t('industry.scanner.pivotAdvisor.estimatedProfit') }}</option>
            </select>
          </div>
        </div>

        <!-- No candidates -->
        <div v-if="sortedCandidates.length === 0" class="bg-slate-900 rounded-xl border border-slate-800 p-8 text-center">
          <p class="text-slate-500">{{ t('industry.scanner.pivotAdvisor.noCandidates') }}</p>
        </div>

        <!-- Candidate cards grid -->
        <div v-else class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
          <div
            v-for="(candidate, idx) in sortedCandidates"
            :key="candidate.typeId"
            class="bg-[#0f172a] border border-[#1e293b] rounded-xl p-4 relative transition-all hover:border-cyan-400/30 hover:shadow-[0_0_20px_rgba(6,182,212,0.05)]"
          >
            <!-- Rank badge -->
            <div
              class="absolute -top-2 -left-2 w-7 h-7 flex items-center justify-center rounded-full font-bold text-xs font-mono"
              :class="rankBadgeClasses(idx + 1)"
            >
              {{ idx + 1 }}
            </div>

            <!-- Header -->
            <div class="flex items-center gap-3 mb-3">
              <img
                :src="getTypeIconUrl(candidate.typeId, 64)"
                :alt="candidate.typeName"
                class="w-12 h-12 rounded-lg border border-slate-700"
                @error="onImageError"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-base font-bold text-slate-100">{{ candidate.typeName }}</span>
                  <span class="text-xs px-1.5 py-0.5 rounded bg-cyan-400/10 text-cyan-400 border border-cyan-400/20">
                    {{ candidate.groupName }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Stats grid -->
            <div class="grid grid-cols-2 gap-2 mb-3">
              <div class="bg-[#1e293b] rounded-lg p-2">
                <div class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.pivotAdvisor.margin') }}</div>
                <div class="font-mono text-sm font-bold" :class="marginColor(candidate.marginPercent)">
                  {{ formatMargin(candidate.marginPercent) }}
                </div>
                <div class="font-mono text-xs text-slate-500">{{ formatIsk(candidate.profitPerUnit) }} ISK</div>
              </div>
              <div class="bg-[#1e293b] rounded-lg p-2">
                <div class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.pivotAdvisor.dailyVol') }}</div>
                <div class="font-mono text-sm font-bold text-slate-200">{{ formatNumber(candidate.dailyVolume, 0) }}</div>
                <div class="text-xs text-slate-500">{{ t('industry.scanner.pivotAdvisor.unitsDay') }}</div>
              </div>
            </div>

            <!-- Coverage bar -->
            <div class="mb-3">
              <div class="flex items-center justify-between mb-1">
                <span class="text-xs text-slate-400">{{ t('industry.scanner.pivotAdvisor.componentCoverage') }}</span>
                <span class="font-mono text-xs" :class="coverageTextColor(candidate.coveragePercent)">
                  {{ candidate.coveragePercent }}%
                </span>
              </div>
              <div class="h-2 rounded-full bg-slate-800 overflow-hidden">
                <div
                  class="h-full rounded-full transition-all duration-600"
                  :class="coverageBarColor(candidate.coveragePercent)"
                  :style="{ width: candidate.coveragePercent + '%' }"
                ></div>
              </div>
            </div>

            <!-- Missing components -->
            <div v-if="candidate.missingComponents.length > 0" class="mb-3">
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.pivotAdvisor.missingComponents') }}</div>
              <div class="space-y-1">
                <div
                  v-for="mc in candidate.missingComponents"
                  :key="mc.typeId"
                  class="flex items-center justify-between text-xs"
                >
                  <!-- Amber for partial (some stock), red for none (zero stock) -->
                  <span :class="mc.quantity > 0 && mc.cost > 0 ? 'text-amber-400' : 'text-red-400'">
                    {{ mc.typeName }}
                  </span>
                  <span
                    class="font-mono"
                    :class="mc.quantity > 0 && mc.cost > 0 ? 'text-amber-400' : 'text-red-400'"
                  >
                    {{ mc.cost > 0
                      ? '+' + formatNumber(mc.quantity, 0) + ' ' + t('industry.scanner.pivotAdvisor.short')
                      : formatNumber(mc.quantity, 0) + ' ' + t('industry.scanner.pivotAdvisor.needed')
                    }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Cost / Profit -->
            <div class="bg-[#1e293b] rounded-lg p-2.5 mb-3 space-y-1.5">
              <div class="flex items-center justify-between text-xs">
                <span class="text-slate-400">{{ t('industry.scanner.pivotAdvisor.additionalCost') }}</span>
                <span class="font-mono text-amber-400">{{ formatIsk(candidate.additionalCost) }}</span>
              </div>
              <div class="flex items-center justify-between text-xs">
                <span class="text-slate-400">{{ t('industry.scanner.pivotAdvisor.estimatedProfit') }}</span>
                <span class="font-mono text-emerald-400 font-bold">{{ formatIsk(candidate.estimatedProfit) }}</span>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-2">
              <button
                @click="onPivot(candidate)"
                class="flex-1 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg transition-colors"
              >
                {{ t('industry.scanner.pivotAdvisor.pivotButton') }}
              </button>
              <button
                @click="onCompare(candidate)"
                class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm rounded-lg border border-slate-700 transition-colors"
              >
                {{ t('industry.scanner.pivotAdvisor.compareButton') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Bottom Info Panel -->
      <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-cyan-400/5 border border-cyan-400/20">
        <svg class="w-5 h-5 text-cyan-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-sm text-cyan-300">{{ t('industry.scanner.pivotAdvisor.infoNote') }}</span>
      </div>
    </template>

    <!-- Empty state (no result yet) -->
    <div v-if="!result && !loading && !error" class="bg-slate-900 rounded-xl border border-slate-800 p-8 text-center">
      <svg class="w-12 h-12 mx-auto mb-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
      </svg>
      <p class="text-slate-500">{{ t('industry.scanner.pivotAdvisor.selectProduct') }}</p>
    </div>
  </div>
</template>

<style scoped>
.duration-600 {
  transition-duration: 600ms;
}
</style>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { authFetch, safeJsonParse } from '@/services/api'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import { useFormatters } from '@/composables/useFormatters'
import AppraisalInputPanel from '@/components/appraisal/AppraisalInputPanel.vue'
import AppraisalResultsTable from '@/components/appraisal/AppraisalResultsTable.vue'
import type { MergedItem } from '@/components/appraisal/AppraisalResultsTable.vue'
import AppraisalActions from '@/components/appraisal/AppraisalActions.vue'

// -- Types --

type AppraisalItem = {
  typeId: number
  typeName: string
  quantity: number
  volume: number
  totalVolume: number
  sellPrice: number | null
  sellTotal: number | null
  buyPrice: number | null
  buyTotal: number | null
  sellPriceWeighted: number | null
  sellTotalWeighted: number | null
  buyPriceWeighted: number | null
  buyTotalWeighted: number | null
  sellCoverage: number | null
  buyCoverage: number | null
  avgDailyVolume: number | null
}

type AppraisalTotals = {
  sellTotal: number
  buyTotal: number
  volume: number
  sellTotalWeighted: number | null
  buyTotalWeighted: number | null
}

type AppraisalResponse = {
  items: AppraisalItem[]
  notFound: string[]
  totals: AppraisalTotals
  priceError: string | null
}

type ShoppingItem = {
  typeId: number
  typeName: string
  quantity: number
  totalVolume: number
  jitaTotal: number | null
  jitaWithImport: number | null
  structureTotal: number | null
  structureUnitPrice: number | null
  bestLocation: 'jita' | 'structure' | null
}

type ShoppingResponse = {
  items: ShoppingItem[]
  notFound: string[]
  totals: {
    jitaWithImport: number
    structure: number
    best: number
    volume: number
  }
  transportCostPerM3: number
  structureId: number | null
  structureName: string | null
  priceError: string | null
  structureAccessible?: boolean
  structureFromCache?: boolean
  structureLastSync?: string | null
}

type FormatType = 'auto' | 'multibuy' | 'cargo_scan' | 'eft' | 'dscan' | 'contract' | 'killmail' | 'inventory'

// -- Composables --

const { t } = useI18n()
const { formatIsk, formatNumber, formatTimeSince } = useFormatters()

// -- State --

const inputText = ref('')
const transportCostPerM3 = ref(1200)
const selectedFormat = ref<FormatType>('auto')
const detectedFormat = ref<FormatType | null>(null)
const selectedStructure = ref<{ id: number | null; name: string }>({ id: null, name: '' })
const isLoading = ref(false)
const isSyncing = ref(false)
const error = ref('')

// Results
const appraisalResult = ref<AppraisalResponse | null>(null)
const shoppingResult = ref<ShoppingResponse | null>(null)

// Config panel
const configExpanded = ref(true)

// Share state
const isSharing = ref(false)
const shareUrl = ref<string | null>(null)

// -- Computed --

const hasInput = computed(() => inputText.value.trim().length > 0)
const hasResults = computed(() => appraisalResult.value != null && appraisalResult.value.items.length > 0)
const hasStructureResults = computed(() => shoppingResult.value != null && shoppingResult.value.items.length > 0)

const shortStructureName = computed(() => {
  const name = shoppingResult.value?.structureName || selectedStructure.value.name
  if (!name) return 'Structure'
  const parts = name.split(' - ')
  return parts[0] || 'Structure'
})

// Merge appraisal + shopping data
const mergedItems = computed((): MergedItem[] => {
  if (!appraisalResult.value) return []

  const shoppingMap = new Map<number, ShoppingItem>()
  if (shoppingResult.value) {
    for (const item of shoppingResult.value.items) {
      shoppingMap.set(item.typeId, item)
    }
  }

  return appraisalResult.value.items.map(ai => {
    const si = shoppingMap.get(ai.typeId)
    return {
      typeId: ai.typeId,
      typeName: ai.typeName,
      quantity: ai.quantity,
      volume: ai.volume,
      totalVolume: ai.totalVolume,
      sellPrice: ai.sellPrice,
      sellPriceWeighted: ai.sellPriceWeighted,
      sellTotal: ai.sellTotal,
      sellTotalWeighted: ai.sellTotalWeighted,
      sellCoverage: ai.sellCoverage,
      buyPrice: ai.buyPrice,
      buyPriceWeighted: ai.buyPriceWeighted,
      buyTotal: ai.buyTotal,
      buyTotalWeighted: ai.buyTotalWeighted,
      buyCoverage: ai.buyCoverage,
      avgDailyVolume: ai.avgDailyVolume,
      jitaWithImport: si?.jitaWithImport ?? null,
      structureTotal: si?.structureTotal ?? null,
      structureCoverage: null,
      structureCoverageQty: null,
      bestLocation: si?.bestLocation ?? null,
    }
  })
})

// KPI totals
const sellValue = computed(() => {
  if (!appraisalResult.value) return 0
  return appraisalResult.value.totals.sellTotalWeighted ?? appraisalResult.value.totals.sellTotal
})

const sellValueBest = computed(() => {
  if (!appraisalResult.value) return 0
  return appraisalResult.value.totals.sellTotal
})

const buyValue = computed(() => {
  if (!appraisalResult.value) return 0
  return appraisalResult.value.totals.buyTotalWeighted ?? appraisalResult.value.totals.buyTotal
})

const buyValueBest = computed(() => {
  if (!appraisalResult.value) return 0
  return appraisalResult.value.totals.buyTotal
})

const totalVolume = computed(() => {
  if (!appraisalResult.value) return 0
  return appraisalResult.value.totals.volume
})

const itemCount = computed(() => {
  return appraisalResult.value?.items.length ?? 0
})

// Structure comparison KPIs
const jitaPlusTransport = computed(() => shoppingResult.value?.totals.jitaWithImport ?? null)
const structureTotal = computed(() => shoppingResult.value?.totals.structure ?? null)
const bestPrice = computed(() => shoppingResult.value?.totals.best ?? null)

// Structure coverage
const structureCoverageCount = computed(() => {
  if (!shoppingResult.value) return { covered: 0, total: 0 }
  const total = shoppingResult.value.items.length
  const covered = shoppingResult.value.items.filter(i => i.structureTotal != null).length
  return { covered, total }
})

const structureCoveragePercent = computed(() => {
  const { covered, total } = structureCoverageCount.value
  if (total === 0) return 0
  return Math.round((covered / total) * 100)
})

// Items by location
const jitaItemCount = computed(() => {
  return mergedItems.value.filter(i => i.bestLocation === 'jita' || i.bestLocation === null).length
})

const structureItemCount = computed(() => {
  return mergedItems.value.filter(i => i.bestLocation === 'structure').length
})

// Low coverage items count for footer
const lowCoverageItems = computed(() => {
  return mergedItems.value.filter(i => {
    if (i.sellCoverage == null) return false
    return i.sellCoverage < 0.5
  }).length
})

// -- Methods --

function toggleConfig(): void {
  configExpanded.value = !configExpanded.value
}

async function analyze(): Promise<void> {
  if (!hasInput.value) return

  isLoading.value = true
  error.value = ''
  appraisalResult.value = null
  shoppingResult.value = null
  shareUrl.value = null

  try {
    // Call both endpoints in parallel
    const [appraisalRes, shoppingRes] = await Promise.all([
      authFetch('/api/shopping-list/appraise', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: inputText.value }),
      }),
      authFetch('/api/shopping-list/parse', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          text: inputText.value,
          transportCost: transportCostPerM3.value,
          structureId: selectedStructure.value.id,
        }),
      }),
    ])

    if (!appraisalRes.ok) {
      const data = await safeJsonParse<{ error?: string }>(appraisalRes).catch(() => ({}))
      throw new Error((data as { error?: string }).error || 'Appraisal failed')
    }

    appraisalResult.value = await safeJsonParse<AppraisalResponse>(appraisalRes)

    if (shoppingRes.ok) {
      shoppingResult.value = await safeJsonParse<ShoppingResponse>(shoppingRes)
    }

    // Format detection feedback
    if (selectedFormat.value === 'auto' && appraisalResult.value && appraisalResult.value.items.length > 0) {
      detectedFormat.value = 'multibuy'
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'An error occurred'
  } finally {
    isLoading.value = false
  }
}

function clear(): void {
  inputText.value = ''
  appraisalResult.value = null
  shoppingResult.value = null
  error.value = ''
  shareUrl.value = null
  selectedFormat.value = 'auto'
  detectedFormat.value = null
}

async function syncStructureMarket(): Promise<void> {
  const structureId = shoppingResult.value?.structureId || selectedStructure.value.id
  if (!structureId) return

  isSyncing.value = true
  try {
    const response = await authFetch('/api/shopping-list/sync-structure-market', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ structureId }),
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response).catch(() => ({}))
      throw new Error((data as { error?: string }).error || 'Sync failed')
    }

    // Re-analyze after sync
    await analyze()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Sync failed'
  } finally {
    isSyncing.value = false
  }
}

async function shareAppraisal(): Promise<void> {
  if (!appraisalResult.value) return

  isSharing.value = true
  shareUrl.value = null

  try {
    const response = await authFetch('/api/shopping-list/share', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        items: appraisalResult.value.items,
        notFound: appraisalResult.value.notFound,
        totals: appraisalResult.value.totals,
        transportCostPerM3: transportCostPerM3.value,
        structureId: selectedStructure.value.id,
        structureName: selectedStructure.value.name || null,
      }),
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response).catch(() => ({}))
      throw new Error((data as { error?: string }).error || 'Failed to create share link')
    }

    const data = await safeJsonParse<{ shareUrl: string }>(response)
    shareUrl.value = data.shareUrl

    // Auto-copy to clipboard
    if (data.shareUrl) {
      navigator.clipboard.writeText(data.shareUrl)
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to share'
  } finally {
    isSharing.value = false
  }
}

function formatRelativeTime(isoDate: string | null): string {
  if (!isoDate) return ''
  const date = new Date(isoDate)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMins / 60)
  if (diffMins < 1) return t('common.time.justNow')
  if (diffMins < 60) return t('common.time.minutesAgo', { minutes: diffMins })
  if (diffHours < 24) return t('common.time.hoursAgo', { hours: diffHours })
  return formatTimeSince(isoDate)
}
</script>

<template>
  <MainLayout>
    <div class="flex flex-col gap-6">

      <!-- HEADER -->
      <div>
        <h1 class="text-2xl font-bold bg-linear-to-r from-cyan-400 to-blue-400 bg-clip-text text-transparent">
          {{ t('appraisal.title') }}
        </h1>
        <p class="text-slate-400 text-sm mt-1">
          {{ t('appraisal.subtitle') }}
        </p>
      </div>

      <!-- INPUT SECTION -->
      <AppraisalInputPanel
        :input-text="inputText"
        :selected-format="selectedFormat"
        :detected-format="detectedFormat"
        :selected-structure="selectedStructure"
        :is-loading="isLoading"
        :has-input="hasInput"
        :has-results="hasResults"
        @update:input-text="inputText = $event"
        @update:selected-format="selectedFormat = $event"
        @update:detected-format="detectedFormat = $event"
        @update:selected-structure="selectedStructure = $event"
        @analyze="analyze"
        @clear="clear"
      />

      <!-- Error -->
      <ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />

      <!-- Not Found Items -->
      <div
        v-if="appraisalResult && appraisalResult.notFound.length > 0"
        class="bg-yellow-900/30 border border-yellow-500/30 rounded-xl p-4"
      >
        <h3 class="text-yellow-400 font-medium mb-2">{{ t('shopping.notFound', { count: appraisalResult.notFound.length }) }}</h3>
        <p class="text-yellow-300/70 text-sm">{{ appraisalResult.notFound.join(', ') }}</p>
      </div>

      <!-- Price Error -->
      <div v-if="appraisalResult?.priceError" class="bg-yellow-900/30 border border-yellow-500/30 rounded-xl p-4 text-yellow-400">
        {{ appraisalResult.priceError }}
      </div>

      <!-- KPI CARDS -->
      <div v-if="hasResults" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Sell Value -->
        <div class="bg-slate-900 rounded-xl p-5 border border-slate-800 border-t-2 border-t-cyan-400 relative overflow-hidden shadow-[0_-2px_12px_-4px_rgba(6,182,212,0.15)]">
          <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1.5">
            <svg class="w-3 h-3 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
            {{ t('appraisal.sellValue') }}
          </p>
          <p class="text-[22px] font-bold text-cyan-400 font-mono">{{ formatIsk(sellValue) }}</p>
          <p
            v-if="appraisalResult?.totals.sellTotalWeighted != null"
            class="text-[11px] text-slate-600 font-mono mt-0.5"
          >
            {{ t('appraisal.bestPrice') }}: {{ formatIsk(sellValueBest) }}
          </p>
          <p class="text-[11px] text-slate-500 mt-1">{{ t('appraisal.sellValueDesc') }}</p>
        </div>

        <!-- Buy Value -->
        <div class="bg-slate-900 rounded-xl p-5 border border-slate-800 border-t-2 border-t-amber-500 relative overflow-hidden shadow-[0_-2px_12px_-4px_rgba(245,158,11,0.15)]">
          <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1.5">
            <svg class="w-3 h-3 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" />
            </svg>
            {{ t('appraisal.buyValue') }}
          </p>
          <p class="text-[22px] font-bold text-amber-500 font-mono">{{ formatIsk(buyValue) }}</p>
          <p
            v-if="appraisalResult?.totals.buyTotalWeighted != null"
            class="text-[11px] text-slate-600 font-mono mt-0.5"
          >
            {{ t('appraisal.bestPrice') }}: {{ formatIsk(buyValueBest) }}
          </p>
          <p class="text-[11px] text-slate-500 mt-1">{{ t('appraisal.buyValueDesc') }}</p>
        </div>

        <!-- Total Volume -->
        <div class="bg-slate-900 rounded-xl p-5 border border-slate-800 border-t-2 border-t-indigo-500 relative overflow-hidden shadow-[0_-2px_12px_-4px_rgba(99,102,241,0.15)]">
          <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1.5">
            <svg class="w-3 h-3 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            {{ t('appraisal.totalVolume') }}
          </p>
          <p class="text-[22px] font-bold text-indigo-400 font-mono">
            {{ formatNumber(totalVolume) }} m<sup class="text-xs">3</sup>
          </p>
          <p class="text-[11px] text-slate-500 mt-2">{{ t('appraisal.itemsAppraised', { count: itemCount }) }}</p>
        </div>
      </div>

      <!-- STRUCTURE COMPARISON PANEL (collapsible) -->
      <div
        v-if="hasResults && hasStructureResults"
        class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden"
      >
        <!-- Toggle header -->
        <button
          @click="toggleConfig"
          class="w-full px-5 py-3.5 flex items-center gap-2.5 bg-transparent border-0 cursor-pointer text-slate-200"
        >
          <svg class="w-[18px] h-[18px] text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
          </svg>
          <span class="text-[13px] font-semibold">{{ t('appraisal.structureComparison') }}</span>
          <span class="text-[11px] text-slate-500 ml-1">-- {{ t('appraisal.structureComparisonDesc') }}</span>
          <span class="flex-1"></span>

          <!-- Summary when collapsed -->
          <span
            v-if="!configExpanded"
            class="text-xs text-violet-400 font-mono"
          >
            {{ shortStructureName }} &middot; {{ t('appraisal.bestPrice') }}: {{ formatIsk(bestPrice) }}
          </span>

          <svg
            class="w-4 h-4 text-slate-500 transition-transform duration-200"
            :class="{ 'rotate-180': configExpanded }"
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>

        <!-- Config content -->
        <div
          class="overflow-hidden transition-all duration-300 ease-in-out"
          :class="configExpanded ? 'max-h-[400px] opacity-100' : 'max-h-0 opacity-0'"
        >
          <div class="px-5 pb-5 border-t border-slate-800">
            <div class="flex gap-4 flex-wrap items-end pt-4">
              <!-- Transport cost -->
              <div>
                <label class="block text-[13px] font-medium text-slate-300 mb-1.5">
                  {{ t('shopping.transportCost') }}
                </label>
                <input
                  v-model.number="transportCostPerM3"
                  type="number"
                  min="0"
                  step="100"
                  class="w-28 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 text-[13px] focus:outline-hidden focus:border-cyan-500/50 font-mono"
                />
              </div>

              <!-- Sync button -->
              <button
                @click="syncStructureMarket"
                :disabled="isSyncing"
                class="px-3.5 py-2 bg-violet-500/15 border border-violet-500/30 rounded-lg text-violet-300 text-xs font-medium cursor-pointer flex items-center gap-1.5 whitespace-nowrap hover:bg-violet-500/25 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                <svg
                  class="w-3.5 h-3.5"
                  :class="{ 'animate-spin': isSyncing }"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                {{ isSyncing ? t('common.actions.syncing') : t('shopping.syncPrices') }}
              </button>
            </div>

            <!-- Cache info -->
            <div
              v-if="shoppingResult?.structureLastSync"
              class="mt-2.5 flex items-center gap-2"
            >
              <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="text-[11px] text-slate-500">
                {{ shortStructureName }} {{ t('appraisal.pricesUpdated') }} {{ formatRelativeTime(shoppingResult.structureLastSync) }}
              </span>
            </div>

            <!-- Import KPI sub-row -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
              <div class="bg-slate-800 rounded-lg px-4 py-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">{{ t('shopping.jitaPlusTransport') }}</p>
                <p class="text-lg font-semibold text-cyan-400 font-mono">{{ formatIsk(jitaPlusTransport) }}</p>
              </div>
              <div class="bg-slate-800 rounded-lg px-4 py-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">{{ shortStructureName }} ({{ t('appraisal.weighted') }})</p>
                <p class="text-lg font-semibold text-violet-400 font-mono">{{ formatIsk(structureTotal) }}</p>
              </div>
              <div class="bg-slate-800 rounded-lg px-4 py-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">{{ t('appraisal.bestPriceMixed') }}</p>
                <p class="text-lg font-bold text-green-400 font-mono">{{ formatIsk(bestPrice) }}</p>
              </div>
              <div class="bg-slate-800 rounded-lg px-4 py-3">
                <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">{{ t('appraisal.structureCoverage') }}</p>
                <div class="flex items-baseline gap-1.5">
                  <p class="text-lg font-semibold text-amber-500 font-mono">
                    {{ structureCoverageCount.covered }} / {{ structureCoverageCount.total }}
                  </p>
                  <span class="text-[11px] text-slate-500">{{ t('appraisal.itemsFullyCovered') }}</span>
                </div>
                <div class="h-1 rounded-full bg-slate-700/50 mt-1.5 overflow-hidden">
                  <div
                    class="h-full rounded-full transition-all duration-300"
                    :class="structureCoveragePercent >= 100 ? 'bg-emerald-500' : structureCoveragePercent >= 50 ? 'bg-amber-500' : 'bg-red-500'"
                    :style="{ width: structureCoveragePercent + '%' }"
                  ></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- COMBINED RESULTS TABLE -->
      <AppraisalResultsTable
        v-if="hasResults"
        :items="mergedItems"
        :has-structure-results="hasStructureResults"
        :short-structure-name="shortStructureName"
        :item-count="itemCount"
        :jita-item-count="jitaItemCount"
        :structure-item-count="structureItemCount"
        :total-volume="totalVolume"
        :sell-value="sellValue"
        :sell-value-best="sellValueBest"
        :buy-value="buyValue"
        :buy-value-best="buyValueBest"
        :jita-plus-transport="jitaPlusTransport"
        :structure-total="structureTotal"
        :best-price="bestPrice"
        :low-coverage-items="lowCoverageItems"
        :has-sell-total-weighted="appraisalResult?.totals.sellTotalWeighted != null"
        :has-buy-total-weighted="appraisalResult?.totals.buyTotalWeighted != null"
      />

      <!-- ACTION BAR -->
      <AppraisalActions
        v-if="hasResults"
        :merged-items="mergedItems"
        :has-structure-results="hasStructureResults"
        :short-structure-name="shortStructureName"
        :jita-item-count="jitaItemCount"
        :structure-item-count="structureItemCount"
        :is-sharing="isSharing"
        :share-url="shareUrl"
        @share="shareAppraisal"
      />

    </div>
  </MainLayout>
</template>

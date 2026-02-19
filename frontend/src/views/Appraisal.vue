<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { authFetch, safeJsonParse } from '@/services/api'
import MainLayout from '@/layouts/MainLayout.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

// -- Types --

interface AppraisalItem {
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

interface AppraisalTotals {
  sellTotal: number
  buyTotal: number
  volume: number
  sellTotalWeighted: number | null
  buyTotalWeighted: number | null
}

interface AppraisalResponse {
  items: AppraisalItem[]
  notFound: string[]
  totals: AppraisalTotals
  priceError: string | null
}

interface ShoppingItem {
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

interface ShoppingResponse {
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

interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
  solarSystemId: number | null
}

type FormatType = 'auto' | 'multibuy' | 'cargo_scan' | 'eft' | 'dscan' | 'contract' | 'killmail' | 'inventory'

interface FormatOption {
  key: FormatType
  labelKey: string
}

// Merged item combining appraisal + shopping data
interface MergedItem {
  typeId: number
  typeName: string
  quantity: number
  volume: number
  totalVolume: number
  // Jita sell
  sellPrice: number | null
  sellPriceWeighted: number | null
  sellTotal: number | null
  sellTotalWeighted: number | null
  sellCoverage: number | null
  // Jita buy
  buyPrice: number | null
  buyPriceWeighted: number | null
  buyTotal: number | null
  buyTotalWeighted: number | null
  buyCoverage: number | null
  // Daily volume
  avgDailyVolume: number | null
  // Jita + import
  jitaWithImport: number | null
  // Structure
  structureTotal: number | null
  structureCoverage: number | null
  structureCoverageQty: number | null
  // Best
  bestLocation: 'jita' | 'structure' | null
}

// -- Composables --

const { t } = useI18n()
const { formatIsk, formatNumber, formatTimeSince } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

// -- Constants --

const FORMAT_OPTIONS: FormatOption[] = [
  { key: 'auto', labelKey: 'appraisal.formatAutoDetect' },
  { key: 'multibuy', labelKey: 'appraisal.formatMultibuy' },
  { key: 'cargo_scan', labelKey: 'appraisal.formatCargoScan' },
  { key: 'eft', labelKey: 'appraisal.formatEft' },
  { key: 'dscan', labelKey: 'appraisal.formatDscan' },
  { key: 'contract', labelKey: 'appraisal.formatContract' },
  { key: 'killmail', labelKey: 'appraisal.formatKillmail' },
  { key: 'inventory', labelKey: 'appraisal.formatInventory' },
]

// -- State --

const inputText = ref('')
const transportCostPerM3 = ref(1200)
const selectedFormat = ref<FormatType>('auto')
const detectedFormat = ref<FormatType | null>(null)
const selectedStructure = ref<{ id: number | null; name: string }>({ id: null, name: '' })
const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
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

// Copy states
const copiedJita = ref(false)
const copiedStructure = ref(false)
const copiedTable = ref(false)
const copiedShare = ref(false)

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
      structureCoverage: null, // Structure coverage ratio not available from API yet
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

function selectFormat(format: FormatType): void {
  selectedFormat.value = format
  if (format !== 'auto') {
    detectedFormat.value = null
  }
}

function formatLabelForDetection(format: FormatType): string {
  const option = FORMAT_OPTIONS.find(o => o.key === format)
  return option ? t(option.labelKey) : format
}

// Debounced structure search
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(structureSearchQuery, (query) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (query.length < 3) {
    structureSearchResults.value = []
    return
  }
  searchTimeout = setTimeout(() => {
    searchStructures(query)
  }, 300)
})

async function searchStructures(query: string): Promise<void> {
  isSearchingStructures.value = true
  try {
    const response = await authFetch(`/api/shopping-list/search-structures?q=${encodeURIComponent(query)}`)
    if (response.ok) {
      const data = await safeJsonParse<{ results: StructureSearchResult[] }>(response)
      structureSearchResults.value = data.results
      showStructureDropdown.value = true
    }
  } catch (e) {
    console.error('Structure search failed:', e)
  } finally {
    isSearchingStructures.value = false
  }
}

function selectStructure(structure: StructureSearchResult): void {
  selectedStructure.value = { id: structure.id, name: structure.name }
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureDropdown.value = false
}

function clearStructure(): void {
  selectedStructure.value = { id: null, name: '' }
  structureSearchQuery.value = ''
  structureSearchResults.value = []
}

function onStructureInputBlur(): void {
  setTimeout(() => {
    showStructureDropdown.value = false
  }, 200)
}

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

// Coverage helpers
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

// Copy functions
function copyJitaMultibuy(): void {
  const items = mergedItems.value.filter(i => i.bestLocation === 'jita' || i.bestLocation === null)
  const text = items.map(i => `${i.typeName}\t${i.quantity}`).join('\n')
  navigator.clipboard.writeText(text)
  copiedJita.value = true
  setTimeout(() => copiedJita.value = false, 2000)
}

function copyStructureMultibuy(): void {
  const items = mergedItems.value.filter(i => i.bestLocation === 'structure')
  const text = items.map(i => `${i.typeName}\t${i.quantity}`).join('\n')
  navigator.clipboard.writeText(text)
  copiedStructure.value = true
  setTimeout(() => copiedStructure.value = false, 2000)
}

function copyTable(): void {
  const header = ['Item', 'Qty', 'Volume', 'Jita Sell', 'Jita Buy', 'Jita+Import', 'Structure', 'Buy at'].join('\t')
  const rows = mergedItems.value.map(i => [
    i.typeName,
    i.quantity,
    formatNumber(i.totalVolume) + ' m3',
    i.sellTotalWeighted != null ? formatNumber(i.sellTotalWeighted) : '-',
    i.buyTotalWeighted != null ? formatNumber(i.buyTotalWeighted) : '-',
    i.jitaWithImport != null ? formatNumber(i.jitaWithImport) : '-',
    i.structureTotal != null ? formatNumber(i.structureTotal) : '-',
    i.bestLocation ?? '-',
  ].join('\t'))
  const text = [header, ...rows].join('\n')
  navigator.clipboard.writeText(text)
  copiedTable.value = true
  setTimeout(() => copiedTable.value = false, 2000)
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

    copiedShare.value = true
    setTimeout(() => copiedShare.value = false, 3000)
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

// Display helpers
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
      <div class="bg-slate-900 rounded-xl p-6 border border-cyan-500/15">
        <div class="flex flex-col gap-4">

          <!-- Label -->
          <label class="text-sm font-medium text-slate-300">
            {{ t('shopping.pasteLabel') }}
          </label>

          <!-- Textarea -->
          <textarea
            v-model="inputText"
            rows="6"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-3 text-slate-200 placeholder-slate-500 focus:outline-hidden font-mono text-[13px] resize-y leading-relaxed"
            :class="{ 'ring-1 ring-cyan-500/40 border-cyan-500/40 shadow-[0_0_20px_-6px_rgba(6,182,212,0.15)]': inputText.trim().length > 0 }"
            placeholder="Tritanium&#9;10000&#10;Pyerite&#9;5000&#10;Megacyte 200&#10;200x Nocxium&#10;..."
          ></textarea>

          <!-- Format detection chips -->
          <div class="flex items-center gap-2 flex-wrap -mt-2">
            <span class="text-[11px] text-slate-500 mr-1">{{ t('appraisal.formatLabel') }}</span>
            <button
              v-for="fmt in FORMAT_OPTIONS"
              :key="fmt.key"
              @click="selectFormat(fmt.key)"
              class="px-3 py-1 rounded-md text-[11px] font-medium transition-all border cursor-pointer tracking-wide"
              :class="[
                selectedFormat === fmt.key && fmt.key === 'auto' && !detectedFormat
                  ? 'bg-cyan-500/12 text-cyan-400 border-cyan-500/25'
                  : detectedFormat === fmt.key && selectedFormat === 'auto'
                    ? 'bg-green-500/12 text-green-400 border-green-500/25'
                    : selectedFormat === fmt.key && fmt.key !== 'auto'
                      ? 'bg-cyan-500/12 text-cyan-400 border-cyan-500/25'
                      : 'bg-slate-800/60 text-slate-500 border-transparent hover:text-slate-400 hover:bg-slate-700/60'
              ]"
            >
              {{ t(fmt.labelKey) }}
            </button>
            <div class="flex-1"></div>
            <span
              v-if="detectedFormat && selectedFormat === 'auto'"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-medium bg-green-500/10 text-green-400 border border-green-500/20"
            >
              <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
              {{ t('appraisal.formatDetected', { format: formatLabelForDetection(detectedFormat) }) }}
            </span>
          </div>

          <!-- Action row -->
          <div class="flex items-center gap-3 flex-wrap">

            <!-- Structure selector -->
            <div class="relative min-w-[300px] max-w-[420px]">
              <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
              <input
                v-model="structureSearchQuery"
                type="text"
                :placeholder="selectedStructure.id ? selectedStructure.name : t('shopping.searchStructure')"
                @focus="showStructureDropdown = true"
                @blur="onStructureInputBlur"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-8 pr-8 py-2 text-slate-200 text-[13px] focus:outline-hidden focus:border-cyan-500/50 placeholder-slate-400"
                :class="{ 'border-cyan-500/50 text-cyan-400 placeholder-cyan-400': selectedStructure.id }"
              />
              <button
                v-if="selectedStructure.id || structureSearchQuery"
                @mousedown.prevent="clearStructure"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-200 p-0.5"
                :title="t('shopping.clearStructure')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <LoadingSpinner
                v-else-if="isSearchingStructures"
                size="sm"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-cyan-400"
              />

              <!-- Dropdown -->
              <div
                v-if="showStructureDropdown && (structureSearchResults.length > 0 || (structureSearchQuery.length >= 3 && !isSearchingStructures))"
                class="absolute z-50 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-60 overflow-y-auto"
              >
                <button
                  v-for="struct in structureSearchResults"
                  :key="struct.id"
                  @mousedown.prevent="selectStructure(struct)"
                  class="w-full px-3 py-2 text-left text-slate-200 hover:bg-slate-700/50 transition-colors border-b border-slate-800 last:border-0"
                >
                  <div class="font-medium truncate">{{ struct.name }}</div>
                </button>
                <div
                  v-if="structureSearchQuery.length >= 3 && structureSearchResults.length === 0 && !isSearchingStructures"
                  class="px-3 py-2 text-slate-400 text-sm"
                >
                  {{ t('shopping.noStructureFound') }}
                </div>
              </div>
            </div>

            <div class="flex-1"></div>

            <!-- Clear -->
            <button
              @click="clear"
              :disabled="!hasInput && !hasResults"
              class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-slate-200 text-[13px] font-medium transition-colors"
            >
              {{ t('common.actions.clear') }}
            </button>

            <!-- Analyze button -->
            <button
              @click="analyze"
              :disabled="!hasInput || isLoading"
              class="px-6 py-2 rounded-lg text-white text-[13px] font-semibold transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
              :class="isLoading ? 'bg-cyan-700' : 'btn-analyze'"
            >
              <LoadingSpinner v-if="isLoading" size="sm" />
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
              {{ isLoading ? t('appraisal.appraising') : t('appraisal.analyze') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Error -->
      <div v-if="error" class="bg-red-900/30 border border-red-500/30 rounded-xl p-4 text-red-400">
        {{ error }}
      </div>

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
      <div
        v-if="hasResults"
        class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden"
      >
        <!-- Table header bar -->
        <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
          <h3 class="text-[15px] font-semibold text-slate-200">
            Items ({{ itemCount }})
          </h3>
          <div v-if="hasStructureResults" class="flex items-center gap-4 text-[13px]">
            <span class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-cyan-500"></span>
              <span class="text-slate-400">Jita ({{ jitaItemCount }})</span>
            </span>
            <span class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-violet-500"></span>
              <span class="text-slate-400">{{ shortStructureName }} ({{ structureItemCount }})</span>
            </span>
          </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
          <table class="w-full text-[13px] border-collapse">
            <thead>
              <!-- Group header row -->
              <tr v-if="hasStructureResults" class="bg-slate-950/80">
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
                    <span class="text-[11px] font-bold uppercase tracking-widest text-violet-400">{{ shortStructureName }}</span>
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
                  :class="hasStructureResults ? 'border-l-2 border-cyan-500/15 bg-cyan-500/3' : ''"
                >
                  {{ t('appraisal.unitPrice') }}
                </th>
                <th
                  class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-400"
                  :class="hasStructureResults ? 'bg-cyan-500/3' : ''"
                >
                  {{ t('appraisal.weightedTotal') }}
                </th>
                <th
                  v-if="hasStructureResults"
                  class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-cyan-300 border-r-2 border-cyan-500/15 bg-cyan-500/3"
                  :title="t('appraisal.importTooltip')"
                >
                  + Import
                </th>
                <!-- Structure column -->
                <th
                  v-if="hasStructureResults"
                  class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-violet-300 border-l-2 border-r-2 border-violet-500/15 bg-violet-500/3"
                  :title="t('appraisal.weightedSellTooltip')"
                >
                  {{ t('appraisal.weightedSell') }}
                </th>
                <th v-if="hasStructureResults" class="px-4 py-2.5 text-center text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ t('shopping.buyAt') }}</th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="item in mergedItems"
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
                  :class="hasStructureResults ? 'border-l-2 border-cyan-500/8 bg-cyan-500/3' : ''"
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
                  :class="hasStructureResults ? 'bg-cyan-500/3' : ''"
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
                  v-if="hasStructureResults"
                  class="px-4 py-2.5 text-right font-mono border-r-2 border-cyan-500/8 bg-cyan-500/3"
                >
                  <span v-if="item.jitaWithImport != null" class="text-cyan-400 font-medium">
                    {{ formatNumber(item.jitaWithImport) }}
                  </span>
                  <span v-else class="text-slate-500">-</span>
                </td>

                <!-- Structure weighted price -->
                <td
                  v-if="hasStructureResults"
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
                <td v-if="hasStructureResults" class="px-4 py-2.5 text-center">
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
                    {{ shortStructureName }}
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
                  {{ formatNumber(totalVolume) }} m<sup>3</sup>
                </td>
                <!-- Unit Price -->
                <td
                  class="px-4 py-3 text-right text-slate-500"
                  :class="hasStructureResults ? 'border-l-2 border-cyan-500/8 bg-cyan-500/3' : ''"
                >--</td>
                <!-- Weighted Total (stacked) -->
                <td
                  class="px-4 py-3 text-right font-mono"
                  :class="hasStructureResults ? 'bg-cyan-500/3' : ''"
                >
                  <div class="flex items-center justify-end gap-1.5">
                    <span class="text-[9px] font-semibold text-cyan-700 uppercase tracking-wider">Sell</span>
                    <span class="text-cyan-400 font-bold text-sm">{{ formatIsk(sellValue) }}</span>
                  </div>
                  <div v-if="appraisalResult?.totals.sellTotalWeighted != null" class="text-[10px] text-slate-600 mt-0.5 text-right">
                    {{ formatIsk(sellValueBest) }}
                  </div>
                  <div class="flex items-center justify-end gap-1.5 mt-1.5">
                    <span class="text-[9px] font-semibold text-amber-700 uppercase tracking-wider">Buy</span>
                    <span class="text-amber-500 font-bold text-sm">{{ formatIsk(buyValue) }}</span>
                  </div>
                  <div v-if="appraisalResult?.totals.buyTotalWeighted != null" class="text-[10px] text-slate-600 mt-0.5 text-right">
                    {{ formatIsk(buyValueBest) }}
                  </div>
                </td>
                <!-- + Import -->
                <td
                  v-if="hasStructureResults"
                  class="px-4 py-3 text-right font-mono border-r-2 border-cyan-500/8 bg-cyan-500/3"
                >
                  <span class="text-cyan-400 font-bold text-sm">{{ formatIsk(jitaPlusTransport) }}</span>
                </td>
                <!-- Structure -->
                <td
                  v-if="hasStructureResults"
                  class="px-4 py-3 text-right font-mono border-l-2 border-r-2 border-violet-500/8 bg-violet-500/3"
                >
                  <span class="text-violet-400 font-bold text-sm">{{ formatIsk(structureTotal) }}</span>
                  <div v-if="lowCoverageItems > 0" class="text-[10px] text-amber-500 mt-1">
                    {{ lowCoverageItems }} {{ t('appraisal.itemsLowCoverage') }}
                  </div>
                </td>
                <!-- Best -->
                <td v-if="hasStructureResults" class="px-4 py-3 text-center font-mono">
                  <span class="text-green-400 font-bold text-sm">{{ formatIsk(bestPrice) }}</span>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- ACTION BAR -->
      <div v-if="hasResults" class="flex flex-wrap gap-3 items-center">
        <!-- Copy Jita Multibuy -->
        <button
          v-if="hasStructureResults && jitaItemCount > 0"
          @click="copyJitaMultibuy"
          class="flex items-center gap-2 px-4 py-2 bg-cyan-500/15 border border-cyan-500/30 rounded-lg text-cyan-300 text-[13px] font-medium cursor-pointer hover:bg-cyan-500/25 transition-colors"
        >
          <svg v-if="!copiedJita" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          {{ copiedJita ? t('shopping.copied') : t('shopping.copyJita', { count: jitaItemCount }) }}
        </button>

        <!-- Copy Structure Multibuy -->
        <button
          v-if="hasStructureResults && structureItemCount > 0"
          @click="copyStructureMultibuy"
          class="flex items-center gap-2 px-4 py-2 bg-violet-500/15 border border-violet-500/30 rounded-lg text-violet-300 text-[13px] font-medium cursor-pointer hover:bg-violet-500/25 transition-colors"
        >
          <svg v-if="!copiedStructure" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          {{ copiedStructure ? t('shopping.copied') : t('shopping.copyStructure', { name: shortStructureName, count: structureItemCount }) }}
        </button>

        <!-- Copy Table -->
        <button
          @click="copyTable"
          class="flex items-center gap-2 px-4 py-2 bg-slate-700/50 border border-slate-700 rounded-lg text-slate-400 text-[11px] font-medium cursor-pointer hover:text-slate-200 hover:bg-slate-700 transition-colors"
        >
          <svg v-if="!copiedTable" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          <svg v-else class="w-3.5 h-3.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          {{ copiedTable ? t('shopping.copied') : t('appraisal.copyTable') }}
        </button>

        <div class="flex-1"></div>

        <!-- Share -->
        <button
          @click="shareAppraisal"
          :disabled="isSharing"
          class="flex items-center gap-2 px-4 py-2 bg-emerald-500/15 border border-emerald-500/30 rounded-lg text-emerald-300 text-[13px] font-medium cursor-pointer hover:bg-emerald-500/25 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          <LoadingSpinner v-if="isSharing" size="sm" />
          <svg v-else-if="!copiedShare" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
          <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          {{ isSharing ? t('shopping.sharing') : copiedShare ? t('shopping.linkCopied') : t('common.actions.share') }}
        </button>
      </div>

    </div>
  </MainLayout>
</template>

<style scoped>
/* Animated gradient on Analyze button */
.btn-analyze {
  background: linear-gradient(135deg, #0e7490, #0891b2, #06b6d4);
  background-size: 200% 200%;
  animation: gradient-shift 3s ease infinite;
}

@keyframes gradient-shift {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}

/* Shimmer effect on "Buy at" badges */
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

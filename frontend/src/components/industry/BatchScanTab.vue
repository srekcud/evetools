<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useScannerStore } from '@/stores/industry/scanner'
import { useIndustryStore } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { apiRequest } from '@/services/api'
import type { BatchScanItem } from '@/stores/industry/types'

type UserSettingsResponse = {
  preferredMarketStructureId: number | null
  preferredMarketStructureName: string | null
  marketStructures: Array<{ id: number; name: string }>
}

const { t } = useI18n()
const scannerStore = useScannerStore()
const industryStore = useIndustryStore()
const { formatIsk, formatNumber, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

// Filters
const categoryFilter = ref('all')
const minMarginFilter = ref<number | null>(5)
const minDailyVolFilter = ref<number | null>(50)
const sellVenueFilter = ref('structure')
const structureIdFilter = ref<number | null>(null)
const marketStructures = ref<Array<{ id: number; name: string }>>([])

// Sorting
type SortKey = 'typeName' | 'categoryLabel' | 'marginPercent' | 'profitPerUnit' | 'dailyVolume' | 'iskPerDay' | 'materialCost' | 'exportCost' | 'importCost' | 'sellPrice' | 'meUsed' | 'activityType'
const sortKey = ref<SortKey>('iskPerDay')
const sortAsc = ref(false)

// Copy to clipboard
const copied = ref(false)

// Pagination
const PAGE_SIZE = 50
const currentPage = ref(1)

const CATEGORY_OPTIONS = [
  { value: 'all', label: 'All' },
  { value: 't1_ships', label: 'T1 Ships' },
  { value: 't2_ships', label: 'T2 Ships' },
  { value: 't1_modules', label: 'T1 Modules' },
  { value: 't2_modules', label: 'T2 Modules' },
  { value: 'capitals', label: 'Capitals' },
  { value: 'components', label: 'Components' },
  { value: 'ammo_charges', label: 'Ammo & Charges' },
  { value: 'drones', label: 'Drones' },
  { value: 'rigs', label: 'Rigs' },
  { value: 'reactions', label: 'Reactions' },
]

const CATEGORY_LABEL_MAP: Record<string, string> = Object.fromEntries(
  CATEGORY_OPTIONS.map(o => [o.value, o.label]),
)

const SELL_VENUE_OPTIONS = [
  { value: 'jita', label: 'Jita' },
  { value: 'structure', label: 'Structure' },
  { value: 'contracts', label: 'Public Contracts' },
]

// Client-side filtered + sorted results
const filteredResults = computed((): BatchScanItem[] => {
  let items = [...(scannerStore.scanResults ?? [])]

  if (categoryFilter.value !== 'all') {
    const label = CATEGORY_LABEL_MAP[categoryFilter.value]
    items = items.filter(i => i.categoryLabel === label)
  }
  if (minMarginFilter.value != null) {
    items = items.filter(i => i.marginPercent >= minMarginFilter.value!)
  }
  if (minDailyVolFilter.value != null) {
    items = items.filter(i => i.dailyVolume >= minDailyVolFilter.value!)
  }

  items.sort((a, b) => {
    const aVal = a[sortKey.value]
    const bVal = b[sortKey.value]
    if (typeof aVal === 'string' && typeof bVal === 'string') {
      return sortAsc.value ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal)
    }
    const diff = (aVal as number) - (bVal as number)
    return sortAsc.value ? diff : -diff
  })

  return items
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredResults.value.length / PAGE_SIZE)))

const paginatedResults = computed(() => {
  const start = (currentPage.value - 1) * PAGE_SIZE
  return filteredResults.value.slice(start, start + PAGE_SIZE)
})

// KPI computations
const profitableCount = computed(() => filteredResults.value.filter(i => i.marginPercent > 0).length)
const profitablePercent = computed(() => {
  if (filteredResults.value.length === 0) return 0
  return Math.round((profitableCount.value / filteredResults.value.length) * 100)
})

const bestMarginItem = computed(() => {
  if (filteredResults.value.length === 0) return null
  return filteredResults.value.reduce((best, item) =>
    item.marginPercent > best.marginPercent ? item : best,
  )
})

const bestIskDayItem = computed(() => {
  if (filteredResults.value.length === 0) return null
  return filteredResults.value.reduce((best, item) =>
    item.iskPerDay > best.iskPerDay ? item : best,
  )
})

// Visible pagination buttons
const visiblePages = computed(() => {
  const pages: (number | '...')[] = []
  const total = totalPages.value
  const current = currentPage.value

  if (total <= 7) {
    for (let i = 1; i <= total; i++) pages.push(i)
  } else {
    pages.push(1)
    if (current > 3) pages.push('...')
    const start = Math.max(2, current - 1)
    const end = Math.min(total - 1, current + 1)
    for (let i = start; i <= end; i++) pages.push(i)
    if (current < total - 2) pages.push('...')
    pages.push(total)
  }
  return pages
})

function toggleSort(key: SortKey): void {
  if (sortKey.value === key) {
    sortAsc.value = !sortAsc.value
  } else {
    sortKey.value = key
    sortAsc.value = false
  }
  currentPage.value = 1
}

function onScan(): void {
  currentPage.value = 1
  scannerStore.fetchBatchScan({
    category: categoryFilter.value,
    minMargin: minMarginFilter.value,
    minDailyVolume: minDailyVolFilter.value,
    sellVenue: sellVenueFilter.value,
    structureId: structureIdFilter.value,
  })
}

async function copyToMultibuy(): Promise<void> {
  const lines = filteredResults.value
    .filter(item => item.marginPercent > 0)
    .map(item => `${item.typeName}\t1`)
    .join('\n')

  try {
    await navigator.clipboard.writeText(lines)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    // silently fail
  }
}

function onRowClick(item: BatchScanItem): void {
  industryStore.navigationIntent = { target: 'margins', typeId: item.typeId }
}

function formatMargin(percent: number): string {
  const sign = percent >= 0 ? '+' : ''
  return `${sign}${percent.toFixed(1)}%`
}

function categoryBadgeClasses(label: string): string {
  switch (label) {
    case 'T2': return 'bg-blue-500/15 text-blue-400'
    case 'Capital': return 'bg-amber-500/15 text-amber-400'
    default: return 'bg-slate-700/50 text-slate-400'
  }
}

function activityBadgeClasses(activity: string): string {
  if (activity.toLowerCase().includes('reaction')) return 'bg-purple-500/10 text-purple-400'
  return 'bg-cyan-500/10 text-cyan-400'
}

onMounted(async () => {
  try {
    const settings = await apiRequest<UserSettingsResponse>('/me/settings')
    if (settings.marketStructures?.length > 0) {
      marketStructures.value = settings.marketStructures
      structureIdFilter.value = settings.preferredMarketStructureId ?? settings.marketStructures[0].id
    } else if (settings.preferredMarketStructureId) {
      marketStructures.value = [{ id: settings.preferredMarketStructureId, name: settings.preferredMarketStructureName ?? 'Unknown' }]
      structureIdFilter.value = settings.preferredMarketStructureId
    }
  } catch { /* best-effort */ }

  if (scannerStore.scanResults?.length === 0 && !scannerStore.scanLoading) {
    onScan()
  }
})
</script>

<template>
  <div class="space-y-5">
    <!-- Header with scan button -->
    <div class="flex items-center justify-between">
      <div>
        <h3 class="text-lg font-bold text-slate-100 flex items-center gap-2">
          <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
          {{ t('industry.scanner.batch.title') }}
        </h3>
        <p class="text-sm text-slate-500 mt-0.5">{{ t('industry.scanner.batch.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-4">
        <div v-if="scannerStore.lastScanAt && !isNaN(scannerStore.lastScanAt.getTime())" class="text-xs text-slate-500 text-right">
          <div>{{ t('industry.scanner.batch.lastScan') }}</div>
          <div class="font-mono text-slate-400">{{ formatDateTime(scannerStore.lastScanAt.toISOString()) }}</div>
        </div>
        <button
          @click="onScan"
          :disabled="scannerStore.scanLoading"
          class="px-5 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-semibold flex items-center gap-2 transition-colors shadow-lg shadow-cyan-600/20 disabled:opacity-50"
        >
          <svg v-if="scannerStore.scanLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          {{ t('industry.scanner.batch.scanNow') }}
        </button>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-4">
      <div class="flex flex-wrap items-end gap-4">
        <!-- Category -->
        <div class="flex-1 min-w-[180px]">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.batch.category') }}</label>
          <select
            v-model="categoryFilter"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-cyan-500 transition-colors"
          >
            <option v-for="cat in CATEGORY_OPTIONS" :key="cat.value" :value="cat.value">
              {{ cat.value === 'all' ? t('industry.scanner.batch.allCategories') : cat.label }}
            </option>
          </select>
        </div>

        <!-- Min margin -->
        <div class="w-32">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.batch.minMargin') }}</label>
          <div class="flex items-center gap-1">
            <input
              v-model.number="minMarginFilter"
              type="number"
              min="0"
              max="100"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500 transition-colors"
            />
            <span class="text-xs text-slate-500">%</span>
          </div>
        </div>

        <!-- Min daily volume -->
        <div class="w-36">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.batch.minDailyVol') }}</label>
          <input
            v-model.number="minDailyVolFilter"
            type="number"
            min="0"
            placeholder="0"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500 transition-colors"
          />
        </div>

        <!-- Sell venue -->
        <div class="w-40">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.batch.sellVenue') }}</label>
          <select
            v-model="sellVenueFilter"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-cyan-500 transition-colors"
          >
            <option v-for="opt in SELL_VENUE_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>

        <!-- Structure (shown when venue = structure) -->
        <div v-if="sellVenueFilter === 'structure'" class="w-64">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.scanner.batch.structure') }}</label>
          <select
            v-model="structureIdFilter"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-cyan-500 transition-colors"
          >
            <option :value="null" disabled>{{ t('industry.scanner.batch.selectStructure') }}</option>
            <option
              v-for="struct in marketStructures"
              :key="struct.id"
              :value="struct.id"
            >
              {{ struct.name }}
            </option>
          </select>
        </div>

        <!-- Buildable only toggle (disabled, soon) -->
        <div class="flex items-center gap-2 pb-1">
          <div class="relative">
            <input type="checkbox" id="buildableToggle" class="sr-only" disabled />
            <div class="w-9 h-5 bg-slate-700 rounded-full opacity-40 cursor-not-allowed"></div>
            <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-slate-500 rounded-full opacity-40"></div>
          </div>
          <label for="buildableToggle" class="text-xs text-slate-600 cursor-not-allowed select-none">
            {{ t('industry.scanner.batch.buildableOnly') }}
            <span class="ml-1 text-[10px] px-1.5 py-0.5 bg-slate-800 text-slate-600 rounded uppercase tracking-wider">{{ t('industry.scanner.batch.buildableSoon') }}</span>
          </label>
        </div>
      </div>
    </div>

    <!-- Error -->
    <div v-if="scannerStore.scanError" class="p-4 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400">
      {{ scannerStore.scanError }}
    </div>

    <!-- Loading -->
    <div v-if="scannerStore.scanLoading" class="p-12 text-center">
      <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      <p class="text-slate-500">{{ t('industry.scanner.batch.scanNow') }}...</p>
    </div>

    <!-- Results -->
    <template v-else-if="scannerStore.scanResults?.length > 0">
      <!-- KPI cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Items Scanned -->
        <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-4">
          <div class="flex items-center gap-2 mb-2">
            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
            <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.batch.itemsScanned') }}</p>
          </div>
          <p class="text-2xl font-mono text-slate-100 font-bold">{{ formatNumber(scannerStore.scanResults.length, 0) }}</p>
          <p class="text-xs text-slate-600 font-mono mt-1">{{ t('industry.scanner.batch.allManufacturable') }}</p>
        </div>

        <!-- Profitable -->
        <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-4">
          <div class="flex items-center gap-2 mb-2">
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.batch.profitable') }}</p>
          </div>
          <div class="flex items-baseline gap-2">
            <p class="text-2xl font-mono text-emerald-400 font-bold">{{ formatNumber(profitableCount, 0) }}</p>
            <span class="text-sm font-mono text-emerald-400/60">({{ profitablePercent }}%)</span>
          </div>
          <p class="text-xs text-slate-600 font-mono mt-1">{{ t('industry.scanner.batch.profitableDesc') }}</p>
        </div>

        <!-- Best Margin -->
        <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-4">
          <div class="flex items-center gap-2 mb-2">
            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
            <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.batch.bestMargin') }}</p>
          </div>
          <p class="text-2xl font-mono text-amber-400 font-bold">
            {{ bestMarginItem ? formatMargin(bestMarginItem.marginPercent) : '---' }}
          </p>
          <div v-if="bestMarginItem" class="flex items-center gap-1.5 mt-1">
            <img
              :src="getTypeIconUrl(bestMarginItem.typeId, 32)"
              :alt="bestMarginItem.typeName"
              class="w-4 h-4 rounded-sm"
              @error="onImageError"
            />
            <p class="text-xs text-slate-400">{{ bestMarginItem.typeName }}</p>
          </div>
        </div>

        <!-- Best ISK/day -->
        <div class="bg-slate-900/50 border border-cyan-500/20 rounded-xl p-4">
          <div class="flex items-center gap-2 mb-2">
            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.scanner.batch.bestIskDay') }}</p>
          </div>
          <p class="text-2xl font-mono text-cyan-400 font-bold">
            {{ bestIskDayItem ? formatIsk(bestIskDayItem.iskPerDay) : '---' }}
          </p>
          <div v-if="bestIskDayItem" class="flex items-center gap-1.5 mt-1">
            <img
              :src="getTypeIconUrl(bestIskDayItem.typeId, 32)"
              :alt="bestIskDayItem.typeName"
              class="w-4 h-4 rounded-sm"
              @error="onImageError"
            />
            <p class="text-xs text-slate-400">{{ bestIskDayItem.typeName }}</p>
          </div>
        </div>
      </div>

      <!-- Results table -->
      <div class="bg-slate-900/50 border border-slate-800 rounded-xl overflow-hidden">
        <!-- Table header bar -->
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
            <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.scanner.batch.results') }}</h4>
            <span class="text-xs px-2 py-0.5 bg-cyan-500/10 text-cyan-400 rounded-full font-mono">{{ formatNumber(filteredResults.length, 0) }}</span>
          </div>
          <div class="flex items-center gap-3">
            <button
              class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg flex items-center gap-1.5 transition-colors border border-slate-700"
              @click="copyToMultibuy"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
              </svg>
              <span v-if="copied">{{ t('industry.scanner.batch.copied') }}</span>
              <span v-else>{{ t('industry.scanner.batch.copyMultibuy') }}</span>
            </button>
            <span class="text-xs text-slate-500">{{ t('industry.scanner.batch.sortedBy') }} <span class="text-cyan-400 font-medium">{{ t('industry.scanner.batch.estIskDay') }}</span></span>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
                <th class="text-center py-2.5 px-2 w-8">{{ t('industry.scanner.batch.rank') }}</th>
                <th class="text-left py-2.5 px-3 w-10"></th>
                <th class="text-left py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('typeName')">
                  {{ t('industry.scanner.batch.itemName') }}
                  <svg class="w-3 h-3 inline ml-0.5 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" /></svg>
                </th>
                <th class="text-center py-2.5 px-2 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('categoryLabel')">{{ t('industry.scanner.batch.category') }}</th>
                <th class="text-right py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('marginPercent')">
                  {{ t('industry.scanner.batch.marginPercent') }}
                  <svg class="w-3 h-3 inline ml-0.5 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" /></svg>
                </th>
                <th class="text-right py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('profitPerUnit')">{{ t('industry.scanner.batch.profitUnit') }}</th>
                <th class="text-right py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('dailyVolume')">{{ t('industry.scanner.batch.dailyVol') }}</th>
                <th class="text-right py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('iskPerDay')">
                  <span class="text-cyan-400">{{ t('industry.scanner.batch.estIskDay') }}</span>
                  <svg v-if="sortKey === 'iskPerDay'" class="w-3 h-3 inline ml-0.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="sortAsc ? 'M5 10l7-7m0 0l7 7m-7-7v18' : 'M19 14l-7 7m0 0l-7-7m7 7V3'" />
                  </svg>
                </th>
                <th class="text-right py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('materialCost')">{{ t('industry.scanner.batch.matCost') }}</th>
                <th class="text-right py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('exportCost')">{{ t('industry.scanner.batch.exportCost') }}</th>
                <th class="text-right py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('importCost')">{{ t('industry.scanner.batch.importCost') }}</th>
                <th class="text-right py-2.5 px-3 cursor-pointer select-none hover:text-slate-200" @click="toggleSort('sellPrice')">{{ t('industry.scanner.batch.sellPrice') }}</th>
                <th class="text-center py-2.5 px-2">{{ t('industry.scanner.batch.me') }}</th>
                <th class="text-center py-2.5 px-3">{{ t('industry.scanner.batch.activity') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
              <tr
                v-for="(item, idx) in paginatedResults"
                :key="item.typeId"
                @click="onRowClick(item)"
                :class="[
                  'cursor-pointer transition-colors',
                  idx === 0 && currentPage === 1
                    ? 'hover:bg-emerald-500/5 bg-emerald-500/[0.03]'
                    : item.marginPercent < 0
                      ? 'hover:bg-slate-800/50 opacity-70'
                      : 'hover:bg-slate-800/50',
                ]"
                :style="idx === 0 && currentPage === 1 ? 'box-shadow: inset 0 0 20px rgba(16, 185, 129, 0.05)' : ''"
              >
                <td class="py-3 px-2 text-center font-mono text-xs text-slate-600">{{ (currentPage - 1) * PAGE_SIZE + idx + 1 }}</td>
                <td class="py-3 px-3">
                  <img
                    :src="getTypeIconUrl(item.typeId, 32)"
                    :alt="item.typeName"
                    class="w-7 h-7 rounded-sm"
                    @error="onImageError"
                  />
                </td>
                <td class="py-3 px-3">
                  <div class="flex items-center gap-2">
                    <span :class="[
                      'font-semibold',
                      idx === 0 && currentPage === 1 ? 'text-slate-100' : item.marginPercent < 0 ? 'text-slate-300' : 'text-slate-200',
                    ]">{{ item.typeName }}</span>
                    <span class="text-[10px] text-slate-500">{{ item.groupName }}</span>
                  </div>
                </td>
                <td class="py-3 px-2 text-center">
                  <span :class="['text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded', categoryBadgeClasses(item.categoryLabel)]">
                    {{ item.categoryLabel }}
                  </span>
                </td>
                <td class="py-3 px-3 text-right">
                  <span :class="[
                    'font-mono',
                    item.marginPercent >= 10 ? 'text-emerald-400 font-bold' : item.marginPercent > 0 ? 'text-amber-400' : 'text-red-400',
                  ]">
                    {{ formatMargin(item.marginPercent) }}
                  </span>
                </td>
                <td class="py-3 px-3 text-right font-mono" :class="item.profitPerUnit < 0 ? 'text-red-400/70' : 'text-slate-200'">
                  {{ formatIsk(item.profitPerUnit) }}
                </td>
                <td class="py-3 px-3 text-right font-mono" :class="item.marginPercent < 0 ? 'text-slate-400' : 'text-slate-300'">
                  {{ formatNumber(item.dailyVolume, 0) }}
                </td>
                <td class="py-3 px-3 text-right font-mono" :class="[
                  item.iskPerDay < 0 ? 'text-red-400/60' : idx === 0 && currentPage === 1 ? 'text-cyan-400 font-bold' : 'text-cyan-400 font-semibold',
                ]">
                  {{ formatIsk(item.iskPerDay) }}
                </td>
                <td class="py-3 px-3 text-right font-mono" :class="item.marginPercent < 0 ? 'text-slate-500' : 'text-slate-400'">
                  {{ formatIsk(item.materialCost) }}
                </td>
                <td class="py-3 px-3 text-right font-mono text-slate-400">
                  {{ formatIsk(item.exportCost) }}
                </td>
                <td class="py-3 px-3 text-right font-mono text-slate-400">
                  {{ formatIsk(item.importCost) }}
                </td>
                <td class="py-3 px-3 text-right font-mono" :class="item.marginPercent < 0 ? 'text-slate-400' : 'text-slate-200'">
                  {{ formatIsk(item.sellPrice) }}
                </td>
                <td class="py-3 px-2 text-center font-mono text-xs" :class="item.marginPercent < 0 ? 'text-slate-500' : 'text-slate-400'">
                  {{ item.meUsed > 0 ? item.meUsed : '--' }}
                </td>
                <td class="py-3 px-3 text-center">
                  <span :class="['text-[10px] px-2 py-0.5 rounded', activityBadgeClasses(item.activityType)]">
                    {{ item.activityType.toLowerCase().includes('reaction') ? t('industry.scanner.batch.reaction') : t('industry.scanner.batch.manufacturing') }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-4 py-3 border-t border-slate-800 flex items-center justify-between">
          <span class="text-xs text-slate-500">
            {{ t('industry.scanner.batch.showing') }}
            <span class="text-slate-400 font-mono">{{ (currentPage - 1) * PAGE_SIZE + 1 }}-{{ Math.min(currentPage * PAGE_SIZE, filteredResults.length) }}</span>
            {{ t('industry.scanner.batch.of') }}
            <span class="text-slate-400 font-mono">{{ formatNumber(filteredResults.length, 0) }}</span>
            {{ t('industry.scanner.batch.profitableItems') }}
          </span>
          <div class="flex items-center gap-1">
            <button
              @click="currentPage = Math.max(1, currentPage - 1)"
              :disabled="currentPage === 1"
              :class="[
                'px-2.5 py-1 border rounded text-xs font-mono transition-colors',
                currentPage === 1
                  ? 'bg-slate-800/50 border-slate-700/50 text-slate-600 cursor-not-allowed'
                  : 'bg-slate-800 border-slate-700 text-slate-400 hover:border-cyan-500/30 hover:text-cyan-400',
              ]"
            >&laquo;</button>
            <template v-for="page in visiblePages" :key="page">
              <span v-if="page === '...'" class="text-xs text-slate-600 px-1">...</span>
              <button
                v-else
                @click="currentPage = page"
                :class="[
                  'px-2.5 py-1 border rounded text-xs font-mono transition-colors',
                  currentPage === page
                    ? 'bg-cyan-600/20 border-cyan-500/30 text-cyan-400'
                    : 'bg-slate-800 border-slate-700 text-slate-400 hover:border-cyan-500/30 hover:text-cyan-400',
                ]"
              >{{ page }}</button>
            </template>
            <button
              @click="currentPage = Math.min(totalPages, currentPage + 1)"
              :disabled="currentPage === totalPages"
              :class="[
                'px-2.5 py-1 border rounded text-xs transition-colors',
                currentPage === totalPages
                  ? 'bg-slate-800/50 border-slate-700/50 text-slate-600 cursor-not-allowed'
                  : 'bg-slate-800 border-slate-700 text-slate-400 hover:border-cyan-500/30',
              ]"
            >&raquo;</button>
          </div>
        </div>
      </div>

      <!-- Info note -->
      <div class="bg-slate-900/50 border border-slate-800 rounded-xl px-4 py-3 flex items-start gap-2">
        <svg class="w-4 h-4 text-cyan-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div class="text-xs text-cyan-300/70 space-y-1">
          <p><strong class="text-cyan-300/90">{{ t('industry.scanner.batch.estIskDay') }}</strong> {{ t('industry.scanner.batch.infoIskDay') }}</p>
          <p>{{ t('industry.scanner.batch.infoPrices') }}</p>
        </div>
      </div>
    </template>

    <!-- Empty state -->
    <div v-else-if="!scannerStore.scanLoading" class="bg-slate-900 rounded-xl border border-slate-800 p-8 text-center">
      <svg class="w-12 h-12 mx-auto mb-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
      <p class="text-slate-500">{{ t('industry.scanner.batch.noResults') }}</p>
    </div>
  </div>
</template>

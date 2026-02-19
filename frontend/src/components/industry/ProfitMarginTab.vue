<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore, type SearchResult, type ProfitMarginResult, type ProfitMarginInventionOption, type MarginEntry } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const store = useIndustryStore()
const { formatIsk, formatIskFull, formatTimeSince } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

// Item selection
const selectedProduct = ref<SearchResult | null>(null)
const productSearchRef = ref<{ clear: () => void } | null>(null)

// Config
const runs = ref(1)
const meLevel = ref(10)
const teLevel = ref(20)
const selectedSolarSystemId = ref<number | null>(null)
const selectedDecryptorTypeId = ref<number | null | undefined>(undefined)

// UI state
const materialsExpanded = ref(false)
const lastFetchedAt = ref<string | null>(null)

// Selected decryptor option (local, no API call on change)
const selectedOption = ref<ProfitMarginInventionOption | null>(null)

const MATERIALS_COLLAPSED_LIMIT = 6

// Computed
const result = computed((): ProfitMarginResult | null => store.marginResult)
const loading = computed(() => store.marginLoading)

const isT2 = computed(() => selectedProduct.value?.isT2 ?? false)

// Favorite systems from user settings for the solar system selector
const favoriteSystems = computed(() => {
  const settings = store.userSettings
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

// Effective total cost: use selected decryptor option if available, otherwise API result
const effectiveTotalCost = computed(() => {
  if (selectedOption.value != null) return selectedOption.value.totalProductionCost
  return result.value?.totalCost ?? 0
})

const effectiveCostPerUnit = computed(() => {
  if (!result.value) return 0
  const outputQty = result.value.outputQuantity || 1
  return effectiveTotalCost.value / outputQty
})

const effectiveInventionCost = computed(() => {
  if (selectedOption.value != null && result.value != null) {
    // inventionCost from the option includes datacores + decryptor + install, amortized
    return selectedOption.value.inventionCost
  }
  return result.value?.inventionCost ?? 0
})

// Summary cards
const totalCost = computed(() => effectiveTotalCost.value)
const costPerUnit = computed(() => effectiveCostPerUnit.value)

// Recompute margins per venue using the effective total cost
function computeVenueMargin(unitPrice: number, r: ProfitMarginResult, isContract: boolean = false): { revenue: number; fees: number; profit: number; margin: number } {
  const revenue = unitPrice * r.outputQuantity
  const fees = isContract ? 0 : revenue * (r.brokerFeeRate + r.salesTaxRate)
  const profit = revenue - effectiveTotalCost.value - fees
  const margin = revenue > 0 ? (profit / revenue) * 100 : 0
  return { revenue, fees, profit, margin }
}

const bestVenue = computed(() => {
  if (!result.value) return null
  const r = result.value
  const useOverride = selectedOption.value != null

  if (!useOverride) {
    // Use original margins from API
    let best: { key: string; margin: number; revenue: number; profit: number; fees: number; unitPrice: number } | null = null
    for (const [key, entry] of Object.entries(r.margins)) {
      if (entry != null && (best == null || entry.margin > best.margin)) {
        const unitPrice = key === 'jitaSell' ? (r.sellPrices.jitaSell ?? 0)
          : key === 'structureSell' ? (r.sellPrices.structureSell ?? 0)
          : key === 'contractSell' ? (r.sellPrices.contractSell ?? 0)
          : (r.sellPrices.structureBuy ?? 0)
        best = { key, margin: entry.margin, revenue: entry.revenue, profit: entry.profit, fees: entry.fees, unitPrice }
      }
    }
    return best
  }

  // Recompute margins with selected decryptor's totalProductionCost
  let best: { key: string; margin: number; revenue: number; profit: number; fees: number; unitPrice: number } | null = null
  const prices: [string, number | null][] = [
    ['jitaSell', r.sellPrices.jitaSell],
    ['structureSell', r.sellPrices.structureSell],
    ['structureBuy', r.sellPrices.structureBuy],
    ['contractSell', r.sellPrices.contractSell],
  ]
  for (const [key, price] of prices) {
    if (price == null || r.margins[key] == null) continue
    const isContract = key === 'contractSell'
    const m = computeVenueMargin(price, r, isContract)
    if (best == null || m.margin > best.margin) {
      best = { key, ...m, unitPrice: price }
    }
  }
  // Handle jitaBuy (unitPrice not in sellPrices, derive from original revenue)
  if (r.margins['jitaBuy'] != null) {
    const origEntry = r.margins['jitaBuy']!
    const jitaBuyUnitPrice = r.outputQuantity > 0 ? origEntry.revenue / r.outputQuantity : 0
    if (jitaBuyUnitPrice > 0) {
      const m = computeVenueMargin(jitaBuyUnitPrice, r)
      if (best == null || m.margin > best.margin) {
        best = { key: 'jitaBuy', ...m, unitPrice: jitaBuyUnitPrice }
      }
    }
  }
  return best
})

const bestSellRevenue = computed(() => bestVenue.value?.revenue ?? 0)
const bestProfit = computed(() => bestVenue.value?.profit ?? 0)
const bestMargin = computed(() => bestVenue.value?.margin ?? 0)
const bestUnitPrice = computed(() => bestVenue.value?.unitPrice ?? 0)

// Sell price comparison rows
interface SellRow {
  key: string
  venue: string
  tag: string
  unitPrice: number
  revenue: number
  fees: number
  profit: number
  margin: number
  dailyVolume: number | null
  contractCount: number | null
  isBest: boolean
}

const sellRows = computed((): SellRow[] => {
  if (!result.value) return []
  const r = result.value
  const rows: SellRow[] = []
  const sp = r.sellPrices
  const m = r.margins
  const structureName = sp.structureName || 'Structure'
  const bestKey = bestVenue.value?.key ?? null
  const useOverride = selectedOption.value != null

  function buildRow(key: string, venue: string, tag: string, unitPrice: number, origEntry: MarginEntry, dailyVolume: number | null, contractCount: number | null = null): SellRow {
    const isContract = key === 'contractSell'
    if (useOverride) {
      const recomputed = computeVenueMargin(unitPrice, r, isContract)
      return {
        key, venue, tag, unitPrice,
        revenue: recomputed.revenue,
        fees: recomputed.fees,
        profit: recomputed.profit,
        margin: recomputed.margin,
        dailyVolume,
        contractCount,
        isBest: bestKey === key,
      }
    }
    return {
      key, venue, tag, unitPrice,
      revenue: origEntry.revenue,
      fees: origEntry.fees,
      profit: origEntry.profit,
      margin: origEntry.margin,
      dailyVolume,
      contractCount,
      isBest: bestKey === key,
    }
  }

  if (sp.jitaSell != null && m['jitaSell'] != null) {
    rows.push(buildRow('jitaSell', 'Jita', t('industry.margins.sellTag'), sp.jitaSell, m['jitaSell']!, r.dailyVolume))
  }
  if (sp.structureSell != null && m['structureSell'] != null) {
    rows.push(buildRow('structureSell', structureName, t('industry.margins.undercutTag'), sp.structureSell, m['structureSell']!, null))
  }
  if (sp.structureBuy != null && m['structureBuy'] != null) {
    rows.push(buildRow('structureBuy', structureName, t('industry.margins.instantSellTag'), sp.structureBuy, m['structureBuy']!, null))
  }
  // Jita instant sell (buy price)
  if (m['jitaBuy'] != null) {
    const jitaBuyUnitPrice = r.outputQuantity > 0 ? m['jitaBuy']!.revenue / r.outputQuantity : 0
    rows.push(buildRow('jitaBuy', 'Jita', t('industry.margins.instantSellTag'), jitaBuyUnitPrice, m['jitaBuy']!, r.dailyVolume))
  }
  // Public contract sell (0% fees)
  if (sp.contractSell != null && m['contractSell'] != null) {
    rows.push(buildRow('contractSell', 'The Forge', t('industry.margins.contractTag'), sp.contractSell, m['contractSell']!, null, sp.contractCount))
  }
  return rows
})

// Materials display
const displayedMaterials = computed(() => {
  if (!result.value) return []
  if (materialsExpanded.value || result.value.materials.length <= MATERIALS_COLLAPSED_LIMIT) {
    return result.value.materials
  }
  return result.value.materials.slice(0, MATERIALS_COLLAPSED_LIMIT)
})

const hiddenMaterialsCount = computed(() => {
  if (!result.value) return 0
  const total = result.value.materials.length
  if (total <= MATERIALS_COLLAPSED_LIMIT) return 0
  return total - MATERIALS_COLLAPSED_LIMIT
})

const hiddenMaterialsTotal = computed(() => {
  if (!result.value || hiddenMaterialsCount.value === 0) return 0
  return result.value.materials
    .slice(MATERIALS_COLLAPSED_LIMIT)
    .reduce((sum, m) => sum + m.totalPrice, 0)
})

// Cost breakdown percentages (use effective values for invention)
const materialPercent = computed(() => {
  if (!result.value || effectiveTotalCost.value === 0) return 0
  return (result.value.materialCost / effectiveTotalCost.value) * 100
})

const jobInstallPercent = computed(() => {
  if (!result.value || effectiveTotalCost.value === 0) return 0
  return (result.value.jobInstallCost / effectiveTotalCost.value) * 100
})

const effectiveInventionPlusCopy = computed(() => {
  if (selectedOption.value != null) {
    // The option's inventionCost already includes copy cost amortized
    return selectedOption.value.inventionCost
  }
  return (result.value?.inventionCost ?? 0) + (result.value?.copyCost ?? 0)
})

const inventionPercent = computed(() => {
  if (!result.value || effectiveTotalCost.value === 0) return 0
  return (effectiveInventionPlusCopy.value / effectiveTotalCost.value) * 100
})

// Invention
const inventionData = computed(() => result.value?.invention ?? null)

const inventionOptions = computed((): ProfitMarginInventionOption[] => {
  return inventionData.value?.options ?? []
})

const bestDecryptorIndex = computed(() => {
  if (inventionOptions.value.length === 0) return -1
  let bestIdx = 0
  let bestMarginVal = inventionOptions.value[0].bestMargin
  for (let i = 1; i < inventionOptions.value.length; i++) {
    if (inventionOptions.value[i].bestMargin > bestMarginVal) {
      bestMarginVal = inventionOptions.value[i].bestMargin
      bestIdx = i
    }
  }
  return bestIdx
})

function isInventionBest(index: number): boolean {
  return index === bestDecryptorIndex.value
}

function isInventionSelected(option: ProfitMarginInventionOption): boolean {
  if (selectedOption.value == null && selectedDecryptorTypeId.value === undefined) {
    // Nothing explicitly selected yet: highlight the "best" one
    return isInventionBest(inventionOptions.value.indexOf(option))
  }
  if (selectedOption.value != null) {
    return option.decryptorTypeId === selectedOption.value.decryptorTypeId
  }
  return option.decryptorTypeId === selectedDecryptorTypeId.value
}

function meTeColor(value: number): string {
  if (value < 0) return 'text-emerald-400'
  if (value > 0) return 'text-red-400'
  return 'text-slate-400'
}

function formatMeTe(value: number): string {
  if (value > 0) return `+${value}`
  if (value === 0) return '+0'
  return String(value)
}

// Actions
function onProductSelect(product: SearchResult) {
  selectedProduct.value = product
  if (product.isT2) {
    meLevel.value = 2
    teLevel.value = 4
  } else {
    meLevel.value = 10
    teLevel.value = 20
  }
  // Reset state
  selectedDecryptorTypeId.value = undefined
  selectedOption.value = null
}

function clearProduct() {
  selectedProduct.value = null
  productSearchRef.value?.clear()
}

async function analyze() {
  if (!selectedProduct.value) return
  // Reset local decryptor override before re-fetching
  selectedOption.value = null
  selectedDecryptorTypeId.value = undefined
  await store.analyzeMargin(selectedProduct.value.typeId, {
    runs: runs.value,
    me: meLevel.value,
    te: teLevel.value,
    solarSystemId: selectedSolarSystemId.value ?? undefined,
  })
  lastFetchedAt.value = new Date().toISOString()
}

function selectDecryptor(option: ProfitMarginInventionOption) {
  selectedDecryptorTypeId.value = option.decryptorTypeId
  selectedOption.value = option
  // Update ME/TE display from the selected decryptor (for reference only)
  meLevel.value = option.me
  teLevel.value = option.te
}

async function refreshPrices() {
  await analyze()
}

// Load user settings on mount for solar system options
store.fetchUserSettings()
</script>

<template>
  <div class="space-y-4">

    <!-- SECTION A: Item Selection & Config -->
    <div class="eve-card p-4">
      <div class="flex items-center gap-2 mb-4">
        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.analyzeTitle') }}</h4>
      </div>

      <!-- Product search -->
      <div class="mb-4">
        <ProductSearch ref="productSearchRef" @select="onProductSelect" />
        <!-- Selected product pill -->
        <div v-if="selectedProduct" class="mt-2 flex items-center gap-2">
          <div class="flex items-center gap-2 bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5">
            <img
              :src="getTypeIconUrl(selectedProduct.typeId, 32)"
              :alt="selectedProduct.typeName"
              class="w-5 h-5 rounded-sm"
              @error="onImageError"
            />
            <span class="text-sm text-slate-200">{{ selectedProduct.typeName }}</span>
            <span
              v-if="selectedProduct.isT2"
              class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded-sm"
            >T2</span>
            <button class="ml-1 text-slate-500 hover:text-slate-300" @click="clearProduct">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Config row -->
      <div class="flex flex-wrap items-end gap-4">
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.createProject.runs') }}</label>
          <input
            v-model.number="runs"
            type="number"
            min="1"
            class="w-20 bg-slate-800 border border-slate-700 rounded-sm px-3 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500"
          />
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.createProject.me') }}</label>
          <div class="relative">
            <input
              v-model.number="meLevel"
              type="number"
              min="0"
              max="10"
              :disabled="isT2"
              :class="[
                'w-20 bg-slate-800 border border-slate-700 rounded-sm px-3 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500',
                isT2 ? 'opacity-50 cursor-not-allowed' : '',
              ]"
            />
            <svg v-if="isT2" class="absolute right-2 top-2 w-3.5 h-3.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
          </div>
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.createProject.te') }}</label>
          <div class="relative">
            <input
              v-model.number="teLevel"
              type="number"
              min="0"
              max="20"
              :disabled="isT2"
              :class="[
                'w-20 bg-slate-800 border border-slate-700 rounded-sm px-3 py-1.5 text-sm font-mono text-slate-200 focus:outline-none focus:border-cyan-500',
                isT2 ? 'opacity-50 cursor-not-allowed' : '',
              ]"
            />
            <svg v-if="isT2" class="absolute right-2 top-2 w-3.5 h-3.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
          </div>
        </div>
        <div>
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">{{ t('industry.margins.solarSystem') }}</label>
          <select
            v-model="selectedSolarSystemId"
            class="bg-slate-800 border border-slate-700 rounded-sm px-3 py-1.5 text-sm text-slate-200 focus:outline-none focus:border-cyan-500"
          >
            <option :value="null">{{ t('industry.margins.defaultSystem') }}</option>
            <option v-for="sys in favoriteSystems" :key="sys.id" :value="sys.id">{{ sys.name }}</option>
          </select>
        </div>
        <button
          @click="analyze"
          :disabled="!selectedProduct || loading"
          class="px-5 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-colors"
        >
          <LoadingSpinner v-if="loading" size="sm" />
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
          </svg>
          {{ t('industry.margins.analyze') }}
        </button>
      </div>

      <!-- T2 note -->
      <div v-if="isT2" class="mt-3 flex items-center gap-2 text-xs text-slate-500">
        <svg class="w-3.5 h-3.5 text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        {{ t('industry.margins.t2Note') }}
      </div>
    </div>

    <!-- Empty state -->
    <div v-if="!result && !loading" class="eve-card p-12 text-center">
      <svg class="w-12 h-12 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
      </svg>
      <p class="text-sm text-slate-500">{{ t('industry.margins.selectItem') }}</p>
    </div>

    <!-- Loading state (initial) -->
    <div v-if="loading && !result" class="text-center py-8 text-slate-500">
      <svg class="w-6 h-6 animate-spin mx-auto mb-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      {{ t('common.status.loading') }}
    </div>

    <!-- Results -->
    <template v-if="result">

      <!-- Loading overlay for re-fetch -->
      <div v-if="loading" class="flex items-center gap-2 text-sm text-slate-500 px-1">
        <svg class="w-4 h-4 animate-spin text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ t('common.status.loading') }}
      </div>

      <!-- SECTION B: Summary Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Total Cost -->
        <div class="eve-card p-4">
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.totalCost') }}</p>
          <p class="text-xl font-mono text-slate-100 font-semibold">{{ formatIsk(totalCost) }}</p>
          <p class="text-xs text-slate-500 font-mono mt-1">{{ t('industry.margins.perUnit') }}: <span class="text-slate-400">{{ formatIsk(costPerUnit) }} ISK</span></p>
        </div>
        <!-- Best Sell Price -->
        <div class="eve-card p-4">
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.bestSellPrice') }}</p>
          <p class="text-xl font-mono text-slate-100 font-semibold">{{ formatIsk(bestSellRevenue) }}</p>
          <p class="text-xs text-slate-500 font-mono mt-1">{{ t('industry.margins.perUnit') }}: <span class="text-slate-400">{{ formatIsk(bestUnitPrice) }} ISK</span></p>
        </div>
        <!-- Profit -->
        <div class="eve-card p-4">
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.profit') }}</p>
          <p
            class="text-xl font-mono font-bold"
            :class="bestProfit >= 0 ? 'text-emerald-400' : 'text-red-400'"
          >{{ bestProfit >= 0 ? '+' : '' }}{{ formatIsk(bestProfit) }}</p>
          <p
            class="text-xs font-mono mt-1"
            :class="bestProfit >= 0 ? 'text-emerald-400/70' : 'text-red-400/70'"
          >{{ t('industry.margins.perUnit') }}: <span :class="bestProfit >= 0 ? 'text-emerald-400' : 'text-red-400'">{{ bestProfit >= 0 ? '+' : '' }}{{ formatIsk(result.runs > 0 ? bestProfit / result.runs : 0) }} ISK</span></p>
        </div>
        <!-- Margin % -->
        <div class="eve-card p-4 border-cyan-500/30">
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.margins.margin') }}</p>
          <p
            class="text-2xl font-mono font-bold"
            :class="bestMargin >= 0 ? 'text-emerald-400' : 'text-red-400'"
          >{{ bestMargin >= 0 ? '+' : '' }}{{ bestMargin.toFixed(1) }}%</p>
          <p class="text-xs text-slate-500 mt-1">{{ bestVenue?.key === 'jitaSell' ? 'Jita sell' : bestVenue?.key === 'structureSell' ? result.sellPrices.structureName : bestVenue?.key === 'contractSell' ? 'Contract' : 'Jita' }} ({{ t('industry.margins.best').toLowerCase() }})</p>
        </div>
      </div>

      <!-- SECTION C: Sell Price Comparison Table -->
      <div v-if="sellRows.length > 0" class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.sellComparison') }}</h4>
          </div>
          <span class="text-xs text-slate-600">{{ result.runs }} runs &middot; {{ t('industry.margins.brokerFeeLabel') }} {{ (result.brokerFeeRate * 100).toFixed(1) }}%</span>
        </div>

        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-left py-2.5 px-4">{{ t('industry.margins.venue') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.margins.unitPrice') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.margins.revenue') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.margins.fees') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.margins.profit') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.margins.margin') }}</th>
              <th class="text-right py-2.5 px-4">{{ t('industry.margins.dailyVolume') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="row in sellRows"
              :key="row.key"
              :class="[
                row.isBest ? 'bg-emerald-500/5 hover:bg-emerald-500/10 border-l-2 border-l-emerald-500' : 'hover:bg-slate-800/50',
              ]"
            >
              <td class="py-2.5 px-4">
                <div class="flex items-center gap-2">
                  <span :class="row.isBest ? 'text-slate-100 font-semibold' : 'text-slate-200'">{{ row.venue }}</span>
                  <span class="text-[10px] px-1.5 py-0.5 bg-slate-700/50 text-slate-400 rounded-sm">{{ row.tag }}</span>
                  <span
                    v-if="row.isBest"
                    class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-sm font-semibold"
                  >{{ t('industry.margins.best') }}</span>
                </div>
              </td>
              <td class="py-2.5 px-3 text-right font-mono" :class="row.isBest ? 'text-slate-100 font-semibold' : 'text-slate-300'">{{ formatIsk(row.unitPrice) }}</td>
              <td class="py-2.5 px-3 text-right font-mono" :class="row.isBest ? 'text-slate-100' : 'text-slate-300'">{{ formatIsk(row.revenue) }}</td>
              <td class="py-2.5 px-3 text-right font-mono text-amber-400" :class="!row.isBest ? 'text-amber-400/70' : ''">-{{ formatIsk(Math.abs(row.fees)) }}</td>
              <td class="py-2.5 px-3 text-right font-mono" :class="[row.profit >= 0 ? 'text-emerald-400' : 'text-red-400', row.isBest ? 'font-bold' : '']">{{ row.profit >= 0 ? '+' : '' }}{{ formatIsk(row.profit) }}</td>
              <td class="py-2.5 px-3 text-right font-mono" :class="[row.margin >= 0 ? 'text-emerald-400' : 'text-red-400', row.isBest ? 'font-bold' : '']">{{ row.margin >= 0 ? '+' : '' }}{{ row.margin.toFixed(1) }}%</td>
              <td class="py-2.5 px-4 text-right font-mono" :class="row.dailyVolume != null || row.contractCount != null ? 'text-slate-300' : 'text-slate-500'">
                <template v-if="row.contractCount != null">{{ t('industry.margins.contractCount', { count: row.contractCount }) }}</template>
                <template v-else-if="row.dailyVolume != null">{{ Math.round(row.dailyVolume) }}/day</template>
                <template v-else>&#x2014;</template>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Info note -->
        <div class="px-4 py-3 bg-cyan-900/10 border-t border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-xs text-cyan-300/70">{{ t('industry.margins.sellComparisonNote') }}</p>
        </div>
      </div>

      <!-- No market data -->
      <div v-else-if="!loading" class="eve-card p-6 text-center">
        <p class="text-sm text-slate-500">{{ t('industry.margins.noData') }}</p>
      </div>

      <!-- SECTION D: Invention (T2 only) -->
      <div v-if="result.isT2 && inventionData && inventionOptions.length > 0" class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800">
          <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
              </svg>
              <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.inventionTitle') }}</h4>
            </div>
            <div class="flex items-center gap-4 text-xs text-slate-500">
              <span>{{ t('industry.bpcKitTab.baseProbability') }}: <span class="text-cyan-400 font-mono">{{ (inventionData.baseProbability * 100).toFixed(1) }}%</span></span>
              <span class="text-slate-700">|</span>
              <span>{{ t('industry.bpcKitTab.datacoresLabel') }}: <span class="text-slate-400">{{ inventionData.datacores.join(' + ') }}</span></span>
            </div>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
                <th class="text-center py-2.5 px-2 w-8"></th>
                <th class="text-left py-2.5 px-3">{{ t('industry.bpcKitTab.decryptor') }}</th>
                <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.me') }}</th>
                <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.te') }}</th>
                <th class="text-center py-2.5 px-2">{{ t('industry.bpcKitTab.runsBpc') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.probability') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.inventionCostCol') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.totalProdCost') }}</th>
                <th class="text-right py-2.5 px-3">{{ t('industry.margins.bestMarginCol') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
              <tr
                v-for="(option, index) in inventionOptions"
                :key="option.decryptorTypeId ?? 'none'"
                :class="[
                  'relative group cursor-pointer transition-colors',
                  isInventionBest(index) ? 'bg-emerald-500/5 hover:bg-emerald-500/10 border-l-2 border-l-emerald-500' : '',
                  !isInventionBest(index) && isInventionSelected(option) ? 'bg-cyan-500/10 hover:bg-cyan-500/15' : '',
                  !isInventionBest(index) && !isInventionSelected(option) ? 'hover:bg-slate-800/50' : '',
                ]"
                @click="selectDecryptor(option)"
              >
                <!-- Radio -->
                <td class="py-2.5 px-2 text-center">
                  <div
                    v-if="isInventionSelected(option)"
                    class="w-3.5 h-3.5 rounded-full border-2 border-cyan-500 mx-auto flex items-center justify-center"
                    style="box-shadow: 0 0 0 2px rgba(6, 182, 212, 0.3)"
                  >
                    <div class="w-1.5 h-1.5 rounded-full bg-cyan-400"></div>
                  </div>
                  <div
                    v-else
                    class="w-3.5 h-3.5 rounded-full border border-slate-600 mx-auto"
                  ></div>
                </td>

                <!-- Decryptor name -->
                <td class="py-2.5 px-3">
                  <div v-if="option.decryptorTypeId == null" class="text-slate-400 italic">
                    {{ t('industry.bpcKitTab.none') }}
                  </div>
                  <div v-else class="flex items-center gap-2">
                    <span :class="isInventionBest(index) ? 'text-slate-100 font-semibold' : 'text-slate-200'">
                      {{ option.decryptorName }}
                    </span>
                    <span
                      v-if="isInventionBest(index)"
                      class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 bg-emerald-500/20 text-emerald-400 rounded-sm font-semibold"
                    >{{ t('industry.margins.best') }}</span>
                  </div>
                </td>

                <!-- ME -->
                <td class="py-2.5 px-2 text-center font-mono" :class="[meTeColor(-option.me), isInventionBest(index) ? 'font-semibold' : '']">
                  {{ formatMeTe(-option.me) }}
                </td>

                <!-- TE -->
                <td class="py-2.5 px-2 text-center font-mono" :class="[meTeColor(-option.te), isInventionBest(index) ? 'font-semibold' : '']">
                  {{ formatMeTe(-option.te) }}
                </td>

                <!-- Runs/BPC -->
                <td class="py-2.5 px-2 text-center font-mono" :class="isInventionBest(index) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
                  {{ option.runs }}
                </td>

                <!-- Probability -->
                <td class="py-2.5 px-3 text-right font-mono" :class="isInventionBest(index) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
                  {{ (option.probability * 100).toFixed(1) }}%
                </td>

                <!-- Invention Cost -->
                <td class="py-2.5 px-3 text-right font-mono" :class="isInventionBest(index) ? 'text-slate-100 font-semibold' : 'text-slate-300'">
                  {{ formatIsk(option.inventionCost) }}
                </td>

                <!-- Total Production Cost -->
                <td class="py-2.5 px-3 text-right font-mono" :class="[
                  isInventionBest(index) ? 'text-cyan-400 font-bold' : '',
                  isInventionSelected(option) && !isInventionBest(index) ? 'text-cyan-400 font-semibold' : '',
                  !isInventionSelected(option) && !isInventionBest(index) ? 'text-slate-300' : '',
                ]">
                  {{ formatIsk(option.totalProductionCost) }}
                </td>

                <!-- Best Margin -->
                <td class="py-2.5 px-3 text-right font-mono" :class="[
                  option.bestMargin >= 0 ? 'text-emerald-400' : 'text-red-400',
                  isInventionBest(index) ? 'font-bold' : '',
                ]">
                  {{ option.bestMargin >= 0 ? '+' : '' }}{{ option.bestMargin.toFixed(1) }}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Footer -->
        <div class="px-4 py-3 border-t border-slate-800">
          <div class="text-xs text-slate-500">
            {{ t('industry.margins.inventionFooterNote') }}
          </div>
        </div>

        <!-- Selected decryptor summary (below table) -->
        <div class="px-4 py-3 border-t border-slate-700 bg-slate-800/40">
          <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
              <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div>
                <span class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.margins.selectedDecryptor') }}</span>
                <p class="text-sm text-slate-200 font-medium">
                  {{ selectedOption?.decryptorName ?? inventionOptions[bestDecryptorIndex]?.decryptorName ?? t('industry.bpcKitTab.none') }}
                </p>
              </div>
            </div>
            <div class="flex items-center gap-6">
              <div class="text-right">
                <span class="text-xs text-slate-500 uppercase tracking-wider block">{{ t('industry.margins.inventionCostCol') }}</span>
                <span class="font-mono text-slate-200 font-semibold">{{ formatIsk(effectiveInventionCost) }}</span>
              </div>
              <div class="text-right">
                <span class="text-xs text-slate-500 uppercase tracking-wider block">{{ t('industry.margins.totalProdCost') }}</span>
                <span class="font-mono text-cyan-400 font-bold">{{ formatIsk(effectiveTotalCost) }}</span>
              </div>
              <div class="text-right">
                <span class="text-xs text-slate-500 uppercase tracking-wider block">{{ t('industry.margins.bestMarginCol') }}</span>
                <span
                  class="font-mono font-bold"
                  :class="bestMargin >= 0 ? 'text-emerald-400' : 'text-red-400'"
                >{{ bestMargin >= 0 ? '+' : '' }}{{ bestMargin.toFixed(1) }}%</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- SECTION E1: Material Cost Breakdown -->
      <div class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.materialCostBreakdown') }}</h4>
          </div>
          <span class="text-xs text-slate-600">{{ t('industry.costEstimation.pricesFromJita') }}</span>
        </div>

        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-left py-2.5 px-4">{{ t('industry.costEstimation.material') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.costEstimation.quantity') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.margins.unitPrice') }}</th>
              <th class="text-right py-2.5 px-4">{{ t('industry.costEstimation.total') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="mat in displayedMaterials"
              :key="mat.typeId"
              class="hover:bg-slate-800/50"
            >
              <td class="py-2.5 px-4">
                <div class="flex items-center gap-2">
                  <div class="w-5 h-5 rounded-sm bg-slate-800 border border-slate-700 overflow-hidden shrink-0">
                    <img
                      :src="getTypeIconUrl(mat.typeId, 32)"
                      alt=""
                      class="w-full h-full"
                      @error="onImageError"
                    />
                  </div>
                  <span class="text-slate-200">{{ mat.typeName }}</span>
                </div>
              </td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ mat.quantity.toLocaleString() }}</td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-400">{{ mat.unitPrice.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</td>
              <td class="py-2.5 px-4 text-right font-mono text-slate-200">{{ mat.totalPrice.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</td>
            </tr>
            <!-- Collapsed items hint -->
            <tr v-if="hiddenMaterialsCount > 0 && !materialsExpanded" class="hover:bg-slate-800/50">
              <td class="py-2 px-4" colspan="3">
                <button
                  @click="materialsExpanded = true"
                  class="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-400 transition-colors"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                  {{ t('industry.costEstimation.moreItems', { count: hiddenMaterialsCount }) }}
                </button>
              </td>
              <td class="py-2 px-4 text-right font-mono text-slate-500">{{ hiddenMaterialsTotal.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</td>
            </tr>
            <!-- Collapse button when expanded -->
            <tr v-if="materialsExpanded && result.materials.length > MATERIALS_COLLAPSED_LIMIT" class="hover:bg-slate-800/50">
              <td class="py-2 px-4" colspan="4">
                <button
                  @click="materialsExpanded = false"
                  class="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-400 transition-colors"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                  {{ t('industry.costEstimation.collapse') }}
                </button>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t border-slate-700 bg-slate-800/30">
              <td colspan="3" class="py-3 px-4 text-right text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.costEstimation.totalMaterialCost') }}</td>
              <td class="py-3 px-4 text-right font-mono text-slate-100 font-bold text-base">{{ formatIskFull(result.materialCost) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- SECTION E2: Job Install Costs -->
      <div v-if="result.jobInstallSteps.length > 0" class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.jobInstallCost') }}</h4>
        </div>

        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-center py-2.5 px-3 w-12">#</th>
              <th class="text-left py-2.5 px-3">{{ t('industry.costEstimation.product') }}</th>
              <th class="text-left py-2.5 px-3">{{ t('industry.costEstimation.activity') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.costEstimation.runs') }}</th>
              <th class="text-right py-2.5 px-4">{{ t('industry.costEstimation.installCost') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="(step, index) in result.jobInstallSteps"
              :key="`${step.productTypeId}-${index}`"
              class="hover:bg-slate-800/50"
            >
              <td class="py-2.5 px-3 text-center text-slate-600 font-mono text-xs">{{ index + 1 }}</td>
              <td class="py-2.5 px-3">
                <div class="flex items-center gap-2">
                  <div class="w-5 h-5 rounded-sm bg-slate-800 border border-slate-700 overflow-hidden shrink-0">
                    <img
                      :src="getTypeIconUrl(step.productTypeId, 32)"
                      alt=""
                      class="w-full h-full"
                      @error="onImageError"
                    />
                  </div>
                  <span class="text-slate-200">{{ step.productName }}</span>
                </div>
              </td>
              <td class="py-2.5 px-3">
                <span
                  :class="[
                    'text-xs px-1.5 py-0.5 rounded-sm',
                    step.activityType === 'reaction'
                      ? 'bg-purple-500/10 text-purple-400'
                      : 'bg-cyan-500/10 text-cyan-400'
                  ]"
                >
                  {{ step.activityType === 'reaction'
                    ? t('industry.costEstimation.reaction')
                    : t('industry.costEstimation.manufacturing')
                  }}
                </span>
              </td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ step.runs }}</td>
              <td class="py-2.5 px-4 text-right font-mono text-slate-200">{{ step.installCost.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t border-slate-700 bg-slate-800/30">
              <td colspan="4" class="py-3 px-4 text-right text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.margins.totalJobInstallCost') }}</td>
              <td class="py-3 px-4 text-right font-mono text-slate-100 font-bold text-base">{{ formatIskFull(result.jobInstallCost) }}</td>
            </tr>
          </tfoot>
        </table>

        <!-- Info note -->
        <div class="px-4 py-3 bg-cyan-900/10 border-t border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-xs text-cyan-300/70">{{ t('industry.costEstimation.jobInstallNote') }}</p>
        </div>
      </div>

      <!-- SECTION E3: Invention Cost (T2 only) -->
      <div v-if="result.isT2 && effectiveInventionCost > 0" class="eve-card p-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <div>
              <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.inventionCostLabel') }}</h4>
              <p class="text-xs text-slate-500">{{ selectedOption?.decryptorName ?? inventionData?.selectedDecryptorName ?? t('industry.bpcKitTab.none') }}</p>
            </div>
          </div>
          <span class="font-mono text-lg text-slate-100 font-semibold">{{ formatIskFull(effectiveInventionCost) }}</span>
        </div>
      </div>

      <!-- SECTION E4: Cost Summary -->
      <div class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
          <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.margins.costSummary') }}</h4>
        </div>
        <div class="p-4">
          <div class="grid grid-cols-4 gap-6 text-center mb-4">
            <div>
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costEstimation.materials') }}</div>
              <div class="font-mono text-slate-200 text-lg">{{ formatIsk(result.materialCost) }}</div>
              <div class="text-xs text-slate-600 font-mono">{{ materialPercent.toFixed(1) }}%</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costEstimation.jobInstall') }}</div>
              <div class="font-mono text-slate-200 text-lg">{{ formatIsk(result.jobInstallCost) }}</div>
              <div class="text-xs text-slate-600 font-mono">{{ jobInstallPercent.toFixed(1) }}%</div>
            </div>
            <div v-if="result.isT2">
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.margins.inventionCostLabel') }}</div>
              <div class="font-mono text-slate-200 text-lg">{{ formatIsk(effectiveInventionPlusCopy) }}</div>
              <div class="text-xs text-slate-600 font-mono">{{ inventionPercent.toFixed(1) }}%</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.margins.totalProductionCost') }}</div>
              <div class="font-mono text-cyan-400 text-xl font-bold">{{ formatIsk(effectiveTotalCost) }}</div>
              <div class="text-xs text-slate-500 font-mono">{{ formatIsk(effectiveCostPerUnit) }} / unit</div>
            </div>
          </div>

          <!-- Breakdown bar -->
          <div class="h-3 rounded-full overflow-hidden flex bg-slate-800">
            <div class="bg-blue-500/60" :style="{ width: materialPercent + '%' }" :title="`Materials: ${formatIsk(result.materialCost)} (${materialPercent.toFixed(1)}%)`"></div>
            <div class="bg-amber-500/60" :style="{ width: jobInstallPercent + '%' }" :title="`Job Install: ${formatIsk(result.jobInstallCost)} (${jobInstallPercent.toFixed(1)}%)`"></div>
            <div v-if="result.isT2" class="bg-purple-500/60" :style="{ width: inventionPercent + '%' }" :title="`Invention: ${formatIsk(effectiveInventionPlusCopy)} (${inventionPercent.toFixed(1)}%)`"></div>
          </div>
          <div class="flex items-center gap-4 mt-2 text-xs">
            <span class="flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-blue-500/60"></span>
              <span class="text-slate-500">{{ t('industry.costEstimation.materials') }}</span>
              <span class="font-mono text-slate-400">{{ materialPercent.toFixed(1) }}%</span>
            </span>
            <span class="flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-amber-500/60"></span>
              <span class="text-slate-500">{{ t('industry.costEstimation.jobInstall') }}</span>
              <span class="font-mono text-slate-400">{{ jobInstallPercent.toFixed(1) }}%</span>
            </span>
            <span v-if="result.isT2" class="flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-purple-500/60"></span>
              <span class="text-slate-500">{{ t('industry.margins.inventionCostLabel') }}</span>
              <span class="font-mono text-slate-400">{{ inventionPercent.toFixed(1) }}%</span>
            </span>
          </div>
        </div>
      </div>

      <!-- Bottom action bar -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <button
            @click="refreshPrices"
            :disabled="loading"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 rounded-lg text-slate-200 text-sm font-medium flex items-center gap-2 transition-colors"
          >
            <svg
              class="w-4 h-4"
              :class="{ 'animate-spin': loading }"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('industry.margins.refreshPrices') }}
          </button>
        </div>
        <div v-if="lastFetchedAt" class="text-xs text-slate-600">
          {{ t('industry.costEstimation.lastUpdated') }}: {{ formatTimeSince(lastFetchedAt) }}
        </div>
      </div>

    </template>
  </div>
</template>

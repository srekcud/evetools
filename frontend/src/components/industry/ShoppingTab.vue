<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import { useSyncStore } from '@/stores/sync'
import { usePurchasesStore } from '@/stores/industry/purchases'
import { useFormatters } from '@/composables/useFormatters'
import { useShoppingStockAnalysis } from '@/composables/useShoppingStockAnalysis'
import { apiRequest } from '@/services/api'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import ShoppingConfigPanel from './shopping/ShoppingConfigPanel.vue'
import ShoppingTotalsSummary from './shopping/ShoppingTotalsSummary.vue'
import ShoppingMaterialsTable from './shopping/ShoppingMaterialsTable.vue'
import ShoppingPurchasesPanel from './shopping/ShoppingPurchasesPanel.vue'
import type { ShoppingListItem, ShoppingListTotals } from '@/stores/industry/types'

export interface EnrichedShoppingItem extends ShoppingListItem {
  inStock: number
  missing: number
  missingPrice: number | null
  missingVolume: number
  missingJita: number | null
  missingJitaWeighted: number | null
  missingJitaCoverage: number | null
  missingStructure: number | null
  missingBest: number | null
  missingSavings: number | null
  status: 'ok' | 'partial' | 'missing'
}

interface FlatPurchase {
  id: string
  stepId: string
  stepName: string
  typeName: string
  quantity: number
  unitPrice: number
  totalPrice: number
  source: string
  transactionId: string | null
}

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const store = useIndustryStore()
const syncStore = useSyncStore()
const purchasesStore = usePurchasesStore()
const { formatDateTime } = useFormatters()

// Shopping list state
const shoppingList = ref<ShoppingListItem[]>([])
const shoppingStructureName = ref('')
const shoppingStructureAccessible = ref(false)
const shoppingStructureFromCache = ref(false)
const shoppingStructureLastSync = ref<string | null>(null)
const shoppingTotals = ref<ShoppingListTotals | null>(null)
const shoppingPriceError = ref<string | null>(null)
const shoppingLoading = ref(false)
const shoppingSyncing = ref(false)
const transportCostPerM3 = ref(1200)

// Reactive refs for the composable
const projectIdRef = computed(() => props.projectId)
const treeRef = computed(() => store.currentProject?.tree)
const stepsRef = computed(() => store.currentProject?.steps ?? [])

// Stock analysis composable
const stock = useShoppingStockAnalysis(
  projectIdRef,
  shoppingList,
  treeRef,
  stepsRef,
  (pid, sid, qty) => store.toggleStepInStock(pid, sid, qty),
  (id) => store.fetchProject(id),
  () => loadShoppingList(),
)

// Mercure sync progress
const marketStructureProgress = computed(() => syncStore.getSyncProgress('market-structure'))

watch(marketStructureProgress, (progress) => {
  if (progress?.status === 'completed') {
    loadShoppingList()
  }
})

// Structure search with persistence
const STRUCTURE_STORAGE_KEY = 'industry_shopping_structure'
const DEFAULT_STRUCTURE_ID = 1049588174021
const DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon'

function loadPersistedStructure(): { id: number | null; name: string } {
  const stored = localStorage.getItem(STRUCTURE_STORAGE_KEY)
  if (stored) {
    try {
      const data = JSON.parse(stored)
      if (data.id && data.name) return data
    } catch {
      // ignore
    }
  }
  return { id: DEFAULT_STRUCTURE_ID, name: DEFAULT_STRUCTURE_NAME }
}

function saveStructureToStorage(structure: { id: number | null; name: string }) {
  if (structure.id) {
    localStorage.setItem(STRUCTURE_STORAGE_KEY, JSON.stringify(structure))
  } else {
    localStorage.removeItem(STRUCTURE_STORAGE_KEY)
  }
}

const selectedStructure = ref<{ id: number | null; name: string }>(loadPersistedStructure())

function onStructureChange(value: { id: number | null; name: string }) {
  selectedStructure.value = value
  saveStructureToStorage(value)
}

// Enriched shopping list with stock status
const enrichedShoppingList = computed<EnrichedShoppingItem[]>(() => {
  const stockMap = new Map<string, number>()
  for (const item of stock.parsedStock.value) {
    const key = item.name.toLowerCase()
    stockMap.set(key, (stockMap.get(key) ?? 0) + item.quantity)
  }

  return shoppingList.value.map(item => {
    const key = item.typeName.toLowerCase()
    const inStock = (stockMap.get(key) ?? 0) + (item.purchasedQuantity ?? 0)
    const totalNeeded = item.quantity + (item.extraQuantity ?? 0)
    const missing = Math.max(0, totalNeeded - inStock)
    let status: 'ok' | 'partial' | 'missing' = 'missing'
    if (inStock >= totalNeeded) {
      status = 'ok'
    } else if (inStock > 0) {
      status = 'partial'
    }
    let missingPrice: number | null = null
    if (item.bestPrice !== null && item.quantity > 0) {
      missingPrice = (missing / item.quantity) * item.bestPrice
    } else if (item.jitaWithImport !== null && item.quantity > 0) {
      missingPrice = (missing / item.quantity) * item.jitaWithImport
    }
    const ratio = item.quantity > 0 ? missing / item.quantity : 0
    const missingVolume = item.volume * missing
    const missingJita = item.jitaWithImport !== null ? item.jitaWithImport * ratio : null
    const missingJitaWeighted = item.jitaWeightedTotal !== null ? item.jitaWeightedTotal * ratio : null
    const missingJitaCoverage = item.jitaCoverage
    const missingStructure = item.structureTotal !== null ? item.structureTotal * ratio : null
    const missingBest = item.bestPrice !== null ? item.bestPrice * ratio : null
    const missingSavings = item.savings !== null ? item.savings * ratio : null
    return { ...item, inStock, missing, missingPrice, missingVolume, missingJita, missingJitaWeighted, missingJitaCoverage, missingStructure, missingBest, missingSavings, status }
  })
})

const missingTotals = computed(() => {
  let totalMissingPrice = 0
  let totalMissingVolume = 0
  let totalMissingJita = 0
  let totalMissingJitaWeighted = 0
  let hasAnyJitaWeighted = false
  let totalMissingStructure = 0
  let totalMissingSavings = 0
  for (const item of enrichedShoppingList.value) {
    if (item.missing > 0) {
      if (item.missingPrice !== null) totalMissingPrice += item.missingPrice
      totalMissingVolume += item.volume * item.missing
      if (item.missingJita !== null) totalMissingJita += item.missingJita
      if (item.missingJitaWeighted !== null) {
        totalMissingJitaWeighted += item.missingJitaWeighted
        hasAnyJitaWeighted = true
      } else if (item.missingJita !== null) {
        totalMissingJitaWeighted += item.missingJita
      }
      if (item.missingStructure !== null) totalMissingStructure += item.missingStructure
      if (item.missingSavings !== null) totalMissingSavings += item.missingSavings
    }
  }
  return {
    price: totalMissingPrice,
    volume: totalMissingVolume,
    jita: totalMissingJita,
    jitaWeighted: hasAnyJitaWeighted ? totalMissingJitaWeighted : null,
    structure: totalMissingStructure,
    savings: totalMissingSavings,
  }
})

const displayStructureName = computed(() => {
  return shoppingStructureName.value || selectedStructure.value.name || 'Structure'
})

const jitaItems = computed(() => {
  return enrichedShoppingList.value.filter(item =>
    (item.bestLocation === 'jita' || item.bestLocation === null) && item.missing > 0,
  )
})

const structureItems = computed(() => {
  return enrichedShoppingList.value.filter(item => item.bestLocation === 'structure' && item.missing > 0)
})

const jitaMultibuyFormat = computed(() => {
  return jitaItems.value.map(item => `${item.typeName}\t${item.missing}`).join('\n')
})

const structureMultibuyFormat = computed(() => {
  return structureItems.value.map(item => `${item.typeName}\t${item.missing}`).join('\n')
})

// Purchases
const purchasesLoading = ref(false)
const purchasesPanelRef = ref<InstanceType<typeof ShoppingPurchasesPanel> | null>(null)

const projectPurchases = computed<FlatPurchase[]>(() => {
  if (!store.currentProject?.steps) return []
  const result: FlatPurchase[] = []
  for (const step of store.currentProject.steps) {
    if (step.purchases && step.purchases.length > 0) {
      for (const p of step.purchases) {
        result.push({
          id: p.id,
          stepId: step.id,
          stepName: step.productTypeName,
          typeName: p.typeName,
          quantity: p.quantity,
          unitPrice: p.unitPrice,
          totalPrice: p.totalPrice,
          source: p.source,
          transactionId: p.transactionId,
        })
      }
    }
  }
  return result
})

const projectPurchasesTotalCost = computed(() =>
  projectPurchases.value.reduce((sum, p) => sum + p.totalPrice, 0),
)

function findStepForType(typeId: number): { id: string; name: string } | null {
  if (!store.currentProject?.steps) return null
  for (const step of store.currentProject.steps) {
    if (step.depth > 0 && step.activityType !== 'copy' && step.productTypeId === typeId) {
      return { id: step.id, name: step.productTypeName }
    }
  }
  for (const step of store.currentProject.steps) {
    if (step.depth === 0 && step.activityType !== 'copy') {
      return { id: step.id, name: step.productTypeName }
    }
  }
  return null
}

function canFindStepForType(typeId: number): boolean {
  return findStepForType(typeId) !== null
}

function roundUpToMillion(value: number): number {
  return Math.ceil(value / 1_000_000) * 1_000_000
}

async function loadShoppingList() {
  shoppingLoading.value = true
  shoppingPriceError.value = null
  try {
    const structureId = selectedStructure.value.id ?? undefined
    const response = await store.fetchShoppingList(props.projectId, structureId, transportCostPerM3.value)
    if (response) {
      shoppingList.value = response.materials
      shoppingStructureName.value = response.structureName
      shoppingStructureAccessible.value = response.structureAccessible
      shoppingStructureFromCache.value = response.structureFromCache
      shoppingStructureLastSync.value = response.structureLastSync
      shoppingTotals.value = response.totals
      shoppingPriceError.value = response.priceError

      if (
        store.currentProject &&
        (store.currentProject.materialCost === null || store.currentProject.materialCost === 0) &&
        response.totals.best > 0
      ) {
        const roundedCost = roundUpToMillion(response.totals.best)
        await store.updateProject(props.projectId, { materialCost: roundedCost })
      }
    } else {
      shoppingList.value = []
      shoppingTotals.value = null
      shoppingStructureAccessible.value = false
      shoppingPriceError.value = store.error
    }
  } catch (e) {
    shoppingList.value = []
    shoppingTotals.value = null
    shoppingStructureAccessible.value = false
    shoppingPriceError.value = e instanceof Error ? e.message : t('common.errors.loadFailed')
  } finally {
    shoppingLoading.value = false
  }
}

async function syncStructureMarket() {
  shoppingSyncing.value = true
  shoppingPriceError.value = null
  try {
    const structureId = selectedStructure.value.id ?? undefined
    await apiRequest('/shopping-list/sync-structure-market', {
      method: 'POST',
      body: JSON.stringify({ structureId }),
    })
    await loadShoppingList()
  } catch (e) {
    shoppingPriceError.value = e instanceof Error ? e.message : 'Sync failed'
  } finally {
    shoppingSyncing.value = false
  }
}

async function applyAsMaterialCost() {
  if (!shoppingTotals.value || !store.currentProject) return
  const costToApply = missingTotals.value.price > 0 ? missingTotals.value.price : shoppingTotals.value.best
  const roundedCost = roundUpToMillion(costToApply)
  await store.updateProject(props.projectId, { materialCost: roundedCost })
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
  return formatDateTime(isoDate)
}

// Called by parent when tab is activated
async function activate() {
  if (store.currentProject?.status === 'completed') return
  if (shoppingList.value.length === 0) {
    stock.stockAnalysisLoading.value = true
    try {
      await loadShoppingList()
    } finally {
      stock.stockAnalysisLoading.value = false
    }
  }
  stock.performStockAnalysis()
}

// Refresh shopping list after purchased status changes (called by parent via StepsTab)
async function refreshAfterPurchaseChange() {
  await loadShoppingList()
  stock.performStockAnalysis()
}

// Purchases
async function linkSuggestion(suggestion: { transactionUuid: string; typeId: number; transactionId: number }) {
  const step = findStepForType(suggestion.typeId)
  if (!step) return

  try {
    await purchasesStore.createPurchase(props.projectId, step.id, {
      transactionId: suggestion.transactionUuid,
    })
    await Promise.all([
      store.fetchProject(props.projectId),
      purchasesStore.fetchSuggestions(props.projectId),
    ])
    await loadShoppingList()
    stock.performStockAnalysis()
  } finally {
    purchasesPanelRef.value?.resetLinkingId()
  }
}

async function removePurchase(stepId: string, purchaseId: string) {
  await purchasesStore.deletePurchase(props.projectId, stepId, purchaseId)
  await Promise.all([
    store.fetchProject(props.projectId),
    purchasesStore.fetchSuggestions(props.projectId),
  ])
  await loadShoppingList()
  stock.performStockAnalysis()
}

async function loadSuggestions() {
  purchasesLoading.value = true
  try {
    await purchasesStore.fetchSuggestions(props.projectId)
  } finally {
    purchasesLoading.value = false
  }
}

defineExpose({
  shoppingTotals,
  enrichedShoppingList,
  missingTotals,
  loadPersistedStock: stock.loadPersistedStock,
  reanalyzeStock: stock.reanalyzeStock,
  activate,
  loadShoppingList,
  refreshAfterPurchaseChange,
  showClearStockModal: stock.showClearStockModal,
  parsedStock: stock.parsedStock,
  clearStock: stock.clearStock,
  projectPurchasesTotalCost,
  loadSuggestions,
})
</script>

<template>
  <div>
    <!-- Loading state -->
    <div v-if="shoppingLoading || stock.stockAnalysisLoading.value" class="text-center py-8 text-slate-500">
      <svg class="w-6 h-6 animate-spin mx-auto mb-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      {{ t('common.status.loading') }}
    </div>

    <!-- Error state with retry -->
    <div v-else-if="shoppingPriceError && shoppingList.length === 0" class="text-center py-8">
      <div class="bg-red-900/20 border border-red-500/30 rounded-xl p-6 max-w-md mx-auto">
        <svg class="w-10 h-10 mx-auto mb-3 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <p class="text-red-400 font-medium mb-2">{{ t('common.errors.loadFailed') }}</p>
        <p class="text-slate-400 text-sm mb-4">{{ shoppingPriceError }}</p>
        <button
          @click="loadShoppingList"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 mx-auto"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ t('common.actions.retry') }}
        </button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else-if="shoppingList.length === 0" class="text-center py-8 text-slate-500">
      <svg class="w-12 h-12 mx-auto mb-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
      </svg>
      <p>{{ t('industry.shoppingTab.noMaterials') }}</p>
    </div>

    <!-- Main content -->
    <div v-else class="space-y-4">
      <!-- Header: 3-column config -->
      <ShoppingConfigPanel
        v-if="store.currentProject?.status !== 'completed'"
        :selected-structure="selectedStructure"
        :default-structure-name="DEFAULT_STRUCTURE_NAME"
        :transport-cost-per-m3="transportCostPerM3"
        :pasted-stock="stock.pastedStock.value"
        :parsed-stock-count="stock.parsedStock.value.length"
        :stock-analysis-loading="stock.stockAnalysisLoading.value"
        :shopping-syncing="shoppingSyncing"
        :market-structure-progress="marketStructureProgress"
        @update:selected-structure="onStructureChange"
        @update:transport-cost-per-m3="transportCostPerM3 = $event"
        @update:pasted-stock="stock.pastedStock.value = $event"
        @sync-structure="syncStructureMarket"
        @recalculate="loadShoppingList"
        @analyze-stock="stock.analyzeStock"
        @confirm-clear-stock="stock.confirmClearStock"
      />

      <!-- Mercure sync progress -->
      <div
        v-if="marketStructureProgress && (marketStructureProgress.status === 'started' || marketStructureProgress.status === 'in_progress')"
        class="p-3 bg-cyan-900/20 border border-cyan-500/30 rounded-lg flex items-center gap-3"
      >
        <LoadingSpinner size="md" class="text-cyan-400 shrink-0" />
        <div class="flex-1">
          <div class="text-sm text-cyan-300">{{ marketStructureProgress.message || 'Synchronisation du marche...' }}</div>
          <div v-if="marketStructureProgress.progress !== null" class="mt-1 h-1.5 bg-slate-700 rounded-full overflow-hidden">
            <div
              class="h-full bg-cyan-500 transition-all duration-300"
              :style="{ width: `${marketStructureProgress.progress}%` }"
            ></div>
          </div>
        </div>
      </div>

      <!-- Structure not accessible warning -->
      <div
        v-if="shoppingStructureAccessible === false && !shoppingPriceError && store.currentProject?.status !== 'completed'"
        class="bg-amber-900/30 border border-amber-500/50 rounded-lg p-3"
      >
        <div class="flex items-center gap-3">
          <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div class="text-sm">
            <p class="text-amber-300">{{ t('industry.shoppingTab.structurePricesUnavailable') }}</p>
            <p class="text-amber-400/70 text-xs mt-0.5">
              {{ t('industry.shoppingTab.structureNotSynced', { name: shoppingStructureName }) }}
            </p>
          </div>
        </div>
      </div>

      <!-- Error message -->
      <div v-if="stock.stockAnalysisError.value" class="p-3 bg-red-900/20 border border-red-500/30 rounded-lg flex items-center gap-2">
        <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-red-400 text-sm">{{ stock.stockAnalysisError.value }}</span>
      </div>

      <!-- Stock/purchase duplicate warning -->
      <div v-if="stock.stockPurchaseWarnings.value.length > 0" class="bg-amber-500/10 border border-amber-500/30 text-amber-300 rounded-lg p-3">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="text-sm font-medium">{{ t('industry.shoppingTab.duplicateWarning') }}</p>
            <ul class="mt-1 text-xs text-amber-400/80 space-y-0.5">
              <li v-for="w in stock.stockPurchaseWarnings.value" :key="w.name">
                {{ w.name }} : {{ w.stockQty.toLocaleString() }} en stock + {{ w.purchasedQty.toLocaleString() }} achetes
              </li>
            </ul>
            <p class="mt-1 text-xs text-amber-400/60">{{ t('industry.shoppingTab.duplicateHint') }}</p>
          </div>
          <button @click="stock.stockPurchaseWarnings.value = []" class="text-amber-400 hover:text-amber-300 shrink-0 p-0.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Totals summary -->
      <ShoppingTotalsSummary
        v-if="shoppingTotals"
        :shopping-totals="shoppingTotals"
        :missing-totals="missingTotals"
        :has-any-stock="stock.hasAnyStock.value"
        :jita-multibuy-format="jitaMultibuyFormat"
        :structure-multibuy-format="structureMultibuyFormat"
        :has-jita-items="jitaItems.length > 0"
        :has-structure-items="structureItems.length > 0"
      />

      <!-- Materials table -->
      <ShoppingMaterialsTable
        :items="enrichedShoppingList"
        @update-stock="stock.updateStockByName"
      />

      <!-- Price info + Apply as material cost -->
      <div class="flex items-center gap-3">
        <div class="flex-1 bg-cyan-900/20 border border-cyan-500/20 rounded-lg px-3 py-2 flex items-center gap-2">
          <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-cyan-300/80 text-xs">
            {{ t('industry.shoppingTab.priceInfo', { structure: displayStructureName }) }}
          </p>
          <div v-if="shoppingStructureFromCache && shoppingStructureLastSync" class="ml-auto text-xs text-slate-500 flex items-center gap-1">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            {{ formatRelativeTime(shoppingStructureLastSync) }}
          </div>
        </div>
        <button
          v-if="shoppingTotals && shoppingTotals.best > 0 && store.currentProject?.status !== 'completed'"
          @click="applyAsMaterialCost"
          class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 shrink-0"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          {{ t('industry.shoppingTab.applyAsMaterialCost') }}
        </button>
      </div>

      <!-- Purchases panel -->
      <ShoppingPurchasesPanel
        ref="purchasesPanelRef"
        :suggestions="purchasesStore.suggestions"
        :project-purchases="projectPurchases"
        :project-purchases-total-cost="projectPurchasesTotalCost"
        :material-cost="store.currentProject?.materialCost ?? null"
        :purchases-loading="purchasesLoading"
        :can-find-step-for-type="canFindStepForType"
        @load-suggestions="loadSuggestions"
        @link-suggestion="linkSuggestion"
        @remove-purchase="removePurchase"
      />
    </div>
  </div>
</template>

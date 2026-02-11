<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useIndustryStore } from '@/stores/industry'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { usePurchasesStore } from '@/stores/industry/purchases'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { parseEveStock } from '@/composables/useStockAnalysis'
import type { ParsedStockItem, IntermediateInStock, RelevantStockItem } from '@/composables/useStockAnalysis'
import { apiRequest, authFetch, safeJsonParse } from '@/services/api'
import type { ShoppingListItem, ShoppingListTotals, ProductionTreeNode } from '@/stores/industry/types'

interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
  solarSystemId: number | null
}

interface ChildInfo {
  typeId: number
  typeName: string
  blueprintTypeId: number
  activityType: string
  quantityPerUnit: number
  isBuildable: boolean
}

export interface EnrichedShoppingItem extends ShoppingListItem {
  inStock: number
  missing: number
  missingPrice: number | null
  missingVolume: number
  missingJita: number | null
  missingStructure: number | null
  missingBest: number | null
  missingSavings: number | null
  status: 'ok' | 'partial' | 'missing'
}

const props = defineProps<{
  projectId: string
}>()

const store = useIndustryStore()
const authStore = useAuthStore()
const syncStore = useSyncStore()
const purchasesStore = usePurchasesStore()
const { formatIsk, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

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
const copiedJita = ref(false)
const copiedStructure = ref(false)

// Stock state
const pastedStock = ref('')
const parsedStock = ref<ParsedStockItem[]>([])
const relevantStock = ref<RelevantStockItem[]>([])
const intermediatesInStock = ref<IntermediateInStock[]>([])
const stockAnalysisError = ref<string | null>(null)
const stockAnalysisLoading = ref(false)
const showClearStockModal = ref(false)
const parentChildrenMap = ref<Map<string, ChildInfo[]>>(new Map())

// Inline stock editing
const editingStockTypeId = ref<number | null>(null)
const editingStockValue = ref('')

// Mercure sync progress
const marketStructureProgress = computed(() => syncStore.getSyncProgress('market-structure'))

watch(marketStructureProgress, (progress) => {
  if (progress?.status === 'completed') {
    loadShoppingList()
  }
})

// Rebuild parent-children map when tree changes
watch(
  () => store.currentProject?.tree,
  (tree) => {
    if (tree) buildParentChildrenMap(tree)
  },
  { immediate: true },
)

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
const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
let structureSearchTimeout: ReturnType<typeof setTimeout> | null = null

watch(structureSearchQuery, (query) => {
  if (structureSearchTimeout) clearTimeout(structureSearchTimeout)
  if (query.length < 3) {
    structureSearchResults.value = []
    return
  }
  structureSearchTimeout = setTimeout(() => {
    searchStructures(query)
  }, 300)
})

async function searchStructures(query: string) {
  isSearchingStructures.value = true
  try {
    const response = await authFetch(`/api/shopping-list/search-structures?q=${encodeURIComponent(query)}`, {
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
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

function selectStructure(structure: StructureSearchResult) {
  selectedStructure.value = { id: structure.id, name: structure.name }
  saveStructureToStorage(selectedStructure.value)
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureDropdown.value = false
}

function clearStructure() {
  selectedStructure.value = { id: DEFAULT_STRUCTURE_ID, name: DEFAULT_STRUCTURE_NAME }
  saveStructureToStorage(selectedStructure.value)
  structureSearchQuery.value = ''
  structureSearchResults.value = []
}

function onStructureInputBlur() {
  setTimeout(() => {
    showStructureDropdown.value = false
  }, 200)
}

// Enriched shopping list with stock status
const enrichedShoppingList = computed<EnrichedShoppingItem[]>(() => {
  const stockMap = new Map<string, number>()
  for (const item of parsedStock.value) {
    const key = item.name.toLowerCase()
    stockMap.set(key, (stockMap.get(key) ?? 0) + item.quantity)
  }

  return shoppingList.value.map(item => {
    const key = item.typeName.toLowerCase()
    const inStock = (stockMap.get(key) ?? 0) + (item.purchasedQuantity ?? 0)
    // Real quantity needed = optimal + extra from suboptimal structures
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
    const missingStructure = item.structureTotal !== null ? item.structureTotal * ratio : null
    const missingBest = item.bestPrice !== null ? item.bestPrice * ratio : null
    const missingSavings = item.savings !== null ? item.savings * ratio : null
    return { ...item, inStock, missing, missingPrice, missingVolume, missingJita, missingStructure, missingBest, missingSavings, status }
  })
})

const missingTotals = computed(() => {
  let totalMissingPrice = 0
  let totalMissingVolume = 0
  let totalMissingJita = 0
  let totalMissingStructure = 0
  let totalMissingSavings = 0
  for (const item of enrichedShoppingList.value) {
    if (item.missing > 0) {
      if (item.missingPrice !== null) totalMissingPrice += item.missingPrice
      totalMissingVolume += item.volume * item.missing
      if (item.missingJita !== null) totalMissingJita += item.missingJita
      if (item.missingStructure !== null) totalMissingStructure += item.missingStructure
      if (item.missingSavings !== null) totalMissingSavings += item.missingSavings
    }
  }
  return {
    price: totalMissingPrice,
    volume: totalMissingVolume,
    jita: totalMissingJita,
    structure: totalMissingStructure,
    savings: totalMissingSavings,
  }
})

const hasAnyStock = computed(() => parsedStock.value.length > 0)

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
    shoppingPriceError.value = e instanceof Error ? e.message : 'Erreur lors du chargement'
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

function copyToClipboard(text: string, type: 'jita' | 'structure') {
  if (!text) return
  navigator.clipboard.writeText(text)
  if (type === 'jita') {
    copiedJita.value = true
    setTimeout(() => copiedJita.value = false, 2000)
  } else {
    copiedStructure.value = true
    setTimeout(() => copiedStructure.value = false, 2000)
  }
}

function formatRelativeTime(isoDate: string | null): string {
  if (!isoDate) return ''
  const date = new Date(isoDate)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMins / 60)

  if (diffMins < 1) return "il y a moins d'une minute"
  if (diffMins < 60) return `il y a ${diffMins} min`
  if (diffHours < 24) return `il y a ${diffHours}h`
  return formatDateTime(isoDate)
}

// Stock persistence
function getStockStorageKey(): string {
  return `industry_stock_${props.projectId}`
}

function loadPersistedStock() {
  const stored = localStorage.getItem(getStockStorageKey())
  if (stored) {
    try {
      const data = JSON.parse(stored)
      pastedStock.value = data.raw || ''
      parsedStock.value = data.parsed || []
    } catch {
      // Ignore invalid data
    }
  }
}

function saveStockToStorage() {
  localStorage.setItem(getStockStorageKey(), JSON.stringify({
    raw: pastedStock.value,
    parsed: parsedStock.value,
  }))
}

function performStockAnalysis() {
  if (shoppingList.value.length === 0) {
    relevantStock.value = []
    intermediatesInStock.value = []
    return
  }

  const stockMap = new Map<string, number>()
  for (const item of parsedStock.value) {
    const key = item.name.toLowerCase()
    stockMap.set(key, (stockMap.get(key) ?? 0) + item.quantity)
  }

  const intermediateMap = new Map<number, IntermediateInStock>()
  const steps = store.currentProject?.steps ?? []
  const rootProductTypeIds = new Set(
    steps
      .filter(s => s.depth === 0 && (s.activityType === 'manufacturing' || s.activityType === 'reaction'))
      .map(s => s.productTypeId),
  )

  for (const step of steps) {
    if (step.activityType !== 'manufacturing' && step.activityType !== 'reaction') continue
    if (rootProductTypeIds.has(step.productTypeId)) continue
    if (step.purchased || step.inStock) continue

    const key = step.productTypeName.toLowerCase()
    const inStock = stockMap.get(key) ?? 0
    const existing = intermediateMap.get(step.productTypeId)

    if (existing) {
      existing.needed += step.quantity
      existing.runsNeeded += step.runs
      existing.runsCovered = existing.needed > 0
        ? Math.min(existing.runsNeeded, Math.floor(existing.runsNeeded * existing.inStock / existing.needed))
        : 0
      if (existing.inStock >= existing.needed) existing.status = 'ok'
      else if (existing.inStock > 0) existing.status = 'partial'
      else existing.status = 'missing'
    } else {
      const runsCovered = step.quantity > 0
        ? Math.min(step.runs, Math.floor(step.runs * inStock / step.quantity))
        : 0
      let status: 'ok' | 'partial' | 'missing' = 'missing'
      if (inStock >= step.quantity) status = 'ok'
      else if (inStock > 0) status = 'partial'

      intermediateMap.set(step.productTypeId, {
        typeId: step.productTypeId,
        name: step.productTypeName,
        inStock,
        needed: step.quantity,
        stepId: step.id,
        blueprintTypeId: step.blueprintTypeId,
        runsNeeded: step.runs,
        runsCovered,
        status,
        activityType: step.activityType,
      })
    }
  }

  intermediatesInStock.value = Array.from(intermediateMap.values())
    .sort((a, b) => {
      if (a.inStock > 0 && b.inStock === 0) return -1
      if (a.inStock === 0 && b.inStock > 0) return 1
      return a.name.localeCompare(b.name)
    })

  const results: RelevantStockItem[] = []
  for (const material of shoppingList.value) {
    const key = material.typeName.toLowerCase()
    const inStock = (stockMap.get(key) ?? 0) + (material.purchasedQuantity ?? 0)
    const totalNeeded = material.quantity + (material.extraQuantity ?? 0)
    if (inStock > 0 || totalNeeded > 0) {
      const missing = Math.max(0, totalNeeded - inStock)
      let status: 'ok' | 'partial' | 'missing' = 'missing'
      if (inStock >= totalNeeded) status = 'ok'
      else if (inStock > 0) status = 'partial'
      results.push({ typeId: material.typeId, name: material.typeName, needed: totalNeeded, inStock, missing, status })
    }
  }

  const statusOrder = { missing: 0, partial: 1, ok: 2 }
  results.sort((a, b) => {
    if (statusOrder[a.status] !== statusOrder[b.status]) return statusOrder[a.status] - statusOrder[b.status]
    return a.name.localeCompare(b.name)
  })
  relevantStock.value = results
}

function buildParentChildrenMap(tree: ProductionTreeNode) {
  parentChildrenMap.value.clear()
  function traverse(node: ProductionTreeNode) {
    const key = `${node.blueprintTypeId}_${node.activityType}`
    const children: ChildInfo[] = []
    for (const mat of node.materials) {
      children.push({
        typeId: mat.typeId,
        typeName: mat.typeName,
        blueprintTypeId: mat.blueprint?.blueprintTypeId ?? 0,
        activityType: mat.blueprint?.activityType ?? '',
        quantityPerUnit: node.quantity > 0 ? mat.quantity / node.quantity : 0,
        isBuildable: mat.isBuildable,
      })
      if (mat.isBuildable && mat.blueprint) traverse(mat.blueprint)
    }
    if (children.length > 0) parentChildrenMap.value.set(key, children)
  }
  traverse(tree)
}

async function cascadeStockToChildren(blueprintTypeId: number, activityType: string, parentQuantityInStock: number) {
  const key = `${blueprintTypeId}_${activityType}`
  const children = parentChildrenMap.value.get(key)
  if (!children || parentQuantityInStock <= 0) return

  for (const child of children) {
    const childQuantityNeeded = Math.ceil(parentQuantityInStock * child.quantityPerUnit)
    if (child.isBuildable && child.blueprintTypeId > 0) {
      const childStep = store.currentProject?.steps?.find(
        s => s.blueprintTypeId === child.blueprintTypeId && s.activityType === child.activityType,
      )
      if (childStep && childStep.depth > 0) {
        const newInStock = Math.min(childStep.inStockQuantity + childQuantityNeeded, childStep.quantity)
        if (newInStock > childStep.inStockQuantity) {
          await store.toggleStepInStock(props.projectId, childStep.id, newInStock)
        }
      }
      await cascadeStockToChildren(child.blueprintTypeId, child.activityType, childQuantityNeeded)
    } else {
      const existingIdx = parsedStock.value.findIndex(
        p => p.name.toLowerCase() === child.typeName.toLowerCase(),
      )
      if (existingIdx >= 0) {
        parsedStock.value[existingIdx].quantity += childQuantityNeeded
      } else {
        parsedStock.value.push({ name: child.typeName, quantity: childQuantityNeeded })
      }
    }
  }
}

async function analyzeStock() {
  stockAnalysisError.value = null

  if (!pastedStock.value.trim()) {
    stockAnalysisError.value = 'Collez votre inventaire EVE dans la zone de texte'
    return
  }

  const newItems = parseEveStock(pastedStock.value)
  if (newItems.length === 0) {
    stockAnalysisError.value = 'Aucun item détecté. Vérifiez le format (copier depuis EVE avec Ctrl+A puis Ctrl+C)'
    relevantStock.value = []
    intermediatesInStock.value = []
    return
  }

  for (const newItem of newItems) {
    const existingIndex = parsedStock.value.findIndex(
      p => p.name.toLowerCase() === newItem.name.toLowerCase(),
    )
    if (existingIndex >= 0) {
      parsedStock.value[existingIndex].quantity = newItem.quantity
    } else {
      parsedStock.value.push(newItem)
    }
  }

  pastedStock.value = ''
  saveStockToStorage()

  if (shoppingList.value.length === 0) {
    stockAnalysisLoading.value = true
    try {
      await loadShoppingList()
    } finally {
      stockAnalysisLoading.value = false
    }
  }

  if (shoppingList.value.length === 0) {
    stockAnalysisError.value = 'Impossible de charger la liste de courses. Réessayez.'
    relevantStock.value = []
    return
  }

  const steps = store.currentProject?.steps ?? []
  const rootProductTypeIds = new Set(
    steps
      .filter(s => s.depth === 0 && (s.activityType === 'manufacturing' || s.activityType === 'reaction'))
      .map(s => s.productTypeId),
  )

  let cascadeTriggered = false
  for (const newItem of newItems) {
    const matchingStep = steps.find(
      s => s.productTypeName.toLowerCase() === newItem.name.toLowerCase() &&
        (s.activityType === 'manufacturing' || s.activityType === 'reaction') &&
        !rootProductTypeIds.has(s.productTypeId) &&
        s.depth > 0 &&
        !s.purchased &&
        !s.inStock,
    )

    if (matchingStep && newItem.quantity > 0) {
      const oldQuantity = (parsedStock.value.find(p => p.name.toLowerCase() === newItem.name.toLowerCase())?.quantity ?? 0) - newItem.quantity
      const addedQuantity = Math.max(0, newItem.quantity - Math.max(0, oldQuantity))
      if (addedQuantity > 0) {
        const newInStock = Math.min(matchingStep.inStockQuantity + addedQuantity, matchingStep.quantity)
        if (newInStock > matchingStep.inStockQuantity) {
          await store.toggleStepInStock(props.projectId, matchingStep.id, newInStock)
        }
        await cascadeStockToChildren(matchingStep.blueprintTypeId, matchingStep.activityType, addedQuantity)
        cascadeTriggered = true
      }
    }
  }

  if (cascadeTriggered) {
    saveStockToStorage()
    await store.fetchProject(props.projectId)
  }

  performStockAnalysis()
}

function clearStock() {
  pastedStock.value = ''
  parsedStock.value = []
  stockAnalysisError.value = null
  localStorage.removeItem(getStockStorageKey())
  showClearStockModal.value = false
  performStockAnalysis()
}

function confirmClearStock() {
  showClearStockModal.value = true
}

function startEditStock(item: EnrichedShoppingItem) {
  editingStockTypeId.value = item.typeId
  editingStockValue.value = item.inStock > 0 ? String(item.inStock) : ''
}

function saveEditStock(typeName: string) {
  if (editingStockTypeId.value === null) return
  editingStockTypeId.value = null
  const qty = parseInt(editingStockValue.value, 10)
  const newQty = isNaN(qty) || qty < 0 ? 0 : qty
  const key = typeName.toLowerCase()
  const existingIdx = parsedStock.value.findIndex(p => p.name.toLowerCase() === key)
  if (newQty > 0) {
    if (existingIdx >= 0) {
      parsedStock.value[existingIdx].quantity = newQty
    } else {
      parsedStock.value.push({ name: typeName, quantity: newQty })
    }
  } else {
    if (existingIdx >= 0) {
      parsedStock.value.splice(existingIdx, 1)
    }
  }
  saveStockToStorage()
  performStockAnalysis()
}

function cancelEditStock() {
  editingStockTypeId.value = null
}

// Called by parent when tab is activated
async function activate() {
  if (store.currentProject?.status === 'completed') return
  if (shoppingList.value.length === 0) {
    stockAnalysisLoading.value = true
    try {
      await loadShoppingList()
    } finally {
      stockAnalysisLoading.value = false
    }
  }
  performStockAnalysis()
}

// Re-analyze stock without reparsing (used after loading from storage)
async function reanalyzeStock() {
  if (parsedStock.value.length === 0) return
  if (shoppingList.value.length === 0) {
    stockAnalysisLoading.value = true
    try {
      await loadShoppingList()
    } finally {
      stockAnalysisLoading.value = false
    }
  }
  performStockAnalysis()
}

// Refresh shopping list after purchased status changes (called by parent via StepsTab)
async function refreshAfterPurchaseChange() {
  await loadShoppingList()
  performStockAnalysis()
}

// ─── Purchases (merged from PurchasesTab) ───
const purchasesLoading = ref(false)
const linkingId = ref<number | null>(null)
const suggestionsPage = ref(1)
const purchasesPage = ref(1)
const PURCHASES_PER_PAGE = 15

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

// Paginated suggestions
const paginatedSuggestions = computed(() => {
  const start = (suggestionsPage.value - 1) * PURCHASES_PER_PAGE
  return purchasesStore.suggestions.slice(start, start + PURCHASES_PER_PAGE)
})

const totalSuggestionsPages = computed(() =>
  Math.ceil(purchasesStore.suggestions.length / PURCHASES_PER_PAGE),
)

// Paginated purchases
const paginatedPurchases = computed(() => {
  const start = (purchasesPage.value - 1) * PURCHASES_PER_PAGE
  return projectPurchases.value.slice(start, start + PURCHASES_PER_PAGE)
})

const totalPurchasesPages = computed(() =>
  Math.ceil(projectPurchases.value.length / PURCHASES_PER_PAGE),
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

async function linkSuggestion(suggestion: { transactionUuid: string; typeId: number; transactionId: number }) {
  const step = findStepForType(suggestion.typeId)
  if (!step) return

  linkingId.value = suggestion.transactionId
  try {
    await purchasesStore.createPurchase(props.projectId, step.id, {
      transactionId: suggestion.transactionUuid,
    })
    await Promise.all([
      store.fetchProject(props.projectId),
      purchasesStore.fetchSuggestions(props.projectId),
    ])
    await loadShoppingList()
    performStockAnalysis()
  } finally {
    linkingId.value = null
  }
}

async function removePurchase(stepId: string, purchaseId: string) {
  await purchasesStore.deletePurchase(props.projectId, stepId, purchaseId)
  await Promise.all([
    store.fetchProject(props.projectId),
    purchasesStore.fetchSuggestions(props.projectId),
  ])
  await loadShoppingList()
  performStockAnalysis()
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
  loadPersistedStock,
  reanalyzeStock,
  activate,
  loadShoppingList,
  refreshAfterPurchaseChange,
  showClearStockModal,
  parsedStock,
  clearStock,
  projectPurchasesTotalCost,
  loadSuggestions,
})
</script>

<template>
  <div>
    <!-- Loading state -->
    <div v-if="shoppingLoading || stockAnalysisLoading" class="text-center py-8 text-slate-500">
      <svg class="w-6 h-6 animate-spin mx-auto mb-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      Chargement...
    </div>

    <!-- Error state with retry -->
    <div v-else-if="shoppingPriceError && shoppingList.length === 0" class="text-center py-8">
      <div class="bg-red-900/20 border border-red-500/30 rounded-xl p-6 max-w-md mx-auto">
        <svg class="w-10 h-10 mx-auto mb-3 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <p class="text-red-400 font-medium mb-2">Erreur de chargement</p>
        <p class="text-slate-400 text-sm mb-4">{{ shoppingPriceError }}</p>
        <button
          @click="loadShoppingList"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 mx-auto"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Réessayer
        </button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else-if="shoppingList.length === 0" class="text-center py-8 text-slate-500">
      <svg class="w-12 h-12 mx-auto mb-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
      </svg>
      <p>Aucun matériau requis pour ce projet</p>
    </div>

    <!-- Main content -->
    <div v-else class="space-y-4">
      <!-- Header: 3-column config + Stock status -->
      <div v-if="store.currentProject?.status !== 'completed'" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <!-- Col 1: Structure selector -->
        <div class="eve-card p-4">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-2">Structure marché</label>
          <div class="relative">
            <input
              v-model="structureSearchQuery"
              type="text"
              :placeholder="selectedStructure.name || DEFAULT_STRUCTURE_NAME"
              :class="[
                'w-full bg-slate-800 border rounded pl-3 pr-8 py-1.5 text-sm focus:outline-none',
                selectedStructure.id
                  ? 'border-cyan-500/50 text-cyan-400 placeholder-cyan-400'
                  : 'border-slate-600 text-slate-200 placeholder-slate-400 focus:border-cyan-500/50'
              ]"
              @focus="showStructureDropdown = true"
              @blur="onStructureInputBlur"
            />
            <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center">
              <svg v-if="isSearchingStructures" class="w-4 h-4 animate-spin text-cyan-400" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <button
                v-else-if="selectedStructure.id || structureSearchQuery"
                @mousedown.prevent="clearStructure"
                class="text-slate-400 hover:text-slate-200"
                title="Revenir au défaut"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <!-- Dropdown -->
            <div
              v-if="showStructureDropdown && (structureSearchResults.length > 0 || (structureSearchQuery.length >= 3 && !isSearchingStructures))"
              class="absolute z-50 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-48 overflow-y-auto"
            >
              <button
                v-for="struct in structureSearchResults"
                :key="struct.id"
                @mousedown.prevent="selectStructure(struct)"
                class="w-full px-3 py-2 text-left text-sm text-slate-200 hover:bg-slate-700/50 transition-colors border-b border-slate-700/50 last:border-0"
              >
                <div class="truncate">{{ struct.name }}</div>
              </button>
              <div
                v-if="structureSearchQuery.length >= 3 && structureSearchResults.length === 0 && !isSearchingStructures"
                class="px-3 py-2 text-slate-400 text-sm"
              >
                Aucune structure trouvée
              </div>
            </div>
          </div>
          <button
            @click="syncStructureMarket"
            :disabled="shoppingSyncing || (marketStructureProgress?.status === 'started' || marketStructureProgress?.status === 'in_progress')"
            class="mt-2 w-full px-3 py-1.5 bg-cyan-500/20 border border-cyan-500/50 text-cyan-400 rounded text-xs font-medium disabled:opacity-50 flex items-center justify-center gap-1.5 hover:bg-cyan-500/30 transition-colors"
          >
            <svg v-if="shoppingSyncing" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Synchroniser
          </button>
        </div>

        <!-- Col 2: Transport cost -->
        <div class="eve-card p-4">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-2">Coût transport / m³</label>
          <div class="flex items-center gap-1.5">
            <input
              v-model.number="transportCostPerM3"
              type="number"
              min="0"
              step="100"
              class="w-full px-2 py-1.5 bg-slate-800 border border-slate-600 rounded text-slate-200 text-sm font-mono focus:outline-none focus:border-cyan-500"
            />
            <span class="text-xs text-slate-500 whitespace-nowrap">ISK/m³</span>
          </div>
          <button
            @click="loadShoppingList"
            class="mt-2 w-full px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 rounded text-white text-xs font-medium"
          >
            Recalculer
          </button>
        </div>

        <!-- Col 3: Stock rapide -->
        <div class="eve-card p-4">
          <label class="block text-xs text-slate-500 uppercase tracking-wider mb-2">Stock rapide</label>
          <textarea
            v-model="pastedStock"
            placeholder="Coller inventaire EVE..."
            class="w-full h-12 bg-slate-800 border border-slate-700 rounded p-2 text-xs font-mono text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500 resize-none"
          />
          <div class="flex gap-2 mt-2">
            <button
              @click="analyzeStock"
              :disabled="stockAnalysisLoading"
              class="flex-1 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 rounded text-white text-xs font-medium"
            >
              Appliquer
            </button>
            <button
              v-if="parsedStock.length > 0"
              @click="confirmClearStock"
              class="px-3 py-1.5 text-red-400 hover:bg-red-500/10 border border-red-500/30 rounded text-xs"
            >
              Effacer
            </button>
          </div>
        </div>
      </div>

      <!-- Mercure sync progress -->
      <div
        v-if="marketStructureProgress && (marketStructureProgress.status === 'started' || marketStructureProgress.status === 'in_progress')"
        class="p-3 bg-cyan-900/20 border border-cyan-500/30 rounded-lg flex items-center gap-3"
      >
        <svg class="w-5 h-5 text-cyan-400 animate-spin flex-shrink-0" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <div class="flex-1">
          <div class="text-sm text-cyan-300">{{ marketStructureProgress.message || 'Synchronisation du marché...' }}</div>
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
          <svg class="w-5 h-5 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div class="text-sm">
            <p class="text-amber-300">Prix de la structure non disponibles</p>
            <p class="text-amber-400/70 text-xs mt-0.5">
              Les données de marché pour {{ shoppingStructureName }} n'ont pas été synchronisées. Utilisez le bouton "Synchroniser" ci-dessus.
            </p>
          </div>
        </div>
      </div>

      <!-- Error message -->
      <div v-if="stockAnalysisError" class="p-3 bg-red-900/20 border border-red-500/30 rounded-lg flex items-center gap-2">
        <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-red-400 text-sm">{{ stockAnalysisError }}</span>
      </div>

      <!-- Totals summary -->
      <div v-if="shoppingTotals" class="eve-card p-4">
        <div class="grid grid-cols-5 gap-6 text-center">
          <div>
            <div class="text-xs text-slate-500 uppercase mb-1">Volume à acheter</div>
            <div class="font-mono text-slate-200">{{ missingTotals.volume.toLocaleString() }} m³</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 uppercase mb-1">Jita + Import</div>
            <div class="font-mono text-slate-200">{{ formatIsk(hasAnyStock ? missingTotals.jita : shoppingTotals.jitaWithImport) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 uppercase mb-1">Structure</div>
            <div class="font-mono text-slate-200">{{ formatIsk(hasAnyStock ? missingTotals.structure : shoppingTotals.structure) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 uppercase mb-1">Meilleur total</div>
            <div class="font-mono text-emerald-400 text-lg font-bold">{{ formatIsk(hasAnyStock ? missingTotals.price : shoppingTotals.best) }}</div>
          </div>
          <div class="flex items-center justify-center gap-2">
            <button
              v-if="jitaItems.length > 0"
              @click="copyToClipboard(jitaMultibuyFormat, 'jita')"
              class="px-3 py-2 bg-amber-500/20 border border-amber-500/50 text-amber-400 rounded-lg text-xs hover:bg-amber-500/30 transition-colors"
            >
              {{ copiedJita ? '✓ Copié' : `Jita Multibuy` }}
            </button>
            <button
              v-if="structureItems.length > 0"
              @click="copyToClipboard(structureMultibuyFormat, 'structure')"
              class="px-3 py-2 bg-cyan-500/20 border border-cyan-500/50 text-cyan-400 rounded-lg text-xs hover:bg-cyan-500/30 transition-colors"
            >
              {{ copiedStructure ? '✓ Copié' : `Structure Multibuy` }}
            </button>
          </div>
        </div>
      </div>

      <!-- Materials table -->
      <div v-if="enrichedShoppingList.length > 0" class="bg-slate-900 rounded-xl border border-slate-800">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-left py-3 px-3">Matériau</th>
              <th class="text-right py-3 px-2">Quantité</th>
              <th class="text-center py-3 px-2">Stock</th>
              <th class="text-right py-3 px-2">À acheter</th>
              <th class="text-right py-3 px-2">Volume</th>
              <th class="text-right py-3 px-2">Jita + Import</th>
              <th class="text-right py-3 px-2">Structure</th>
              <th class="text-right py-3 px-2">Meilleur</th>
              <th class="text-right py-3 px-2">Économie</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="item in enrichedShoppingList"
              :key="item.typeId"
              class="hover:bg-slate-800/50"
            >
              <td class="py-3 px-3">
                <div class="flex items-center gap-2">
                  <img
                    :src="getTypeIconUrl(item.typeId, 32)"
                    class="w-5 h-5 rounded"
                    @error="onImageError"
                  />
                  <span class="text-slate-200">{{ item.typeName }}</span>
                </div>
              </td>
              <td class="py-3 px-2 text-right font-mono text-slate-300">
                {{ item.quantity.toLocaleString() }}
                <span v-if="item.extraQuantity > 0" class="text-amber-400 text-xs" :title="`+${item.extraQuantity.toLocaleString()} dû à des structures sous-optimales`">
                  (+{{ item.extraQuantity.toLocaleString() }})
                </span>
              </td>
              <!-- Stock (editable) -->
              <td class="py-3 px-2 text-center">
                <div class="flex items-center justify-center gap-1.5">
                  <span
                    class="w-2 h-2 rounded-full flex-shrink-0"
                    :class="[
                      item.status === 'ok' ? 'bg-emerald-500' :
                      item.status === 'partial' ? 'bg-amber-500' : 'bg-red-500'
                    ]"
                  ></span>
                  <input
                    v-if="editingStockTypeId === item.typeId"
                    v-model="editingStockValue"
                    type="number"
                    min="0"
                    class="w-16 bg-slate-700 border border-cyan-500 rounded px-1.5 py-0.5 text-xs text-right font-mono focus:outline-none"
                    @keydown.enter="saveEditStock(item.typeName)"
                    @keydown.escape="cancelEditStock"
                    @blur="saveEditStock(item.typeName)"
                    autofocus
                  />
                  <span
                    v-else
                    @click="startEditStock(item)"
                    class="font-mono text-xs cursor-pointer hover:text-cyan-400 min-w-[2rem]"
                    :class="item.inStock > 0 ? 'text-slate-300' : 'text-slate-600'"
                    title="Cliquer pour modifier"
                  >
                    {{ item.inStock > 0 ? item.inStock.toLocaleString() : '-' }}
                  </span>
                </div>
              </td>
              <!-- À acheter (missing) -->
              <td class="py-3 px-2 text-right font-mono" :class="item.missing > 0 ? 'text-slate-200' : 'text-emerald-400'">
                {{ item.missing > 0 ? item.missing.toLocaleString() : '0' }}
              </td>
              <!-- Volume (based on missing) -->
              <td class="py-3 px-2 text-right font-mono text-slate-400">{{ item.missingVolume.toLocaleString() }} m³</td>
              <!-- Jita (based on missing) -->
              <td class="py-3 px-2 text-right font-mono" :class="item.bestLocation === 'jita' && item.missing > 0 ? 'text-emerald-400' : 'text-slate-300'">
                {{ item.missingJita !== null ? formatIsk(item.missingJita) : '-' }}
              </td>
              <!-- Structure (based on missing) -->
              <td class="py-3 px-2 text-right font-mono" :class="item.bestLocation === 'structure' && item.missing > 0 ? 'text-emerald-400' : 'text-slate-300'">
                {{ item.missingStructure !== null ? formatIsk(item.missingStructure) : '-' }}
              </td>
              <!-- Meilleur (based on missing) -->
              <td class="py-3 px-2 text-right font-mono text-emerald-400">
                {{ item.missingBest !== null ? formatIsk(item.missingBest) : '-' }}
              </td>
              <!-- Économie (based on missing) -->
              <td class="py-3 px-2 text-right font-mono text-emerald-400">
                {{ item.missingSavings !== null ? formatIsk(item.missingSavings) : '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Price info + Apply as material cost -->
      <div class="flex items-center gap-3">
        <div class="flex-1 bg-cyan-900/20 border border-cyan-500/20 rounded-lg px-3 py-2 flex items-center gap-2">
          <svg class="w-4 h-4 text-cyan-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-cyan-300/80 text-xs">
            Prix = ordres de vente les plus bas (Jita Sell + import vs {{ displayStructureName }} Sell)
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
          class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 flex-shrink-0"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Appliquer comme coût matériaux
        </button>
      </div>

      <!-- ─── Purchases sections ─── -->

      <!-- Loading purchases -->
      <div v-if="purchasesLoading" class="text-center py-6 text-slate-500">
        <svg class="w-5 h-5 animate-spin mx-auto mb-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        Chargement des suggestions d'achats...
      </div>

      <template v-else>
        <!-- Purchase suggestions from wallet -->
        <div class="bg-slate-800/50 rounded-xl border border-slate-700 p-4">
          <div class="flex items-center justify-between mb-3">
            <h4 class="text-sm font-semibold text-slate-200">
              Suggestions d'achats (wallet ESI)
              <span v-if="purchasesStore.suggestions.length > 0" class="text-slate-500 font-normal">({{ purchasesStore.suggestions.length }})</span>
            </h4>
            <button
              @click="loadSuggestions"
              class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 border border-slate-600 rounded-lg text-slate-300 text-xs flex items-center gap-1.5"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              Actualiser
            </button>
          </div>

          <div v-if="purchasesStore.suggestions.length === 0" class="text-center py-6 text-slate-500 text-sm">
            Aucune transaction wallet correspondant aux matériaux du projet.
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase">
                  <th class="text-left py-2 px-3">Date</th>
                  <th class="text-left py-2 px-3">Item</th>
                  <th class="text-right py-2 px-3">Qté</th>
                  <th class="text-right py-2 px-3">Prix unit.</th>
                  <th class="text-right py-2 px-3">Total</th>
                  <th class="text-left py-2 px-3">Perso</th>
                  <th class="text-center py-2 px-3">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="suggestion in paginatedSuggestions"
                  :key="suggestion.transactionId"
                  class="border-b border-slate-800/50 hover:bg-slate-800/30"
                  :class="{ 'opacity-50': suggestion.alreadyLinked }"
                >
                  <td class="py-2 px-3 text-slate-400 text-xs">{{ formatDateTime(suggestion.date) }}</td>
                  <td class="py-2 px-3 text-slate-200">{{ suggestion.typeName }}</td>
                  <td class="py-2 px-3 text-right font-mono text-slate-300">{{ suggestion.quantity.toLocaleString() }}</td>
                  <td class="py-2 px-3 text-right font-mono text-slate-400">{{ formatIsk(suggestion.unitPrice) }}</td>
                  <td class="py-2 px-3 text-right font-mono text-slate-200">{{ formatIsk(suggestion.totalPrice) }}</td>
                  <td class="py-2 px-3 text-slate-400 text-xs">{{ suggestion.characterName }}</td>
                  <td class="py-2 px-3 text-center">
                    <span v-if="suggestion.alreadyLinked" class="px-2 py-0.5 rounded text-xs bg-emerald-500/10 text-emerald-400">Lié</span>
                    <button
                      v-else-if="findStepForType(suggestion.typeId)"
                      @click="linkSuggestion(suggestion)"
                      :disabled="linkingId === suggestion.transactionId"
                      class="px-2 py-0.5 rounded text-xs bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 hover:bg-cyan-500/20 disabled:opacity-50"
                    >
                      <template v-if="linkingId === suggestion.transactionId">
                        <svg class="w-3 h-3 animate-spin inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                      </template>
                      <template v-else>Lier</template>
                    </button>
                    <span v-else class="text-xs text-slate-600">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
            <!-- Suggestions pagination -->
            <div v-if="totalSuggestionsPages > 1" class="flex items-center justify-between px-3 py-2 border-t border-slate-700">
              <span class="text-xs text-slate-500">{{ purchasesStore.suggestions.length }} résultat(s)</span>
              <div class="flex items-center gap-2">
                <button
                  @click="suggestionsPage--"
                  :disabled="suggestionsPage <= 1"
                  class="px-2 py-1 rounded text-xs bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  Précédent
                </button>
                <span class="text-xs text-slate-400">{{ suggestionsPage }} / {{ totalSuggestionsPages }}</span>
                <button
                  @click="suggestionsPage++"
                  :disabled="suggestionsPage >= totalSuggestionsPages"
                  class="px-2 py-1 rounded text-xs bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  Suivant
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Linked purchases for this project -->
        <div v-if="store.currentProject?.steps" class="bg-slate-800/50 rounded-xl border border-slate-700 p-4">
          <h4 class="text-sm font-semibold text-slate-200 mb-3">
            Achats liés au projet
            <span v-if="projectPurchases.length > 0" class="text-slate-500 font-normal">({{ projectPurchases.length }})</span>
          </h4>

          <div v-if="projectPurchases.length === 0" class="text-center py-4 text-slate-500 text-sm">
            Aucun achat lié à ce projet.
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase">
                  <th class="text-left py-2 px-3">Step</th>
                  <th class="text-left py-2 px-3">Item</th>
                  <th class="text-right py-2 px-3">Qté</th>
                  <th class="text-right py-2 px-3">Prix unit.</th>
                  <th class="text-right py-2 px-3">Total</th>
                  <th class="text-center py-2 px-3">Source</th>
                  <th class="text-center py-2 px-3">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="purchase in paginatedPurchases"
                  :key="purchase.id"
                  class="border-b border-slate-800/50 hover:bg-slate-800/30"
                >
                  <td class="py-2 px-3 text-slate-400 text-xs">{{ purchase.stepName }}</td>
                  <td class="py-2 px-3 text-slate-200">{{ purchase.typeName }}</td>
                  <td class="py-2 px-3 text-right font-mono text-slate-300">{{ purchase.quantity.toLocaleString() }}</td>
                  <td class="py-2 px-3 text-right font-mono text-slate-400">{{ formatIsk(purchase.unitPrice) }}</td>
                  <td class="py-2 px-3 text-right font-mono text-slate-200">{{ formatIsk(purchase.totalPrice) }}</td>
                  <td class="py-2 px-3 text-center">
                    <span
                      :class="[
                        'px-2 py-0.5 rounded text-xs',
                        purchase.source === 'esi_wallet' ? 'bg-cyan-500/10 text-cyan-400' : 'bg-amber-500/10 text-amber-400'
                      ]"
                    >
                      {{ purchase.source === 'esi_wallet' ? 'ESI' : 'Manuel' }}
                    </span>
                  </td>
                  <td class="py-2 px-3 text-center">
                    <button
                      @click="removePurchase(purchase.stepId, purchase.id)"
                      class="text-slate-500 hover:text-red-400"
                      title="Délier"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            <!-- Purchases pagination -->
            <div v-if="totalPurchasesPages > 1" class="flex items-center justify-between px-3 py-2 border-t border-slate-700">
              <span class="text-xs text-slate-500">{{ projectPurchases.length }} résultat(s)</span>
              <div class="flex items-center gap-2">
                <button
                  @click="purchasesPage--"
                  :disabled="purchasesPage <= 1"
                  class="px-2 py-1 rounded text-xs bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  Précédent
                </button>
                <span class="text-xs text-slate-400">{{ purchasesPage }} / {{ totalPurchasesPages }}</span>
                <button
                  @click="purchasesPage++"
                  :disabled="purchasesPage >= totalPurchasesPages"
                  class="px-2 py-1 rounded text-xs bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  Suivant
                </button>
              </div>
            </div>

            <!-- Cost comparison -->
            <div v-if="projectPurchasesTotalCost > 0" class="mt-4 pt-3 border-t border-slate-700 flex items-center gap-6 text-sm">
              <span class="text-slate-400">Coût réel achats :</span>
              <span class="font-mono text-slate-200">{{ formatIsk(projectPurchasesTotalCost) }}</span>
              <template v-if="store.currentProject?.materialCost">
                <span class="text-slate-400">Estimé :</span>
                <span class="font-mono text-slate-400">{{ formatIsk(store.currentProject.materialCost) }}</span>
                <span
                  :class="[
                    'font-mono',
                    projectPurchasesTotalCost <= store.currentProject.materialCost ? 'text-emerald-400' : 'text-red-400'
                  ]"
                >
                  {{ projectPurchasesTotalCost <= store.currentProject.materialCost ? '-' : '+' }}{{ formatIsk(Math.abs(projectPurchasesTotalCost - store.currentProject.materialCost)) }}
                </span>
              </template>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

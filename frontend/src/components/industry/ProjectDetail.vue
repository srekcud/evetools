<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useIndustryStore } from '@/stores/industry'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { apiRequest, authFetch, safeJsonParse } from '@/services/api'
import type { ShoppingListItem, ShoppingListTotals, ProductionTreeNode } from '@/stores/industry'

interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
  solarSystemId: number | null
}
import StepTree from './StepTree.vue'
import StepHierarchyTree from './StepHierarchyTree.vue'

const props = defineProps<{
  projectId: string
}>()

const emit = defineEmits<{
  close: []
}>()

const store = useIndustryStore()
const authStore = useAuthStore()
const syncStore = useSyncStore()
const { formatIsk, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const activeTab = ref<'steps' | 'shopping'>('steps')
const stepsViewMode = ref<'flat' | 'tree'>('flat')
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
const matchJobsLoading = ref(false)
const matchJobsWarning = ref<string | null>(null)
const regeneratingSteps = ref(false)

// Mercure sync progress
const marketStructureProgress = computed(() => syncStore.getSyncProgress('market-structure'))
const industryProjectProgress = computed(() => syncStore.getSyncProgress('industry-project'))

// Watch for sync completion to refresh data
watch(marketStructureProgress, (progress) => {
  if (progress?.status === 'completed' && activeTab.value === 'shopping') {
    loadShoppingList()
  }
})

watch(industryProjectProgress, (progress) => {
  if (progress?.status === 'completed') {
    store.fetchProject(props.projectId)
    regeneratingSteps.value = false
  }
})

// Project name editing
const editingProjectName = ref(false)
const projectNameEdit = ref('')

function startEditProjectName() {
  projectNameEdit.value = store.currentProject?.name || store.currentProject?.productTypeName || ''
  editingProjectName.value = true
}

async function saveProjectName() {
  if (!editingProjectName.value) return
  editingProjectName.value = false

  const newName = projectNameEdit.value.trim()
  // If empty or same as product name, set to null (use product name)
  const nameToSave = newName && newName !== store.currentProject?.productTypeName ? newName : null

  if (nameToSave !== store.currentProject?.name) {
    await store.updateProject(props.projectId, { name: nameToSave } as never)
  }
}

function cancelProjectNameEdit() {
  editingProjectName.value = false
}

// Stock tab state
interface ParsedStockItem {
  name: string
  quantity: number
}
interface RelevantStockItem {
  typeId: number
  name: string
  needed: number
  inStock: number
  missing: number
  status: 'ok' | 'partial' | 'missing'
  isIntermediate?: boolean // true if this is a craftable intermediate product
}
interface IntermediateInStock {
  typeId: number
  name: string
  inStock: number
  needed: number
  stepId: string
  blueprintTypeId: number
  runsNeeded: number
  runsCovered: number
  status: 'ok' | 'partial' | 'missing'
  activityType: string
}

// Child info for cascade stock propagation
interface ChildInfo {
  typeId: number
  typeName: string
  blueprintTypeId: number
  activityType: string
  quantityPerUnit: number  // Quantity of child needed for 1 unit of parent
  isBuildable: boolean
}

const pastedStock = ref('')
const parsedStock = ref<ParsedStockItem[]>([])
const relevantStock = ref<RelevantStockItem[]>([])
const intermediatesInStock = ref<IntermediateInStock[]>([])
const stockAnalysisError = ref<string | null>(null)
const stockAnalysisLoading = ref(false)
const showClearStockModal = ref(false)
const intermediatesExpanded = ref(false)
const rawMaterialsExpanded = ref(true)

// Map of parent blueprint+activity key to its children (for cascade stock propagation)
const parentChildrenMap = ref<Map<string, ChildInfo[]>>(new Map())

// Rebuild parent-children map when tree changes
watch(
  () => store.currentProject?.tree,
  (tree) => {
    if (tree) {
      buildParentChildrenMap(tree)
    }
  },
  { immediate: true }
)

// Computed: raw materials (non-intermediate items from relevantStock)
const rawMaterials = computed(() => {
  return relevantStock.value.filter(item => !item.isIntermediate)
})

// Copy to clipboard state
const copiedJita = ref(false)
const copiedStructure = ref(false)

// Format duration in seconds to human readable
function formatDuration(seconds: number | null): string {
  if (seconds === null || seconds <= 0) return '-'

  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)

  if (days > 0) {
    if (hours > 0) {
      return `${days}j ${hours}h`
    }
    return `${days}j`
  } else if (hours > 0) {
    return `${hours}h ${minutes}m`
  } else {
    return `${minutes}m`
  }
}

// Estimate total project time
// Strategy: sum the longest step duration at each depth level
// (steps at same depth can run in parallel, but different depths are sequential)
const estimatedProjectTime = computed(() => {
  const steps = store.currentProject?.steps
  if (!steps || steps.length === 0) return null

  // Group steps by depth, find max duration at each depth
  const maxByDepth = new Map<number, number>()

  for (const step of steps) {
    // Skip purchased/in-stock steps
    if (step.purchased || step.inStock) continue
    if (step.timePerRun === null) continue

    const duration = step.timePerRun * step.runs
    const depth = step.depth

    const current = maxByDepth.get(depth) ?? 0
    if (duration > current) {
      maxByDepth.set(depth, duration)
    }
  }

  // Sum all depths (sequential)
  let total = 0
  for (const duration of maxByDepth.values()) {
    total += duration
  }

  return total > 0 ? total : null
})

// Get root products (depth 0 steps) for multi-product display
const rootProducts = computed(() => {
  const steps = store.currentProject?.steps
  if (!steps || steps.length === 0) return []

  // Get unique products at depth 0 (consolidate split jobs)
  const productMap = new Map<number, { typeId: number; typeName: string; runs: number }>()

  for (const step of steps) {
    if (step.depth === 0 && (step.activityType === 'manufacturing' || step.activityType === 'reaction')) {
      const existing = productMap.get(step.productTypeId)
      if (existing) {
        existing.runs += step.runs
      } else {
        productMap.set(step.productTypeId, {
          typeId: step.productTypeId,
          typeName: step.productTypeName,
          runs: step.runs,
        })
      }
    }
  }

  return Array.from(productMap.values())
})

// Check if this is a multi-product project
const isMultiProduct = computed(() => rootProducts.value.length > 1)

// Items to buy at Jita (where jita is best or no data for structure) - only missing items
const jitaItems = computed(() => {
  return enrichedShoppingList.value.filter(item =>
    (item.bestLocation === 'jita' || item.bestLocation === null) && item.missing > 0
  )
})

// Items to buy at structure (where structure is best) - only missing items
const structureItems = computed(() => {
  return enrichedShoppingList.value.filter(item => item.bestLocation === 'structure' && item.missing > 0)
})

// Generate EVE multibuy format for Jita items (uses missing quantities)
const jitaMultibuyFormat = computed(() => {
  return jitaItems.value
    .map(item => `${item.typeName}\t${item.missing}`)
    .join('\n')
})

// Generate EVE multibuy format for structure items (uses missing quantities)
const structureMultibuyFormat = computed(() => {
  return structureItems.value
    .map(item => `${item.typeName}\t${item.missing}`)
    .join('\n')
})

// Short structure name (first part before " - ")
const shortStructureName = computed(() => {
  return shoppingStructureName.value?.split(' - ')[0] || 'Structure'
})

// Enriched shopping list with stock status
interface EnrichedShoppingItem extends ShoppingListItem {
  inStock: number
  missing: number
  missingPrice: number | null  // Price for only the missing quantity
  status: 'ok' | 'partial' | 'missing'
}

const enrichedShoppingList = computed<EnrichedShoppingItem[]>(() => {
  // Build a map of stock items by lowercase name for matching
  const stockMap = new Map<string, number>()
  for (const item of parsedStock.value) {
    const key = item.name.toLowerCase()
    stockMap.set(key, (stockMap.get(key) ?? 0) + item.quantity)
  }

  return shoppingList.value.map(item => {
    const key = item.typeName.toLowerCase()
    const inStock = stockMap.get(key) ?? 0
    const missing = Math.max(0, item.quantity - inStock)
    let status: 'ok' | 'partial' | 'missing' = 'missing'
    if (inStock >= item.quantity) {
      status = 'ok'
    } else if (inStock > 0) {
      status = 'partial'
    }
    // Calculate price for missing quantity only (proportional)
    let missingPrice: number | null = null
    if (item.bestPrice !== null && item.quantity > 0) {
      missingPrice = (missing / item.quantity) * item.bestPrice
    } else if (item.jitaWithImport !== null && item.quantity > 0) {
      missingPrice = (missing / item.quantity) * item.jitaWithImport
    }
    return { ...item, inStock, missing, missingPrice, status }
  })
})

// Calculate totals for missing items only
const missingTotals = computed(() => {
  let totalMissingPrice = 0
  let totalMissingVolume = 0
  for (const item of enrichedShoppingList.value) {
    if (item.missing > 0 && item.missingPrice !== null) {
      totalMissingPrice += item.missingPrice
    }
    if (item.missing > 0 && item.volume) {
      totalMissingVolume += item.volume * item.missing
    }
  }
  return { price: totalMissingPrice, volume: totalMissingVolume }
})

// Stock summary stats
const stockStats = computed(() => {
  const ok = enrichedShoppingList.value.filter(i => i.status === 'ok').length
  const partial = enrichedShoppingList.value.filter(i => i.status === 'partial').length
  const missing = enrichedShoppingList.value.filter(i => i.status === 'missing').length
  return { ok, partial, missing }
})

// Persistent stock storage key
function getStockStorageKey(): string {
  return `industry_stock_${props.projectId}`
}

// Load persisted stock on mount
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

// Save stock to localStorage
function saveStockToStorage() {
  localStorage.setItem(getStockStorageKey(), JSON.stringify({
    raw: pastedStock.value,
    parsed: parsedStock.value,
  }))
}

// BPC Kit modal
const showBpcKitModal = ref(false)
const bpcKitPrice = ref('')
const bpcKitLoading = ref(false)

// Structure search for shopping list
const DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon'
const selectedStructure = ref<{ id: number | null; name: string }>({ id: null, name: '' })
const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
let structureSearchTimeout: ReturnType<typeof setTimeout> | null = null

// Debounced structure search
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
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureDropdown.value = false
}

function clearStructure() {
  selectedStructure.value = { id: null, name: '' }
  structureSearchQuery.value = ''
  structureSearchResults.value = []
}

function onStructureInputBlur() {
  setTimeout(() => {
    showStructureDropdown.value = false
  }, 200)
}

onMounted(async () => {
  await store.fetchProject(props.projectId)
  loadPersistedStock()
  // If we have persisted stock, re-analyze it
  if (parsedStock.value.length > 0) {
    await reanalyzeStock()
  }
})

// Re-analyze stock without reparsing (used after loading from storage)
async function reanalyzeStock() {
  if (parsedStock.value.length === 0) return

  // Load shopping list if needed
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

async function togglePurchased(stepId: string, purchased: boolean) {
  await store.toggleStepPurchased(props.projectId, stepId, purchased)
  // Always refresh shopping list and stock analysis when purchased status changes
  await loadShoppingList()
  performStockAnalysis()
}

async function matchJobs() {
  matchJobsLoading.value = true
  matchJobsWarning.value = null
  try {
    const warning = await store.matchJobs(props.projectId)
    matchJobsWarning.value = warning
    await store.fetchProject(props.projectId)
  } finally {
    matchJobsLoading.value = false
  }
}

// Round up to nearest million
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

      // Auto-fill materialCost if not set and best price available
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

    // Mercure will notify when complete, but also refresh here as fallback
    await loadShoppingList()
  } catch (e) {
    shoppingPriceError.value = e instanceof Error ? e.message : 'Sync failed'
  } finally {
    shoppingSyncing.value = false
  }
}

// Apply shopping list best total as materialCost (uses missing cost if stock exists)
async function applyAsMaterialCost() {
  if (!shoppingTotals.value || !store.currentProject) return
  // Use missing cost if there's stock, otherwise use total
  const costToApply = missingTotals.value.price > 0 ? missingTotals.value.price : shoppingTotals.value.best
  const roundedCost = roundUpToMillion(costToApply)
  await store.updateProject(props.projectId, { materialCost: roundedCost })
}

async function switchTab(tab: 'steps' | 'shopping') {
  activeTab.value = tab
  // Don't auto-load shopping list for completed projects to avoid unnecessary API calls
  if (tab === 'shopping' && store.currentProject?.status !== 'completed') {
    if (shoppingList.value.length === 0) {
      stockAnalysisLoading.value = true
      try {
        await loadShoppingList()
      } finally {
        stockAnalysisLoading.value = false
      }
    }
    // Always perform stock analysis to show status indicators
    performStockAnalysis()
  }
}

// Parse EVE inventory text (copy/paste from game)
// Inspired by https://github.com/isk-insider/eve-paste
function parseEveStock(text: string): ParsedStockItem[] {
  if (!text.trim()) return []

  const lines = text.trim().split(/\r?\n/)
  const items: ParsedStockItem[] = []

  // Helper to parse quantity with various thousand separators (comma, dot, space)
  function parseQuantity(str: string): number {
    // Remove thousand separators (space, comma, dot used as separator not decimal)
    // EVE quantities are always integers
    const cleaned = str.trim().replace(/[\s,]/g, '').replace(/\.(?=\d{3})/g, '')
    return parseInt(cleaned, 10)
  }

  for (const line of lines) {
    const trimmed = line.trim()
    if (!trimmed) continue
    if (trimmed.toLowerCase().startsWith('total:')) continue

    // 1. Tab-separated format (standard EVE inventory/contract)
    if (trimmed.includes('\t')) {
      const parts = trimmed.split(/\t+/)
      if (parts.length >= 2) {
        const name = parts[0].trim()
        const quantity = parseQuantity(parts[1])

        if (name && /[A-Za-z]/.test(name) && !isNaN(quantity) && quantity > 0) {
          items.push({ name, quantity })
          continue
        }
      }
    }

    // 2. Space-separated: try to extract quantity from the end
    // Handles: "Atmospheric Gases 90 500" (French format = 90500)
    // Also: "Tritanium 1000" or "Tritanium 1,000"
    const spaceMatch = trimmed.match(/^(.+?)\s+([\d\s,.]+)\s*(?:m[³3]|units?)?\s*$/i)
    if (spaceMatch) {
      const name = spaceMatch[1].trim()
      const quantity = parseQuantity(spaceMatch[2])

      if (name && /[A-Za-z]/.test(name) && !isNaN(quantity) && quantity > 0) {
        items.push({ name, quantity })
        continue
      }
    }

    // 3. Fallback: last word might be quantity
    const words = trimmed.split(/\s+/)
    if (words.length >= 2) {
      const lastWord = words[words.length - 1]
      const quantity = parseQuantity(lastWord)

      if (!isNaN(quantity) && quantity > 0 && /^\d/.test(lastWord)) {
        const name = words.slice(0, -1).join(' ').trim()
        if (name && /[A-Za-z]/.test(name)) {
          items.push({ name, quantity })
          continue
        }
      }
    }

    // 4. Ultimate fallback: whole line is item name, quantity = 1
    if (/[A-Za-z]/.test(trimmed) && !trimmed.includes('\t')) {
      items.push({ name: trimmed, quantity: 1 })
    }
  }

  return items
}

// Perform stock analysis (called after parsing or loading from storage)
// If no stock is parsed, shows all shopping list items as missing
function performStockAnalysis() {
  if (shoppingList.value.length === 0) {
    relevantStock.value = []
    intermediatesInStock.value = []
    return
  }

  // Build a map of stock items by lowercase name for matching
  const stockMap = new Map<string, number>()
  for (const item of parsedStock.value) {
    const key = item.name.toLowerCase()
    stockMap.set(key, (stockMap.get(key) ?? 0) + item.quantity)
  }

  // Detect ALL intermediate products (manufacturing and reaction steps)
  // Group by product type to consolidate split jobs
  const intermediateMap = new Map<number, IntermediateInStock>()
  const steps = store.currentProject?.steps ?? []

  // Get the root product type IDs to exclude from intermediates
  const rootProductTypeIds = new Set(
    steps
      .filter(s => s.depth === 0 && (s.activityType === 'manufacturing' || s.activityType === 'reaction'))
      .map(s => s.productTypeId)
  )

  for (const step of steps) {
    // Only consider manufacturing and reaction steps (not copy/invention)
    if (step.activityType !== 'manufacturing' && step.activityType !== 'reaction') continue
    // Skip root products (the final products we're building)
    if (rootProductTypeIds.has(step.productTypeId)) continue
    // Skip already purchased or in-stock steps
    if (step.purchased || step.inStock) continue

    const key = step.productTypeName.toLowerCase()
    const inStock = stockMap.get(key) ?? 0

    // Consolidate split jobs by product type
    const existing = intermediateMap.get(step.productTypeId)
    if (existing) {
      existing.needed += step.quantity
      existing.runsNeeded += step.runs
      // Recalculate runsCovered based on total
      existing.runsCovered = existing.needed > 0
        ? Math.min(existing.runsNeeded, Math.floor(existing.runsNeeded * existing.inStock / existing.needed))
        : 0
      // Update status
      if (existing.inStock >= existing.needed) {
        existing.status = 'ok'
      } else if (existing.inStock > 0) {
        existing.status = 'partial'
      } else {
        existing.status = 'missing'
      }
    } else {
      const runsCovered = step.quantity > 0
        ? Math.min(step.runs, Math.floor(step.runs * inStock / step.quantity))
        : 0

      let status: 'ok' | 'partial' | 'missing' = 'missing'
      if (inStock >= step.quantity) {
        status = 'ok'
      } else if (inStock > 0) {
        status = 'partial'
      }

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

  // Sort: items in stock first, then by name
  intermediatesInStock.value = Array.from(intermediateMap.values())
    .sort((a, b) => {
      // In stock items first
      if (a.inStock > 0 && b.inStock === 0) return -1
      if (a.inStock === 0 && b.inStock > 0) return 1
      // Then by name
      return a.name.localeCompare(b.name)
    })

  // Match against shopping list
  const results: RelevantStockItem[] = []
  for (const material of shoppingList.value) {
    const key = material.typeName.toLowerCase()
    const inStock = stockMap.get(key) ?? 0

    // Only include items that are either in stock or needed
    if (inStock > 0 || material.quantity > 0) {
      const missing = Math.max(0, material.quantity - inStock)
      let status: 'ok' | 'partial' | 'missing' = 'missing'
      if (inStock >= material.quantity) {
        status = 'ok'
      } else if (inStock > 0) {
        status = 'partial'
      }

      results.push({
        typeId: material.typeId,
        name: material.typeName,
        needed: material.quantity,
        inStock,
        missing,
        status,
      })
    }
  }

  // Sort: missing first, then partial, then ok
  const statusOrder = { missing: 0, partial: 1, ok: 2 }
  results.sort((a, b) => {
    if (statusOrder[a.status] !== statusOrder[b.status]) {
      return statusOrder[a.status] - statusOrder[b.status]
    }
    return a.name.localeCompare(b.name)
  })

  relevantStock.value = results
}

// Build a map of parent blueprint+activity to their children for cascade stock propagation
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

      // Recursively traverse children that have blueprints
      if (mat.isBuildable && mat.blueprint) {
        traverse(mat.blueprint)
      }
    }

    if (children.length > 0) {
      parentChildrenMap.value.set(key, children)
    }
  }

  traverse(tree)
}

// Cascade stock to children when a parent intermediate is marked as in stock
// This adds child quantities to parsedStock (for raw materials) or updates step inStockQuantity (for intermediates)
async function cascadeStockToChildren(
  blueprintTypeId: number,
  activityType: string,
  parentQuantityInStock: number,
) {
  const key = `${blueprintTypeId}_${activityType}`
  const children = parentChildrenMap.value.get(key)
  if (!children || parentQuantityInStock <= 0) return

  for (const child of children) {
    // Calculate the child quantity covered by the parent stock
    const childQuantityNeeded = Math.ceil(parentQuantityInStock * child.quantityPerUnit)

    if (child.isBuildable && child.blueprintTypeId > 0) {
      // Find the child step
      const childStep = store.currentProject?.steps?.find(
        s => s.blueprintTypeId === child.blueprintTypeId &&
             s.activityType === child.activityType
      )

      if (childStep && childStep.depth > 0) {
        // Calculate new inStockQuantity (capped at step quantity)
        const newInStock = Math.min(
          childStep.inStockQuantity + childQuantityNeeded,
          childStep.quantity
        )

        if (newInStock > childStep.inStockQuantity) {
          await store.toggleStepInStock(props.projectId, childStep.id, newInStock)
        }
      }

      // Recursively cascade to children of this child
      await cascadeStockToChildren(child.blueprintTypeId, child.activityType, childQuantityNeeded)
    } else {
      // Raw material: add to parsedStock
      const existingIdx = parsedStock.value.findIndex(
        p => p.name.toLowerCase() === child.typeName.toLowerCase()
      )
      if (existingIdx >= 0) {
        parsedStock.value[existingIdx].quantity += childQuantityNeeded
      } else {
        parsedStock.value.push({ name: child.typeName, quantity: childQuantityNeeded })
      }
    }
  }
}

// Handle stock change for an intermediate product
// Updates parsedStock, marks the step as in stock, and cascades to children
async function handleIntermediateStockChange(item: IntermediateInStock, newQuantity: number) {
  const quantity = Math.max(0, newQuantity)
  const oldQuantity = item.inStock
  const addedQuantity = Math.max(0, quantity - oldQuantity)

  // 1. Update parsedStock directly (without saving/analyzing yet)
  const key = item.name.toLowerCase()
  const existingIndex = parsedStock.value.findIndex(p => p.name.toLowerCase() === key)
  if (existingIndex >= 0) {
    if (quantity === 0) {
      parsedStock.value.splice(existingIndex, 1)
    } else {
      parsedStock.value[existingIndex].quantity = quantity
    }
  } else if (quantity > 0) {
    parsedStock.value.push({ name: item.name, quantity })
  }

  if (addedQuantity > 0 && item.blueprintTypeId > 0) {
    // 2. Update the step inStockQuantity
    const step = store.currentProject?.steps?.find(s => s.id === item.stepId)
    if (step) {
      const newInStock = Math.min(step.inStockQuantity + addedQuantity, step.quantity)
      if (newInStock > step.inStockQuantity) {
        await store.toggleStepInStock(props.projectId, step.id, newInStock)
      }

      // 3. Cascade to children (this adds raw materials to parsedStock)
      await cascadeStockToChildren(step.blueprintTypeId, step.activityType, addedQuantity)
    }

    // 4. Save all stock changes and reload project to get updated steps
    saveStockToStorage()
    await store.fetchProject(props.projectId)
  } else {
    // No cascade needed, just save
    saveStockToStorage()
  }

  // 5. Re-analyze stock
  performStockAnalysis()
}

// Analyze pasted stock against shopping list
async function analyzeStock() {
  stockAnalysisError.value = null

  // Check if there's text to parse
  if (!pastedStock.value.trim()) {
    stockAnalysisError.value = 'Collez votre inventaire EVE dans la zone de texte'
    return
  }

  // Parse the stock
  const newItems = parseEveStock(pastedStock.value)

  if (newItems.length === 0) {
    stockAnalysisError.value = 'Aucun item détecté. Vérifiez le format (copier depuis EVE avec Ctrl+A puis Ctrl+C)'
    relevantStock.value = []
    intermediatesInStock.value = []
    return
  }

  // Merge with existing parsed stock (update quantities for existing items, add new ones)
  for (const newItem of newItems) {
    const existingIndex = parsedStock.value.findIndex(
      p => p.name.toLowerCase() === newItem.name.toLowerCase()
    )
    if (existingIndex >= 0) {
      // Update existing item quantity
      parsedStock.value[existingIndex].quantity = newItem.quantity
    } else {
      // Add new item
      parsedStock.value.push(newItem)
    }
  }

  // Clear the textarea after successful parsing
  pastedStock.value = ''

  // Save to localStorage
  saveStockToStorage()

  // Load shopping list if not already loaded
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

  // Check if any pasted items are intermediates and trigger cascade
  const steps = store.currentProject?.steps ?? []
  const rootProductTypeIds = new Set(
    steps
      .filter(s => s.depth === 0 && (s.activityType === 'manufacturing' || s.activityType === 'reaction'))
      .map(s => s.productTypeId)
  )

  let cascadeTriggered = false
  for (const newItem of newItems) {
    // Find if this item matches an intermediate step
    const matchingStep = steps.find(
      s => s.productTypeName.toLowerCase() === newItem.name.toLowerCase() &&
           (s.activityType === 'manufacturing' || s.activityType === 'reaction') &&
           !rootProductTypeIds.has(s.productTypeId) &&
           s.depth > 0 &&
           !s.purchased &&
           !s.inStock
    )

    if (matchingStep && newItem.quantity > 0) {
      // Calculate how much was added vs what was already in stock
      const oldQuantity = (parsedStock.value.find(p => p.name.toLowerCase() === newItem.name.toLowerCase())?.quantity ?? 0) - newItem.quantity
      const addedQuantity = Math.max(0, newItem.quantity - Math.max(0, oldQuantity))

      if (addedQuantity > 0) {
        // Update step inStockQuantity
        const newInStock = Math.min(matchingStep.inStockQuantity + addedQuantity, matchingStep.quantity)
        if (newInStock > matchingStep.inStockQuantity) {
          await store.toggleStepInStock(props.projectId, matchingStep.id, newInStock)
        }

        // Cascade to children
        await cascadeStockToChildren(matchingStep.blueprintTypeId, matchingStep.activityType, addedQuantity)
        cascadeTriggered = true
      }
    }
  }

  // Re-save if cascade added raw materials
  if (cascadeTriggered) {
    saveStockToStorage()
    await store.fetchProject(props.projectId)
  }

  performStockAnalysis()
}

// Update stock quantity by item name (for direct editing in tables)
function updateStockByName(itemName: string, newQuantity: number) {
  const quantity = Math.max(0, newQuantity)
  const key = itemName.toLowerCase()

  // Find existing item in parsedStock
  const existingIndex = parsedStock.value.findIndex(
    p => p.name.toLowerCase() === key
  )

  if (existingIndex >= 0) {
    if (quantity === 0) {
      // Remove item if quantity is 0
      parsedStock.value.splice(existingIndex, 1)
    } else {
      // Update existing item
      parsedStock.value[existingIndex].quantity = quantity
    }
  } else if (quantity > 0) {
    // Add new item
    parsedStock.value.push({ name: itemName, quantity })
  }

  saveStockToStorage()
  performStockAnalysis()
}

// Clear stock analysis
function clearStock() {
  pastedStock.value = ''
  parsedStock.value = []
  stockAnalysisError.value = null
  localStorage.removeItem(getStockStorageKey())
  showClearStockModal.value = false
  // Re-run analysis to show all items as missing
  performStockAnalysis()
}

// Confirm clear stock (shows modal)
function confirmClearStock() {
  showClearStockModal.value = true
}

async function deleteStepHandler(stepId: string) {
  await store.deleteStep(props.projectId, stepId)
}

async function addChildJobHandler(splitGroupId: string | null, stepId: string | null, runs: number) {
  await store.addChildJob(props.projectId, splitGroupId, stepId, runs)
}

async function updateStepRuns(stepId: string, runs: number) {
  await store.updateStepRuns(props.projectId, stepId, runs)
}

async function toggleProjectStatus() {
  if (!store.currentProject) return
  const newStatus = store.currentProject.status === 'completed' ? 'active' : 'completed'
  await store.updateProject(props.projectId, { status: newStatus })
  // Switch to steps tab when marking as completed (shopping tab is disabled)
  if (newStatus === 'completed') {
    activeTab.value = 'steps'
  }
}

function openBpcKitModal() {
  bpcKitPrice.value = ''
  showBpcKitModal.value = true
}

function closeBpcKitModal() {
  showBpcKitModal.value = false
  bpcKitPrice.value = ''
}

async function confirmBpcKit() {
  if (!store.currentProject?.steps) return

  bpcKitLoading.value = true
  try {
    // Parse the price using the ISK parser pattern (default to 0 if empty)
    let kitPrice = 0
    if (bpcKitPrice.value.trim()) {
      const normalized = bpcKitPrice.value.trim().toLowerCase().replace(/,/g, '.')
      const match = normalized.match(/^([\d.]+)\s*([kmb])?$/)
      if (match) {
        let value = parseFloat(match[1])
        const suffix = match[2]
        if (suffix === 'k') value *= 1_000
        else if (suffix === 'm') value *= 1_000_000
        else if (suffix === 'b') value *= 1_000_000_000
        kitPrice = Math.round(value)
      }
    }

    // Mark all BPC steps as purchased (except root products at depth 0)
    const bpcSteps = store.currentProject.steps.filter(s => s.activityType === 'copy' && s.depth > 0)
    for (const step of bpcSteps) {
      if (!step.purchased) {
        try {
          await store.toggleStepPurchased(props.projectId, step.id, true)
        } catch {
          // Ignore errors for individual steps, continue with others
        }
      }
    }

    // Update bpoCost (even if 0)
    if (kitPrice >= 0) {
      await store.updateProject(props.projectId, { bpoCost: kitPrice })
    }

    closeBpcKitModal()
  } finally {
    bpcKitLoading.value = false
  }
}

// Check if there are any BPC steps
function hasBpcSteps(): boolean {
  return store.currentProject?.steps?.some(s => s.activityType === 'copy') ?? false
}

// Copy to clipboard
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

// Format relative time for cache info
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
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between group">
      <div class="flex items-center gap-4">
        <button
          @click="emit('close')"
          class="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-200"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </button>
        <div v-if="store.currentProject" class="flex items-center gap-3">
          <!-- Single product: show one icon -->
          <img
            v-if="!isMultiProduct"
            :src="getTypeIconUrl(store.currentProject.productTypeId, 64)"
            class="w-10 h-10 rounded"
            @error="onImageError"
          />
          <!-- Multi-product: show stacked icons -->
          <div v-else class="flex -space-x-2">
            <img
              v-for="(product, index) in rootProducts.slice(0, 4)"
              :key="product.typeId"
              :src="getTypeIconUrl(product.typeId, 64)"
              class="w-10 h-10 rounded border-2 border-slate-900"
              :style="{ zIndex: 10 - index }"
              :title="product.typeName"
              @error="onImageError"
            />
            <div
              v-if="rootProducts.length > 4"
              class="w-10 h-10 rounded border-2 border-slate-900 bg-slate-700 flex items-center justify-center text-xs text-slate-300"
            >
              +{{ rootProducts.length - 4 }}
            </div>
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h3 v-if="!editingProjectName" class="text-lg font-semibold text-slate-100">
                {{ store.currentProject.displayName }}
              </h3>
              <input
                v-else
                v-model="projectNameEdit"
                type="text"
                class="text-lg font-semibold bg-slate-800 border border-cyan-500 rounded px-2 py-0.5 focus:outline-none"
                @keydown.enter="saveProjectName"
                @keydown.escape="cancelProjectNameEdit"
                @blur="saveProjectName"
                autofocus
              />
              <button
                v-if="!editingProjectName && store.currentProject.status !== 'completed'"
                @click="startEditProjectName"
                class="p-1 text-slate-500 hover:text-cyan-400 opacity-0 group-hover:opacity-100 transition-opacity"
                title="Modifier le nom"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                </svg>
              </button>
            </div>
            <!-- Single product info -->
            <p v-if="!isMultiProduct" class="text-sm text-slate-500">
              <span v-if="store.currentProject.name" class="text-slate-400">{{ store.currentProject.productTypeName }} · </span>
              {{ store.currentProject.runs }} runs - ME {{ store.currentProject.meLevel }} - TE {{ store.currentProject.teLevel }}
              - Créé le {{ formatDateTime(store.currentProject.createdAt) }}
            </p>
            <!-- Multi-product info -->
            <div v-else class="text-sm text-slate-500">
              <div class="flex flex-wrap gap-x-3 gap-y-1">
                <span
                  v-for="product in rootProducts"
                  :key="product.typeId"
                  class="text-slate-400"
                >
                  {{ product.typeName }} <span class="text-slate-500">×{{ product.runs }}</span>
                </span>
              </div>
              <p class="mt-1">
                {{ rootProducts.length }} produits - Créé le {{ formatDateTime(store.currentProject.createdAt) }}
              </p>
            </div>
          </div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button
          v-if="store.currentProject?.status !== 'completed'"
          @click="matchJobs"
          :disabled="matchJobsLoading"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-cyan-800 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium flex items-center gap-2"
        >
          <svg v-if="matchJobsLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
          </svg>
          {{ matchJobsLoading ? 'Synchronisation...' : 'Lier jobs ESI' }}
        </button>
        <button
          @click="toggleProjectStatus"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2',
            store.currentProject?.status === 'completed'
              ? 'bg-amber-600 hover:bg-amber-500 text-white'
              : 'bg-emerald-600 hover:bg-emerald-500 text-white'
          ]"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              v-if="store.currentProject?.status === 'completed'"
              stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
            <path
              v-else
              stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M5 13l4 4L19 7"
            />
          </svg>
          {{ store.currentProject?.status === 'completed' ? 'Réactiver' : 'Terminé' }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="store.isLoading" class="p-8 text-center text-slate-500">
      <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      Chargement...
    </div>

    <!-- Content -->
    <div v-else-if="store.currentProject" class="p-6">
      <!-- ESI Warning -->
      <div
        v-if="matchJobsWarning"
        class="mb-4 p-3 bg-amber-500/20 border border-amber-500/50 rounded-lg flex items-center gap-3"
      >
        <svg class="w-5 h-5 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <span class="text-amber-200 text-sm">{{ matchJobsWarning }}</span>
        <button
          @click="matchJobsWarning = null"
          class="ml-auto p-1 hover:bg-amber-500/30 rounded text-amber-400"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Coût total</p>
          <p class="text-lg font-mono text-slate-200 mt-1">
            {{ formatIsk(store.currentProject.totalCost) }}
          </p>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Coût jobs</p>
          <p class="text-lg font-mono text-slate-200 mt-1">
            {{ formatIsk(store.currentProject.jobsCost) }}
          </p>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Prix de vente</p>
          <p class="text-lg font-mono mt-1" :class="store.currentProject.personalUse ? 'text-slate-600' : 'text-slate-200'">
            {{ store.currentProject.personalUse ? 'N/A' : (store.currentProject.sellPrice !== null ? formatIsk(store.currentProject.sellPrice) : '-') }}
          </p>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Profit</p>
          <p
            class="text-lg font-mono mt-1"
            :class="
              store.currentProject.personalUse
                ? 'text-slate-600'
                : store.currentProject.profit !== null
                  ? store.currentProject.profit >= 0
                    ? 'text-emerald-400'
                    : 'text-red-400'
                  : 'text-slate-400'
            "
          >
            {{
              store.currentProject.personalUse
                ? 'N/A'
                : store.currentProject.profit !== null
                  ? formatIsk(store.currentProject.profit)
                  : '-'
            }}
          </p>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider flex items-center gap-1">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Durée estimée
          </p>
          <p class="text-lg font-mono text-cyan-400 mt-1">
            {{ estimatedProjectTime ? formatDuration(estimatedProjectTime) : '-' }}
          </p>
        </div>
      </div>

      <!-- Tabs -->
      <div class="flex gap-4 mb-4 border-b border-slate-700">
        <button
          @click="switchTab('steps')"
          :class="[
            'pb-2 text-sm font-medium border-b-2 -mb-px',
            activeTab === 'steps' ? 'border-cyan-500 text-cyan-400' : 'border-transparent text-slate-500 hover:text-slate-300',
          ]"
        >
          Étapes de production
        </button>
        <button
          @click="store.currentProject?.status !== 'completed' && switchTab('shopping')"
          :disabled="store.currentProject?.status === 'completed'"
          :class="[
            'pb-2 text-sm font-medium border-b-2 -mb-px',
            store.currentProject?.status === 'completed'
              ? 'border-transparent text-slate-600 cursor-not-allowed'
              : activeTab === 'shopping' ? 'border-cyan-500 text-cyan-400' : 'border-transparent text-slate-500 hover:text-slate-300',
          ]"
          :title="store.currentProject?.status === 'completed' ? 'Non disponible pour les projets terminés' : ''"
        >
          Matériaux & Stock
        </button>
      </div>

      <!-- Steps tab -->
      <div v-if="activeTab === 'steps'">
        <!-- Toolbar: BPC Kit + View toggle -->
        <div class="mb-4 flex items-center justify-between">
          <!-- BPC Kit button (hidden for completed projects) -->
          <div class="flex items-center gap-4">
            <button
              v-if="hasBpcSteps() && store.currentProject?.status !== 'completed'"
              @click="openBpcKitModal"
              class="flex items-center gap-2 text-sm px-3 py-1.5 bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/50 rounded-lg text-blue-400"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              BPC Kit
            </button>
          </div>

          <!-- View mode toggle -->
          <div class="flex items-center gap-1 bg-slate-800 rounded-lg p-1">
            <button
              @click="stepsViewMode = 'flat'"
              :class="[
                'px-3 py-1.5 text-xs font-medium rounded transition-colors',
                stepsViewMode === 'flat'
                  ? 'bg-cyan-600 text-white'
                  : 'text-slate-400 hover:text-slate-200'
              ]"
            >
              <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
                Liste
              </span>
            </button>
            <button
              @click="stepsViewMode = 'tree'"
              :class="[
                'px-3 py-1.5 text-xs font-medium rounded transition-colors',
                stepsViewMode === 'tree'
                  ? 'bg-cyan-600 text-white'
                  : 'text-slate-400 hover:text-slate-200'
              ]"
            >
              <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h4v4H3V4zm0 8h4v4H3v-4zm0 8h4v4H3v-4zm8-16h10M11 12h10M11 20h10" />
                </svg>
                Arbre
              </span>
            </button>
          </div>
        </div>

        <!-- Steps list (flat view) -->
        <div v-if="stepsViewMode === 'flat'">
          <div v-if="store.currentProject.steps && store.currentProject.steps.length > 0">
            <StepTree
              :steps="store.currentProject.steps"
              :readonly="store.currentProject.status === 'completed'"
              @toggle-purchased="togglePurchased"
              @update-step-runs="updateStepRuns"
              @delete-step="deleteStepHandler"
              @add-child-job="addChildJobHandler"
            />
          </div>
          <div v-else class="text-center py-8 text-slate-500">
            Aucune étape de production
          </div>
        </div>

        <!-- Steps tree (hierarchy view) -->
        <div v-else-if="stepsViewMode === 'tree'">
          <div v-if="store.currentProject.tree && store.currentProject.steps">
            <StepHierarchyTree
              :tree="store.currentProject.tree"
              :steps="store.currentProject.steps"
              :readonly="store.currentProject.status === 'completed'"
              @toggle-purchased="togglePurchased"
            />
          </div>
          <div v-else class="text-center py-8 text-slate-500">
            Arbre de production non disponible
          </div>
        </div>
      </div>

      <!-- Matériaux & Stock tab (unified view) -->
      <div v-if="activeTab === 'shopping'">
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
          <!-- Header: Stats + Config -->
          <div class="flex flex-wrap items-start justify-between gap-4">
            <!-- Stock status badges -->
            <div class="flex items-center gap-3">
              <div
                class="flex items-center gap-1.5 px-2.5 py-1 bg-emerald-900/30 border border-emerald-500/30 rounded-full cursor-help"
                title="Matériaux complets - quantité en stock suffisante"
              >
                <span class="text-emerald-400 text-sm font-bold">{{ stockStats.ok }}</span>
                <span class="text-emerald-300/70 text-xs">✓</span>
              </div>
              <div
                class="flex items-center gap-1.5 px-2.5 py-1 bg-amber-900/30 border border-amber-500/30 rounded-full cursor-help"
                title="Matériaux partiels - quantité en stock insuffisante"
              >
                <span class="text-amber-400 text-sm font-bold">{{ stockStats.partial }}</span>
                <span class="text-amber-300/70 text-xs">◐</span>
              </div>
              <div
                class="flex items-center gap-1.5 px-2.5 py-1 bg-red-900/30 border border-red-500/30 rounded-full cursor-help"
                title="Matériaux manquants - rien en stock"
              >
                <span class="text-red-400 text-sm font-bold">{{ stockStats.missing }}</span>
                <span class="text-red-300/70 text-xs">✗</span>
              </div>
              <span
                v-if="parsedStock.length > 0"
                class="text-xs text-slate-500 flex items-center gap-1 cursor-help"
                title="Nombre total d'items différents dans votre stock"
              >
                <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
                {{ parsedStock.length }} items en stock
              </span>
            </div>

            <!-- Config controls (disabled for completed projects) -->
            <div v-if="store.currentProject?.status !== 'completed'" class="flex flex-wrap items-end gap-3">
              <!-- Structure selector -->
              <div class="relative min-w-[220px]">
                <label class="block text-xs text-slate-500 mb-1">Structure</label>
                <div class="relative">
                  <input
                    v-model="structureSearchQuery"
                    type="text"
                    :placeholder="selectedStructure.id ? selectedStructure.name : DEFAULT_STRUCTURE_NAME.split(' - ')[0]"
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
              </div>

              <!-- Transport cost -->
              <div>
                <label class="block text-xs text-slate-500 mb-1">Transport</label>
                <div class="flex items-center gap-1.5">
                  <input
                    v-model.number="transportCostPerM3"
                    type="number"
                    min="0"
                    step="100"
                    class="w-20 px-2 py-1.5 bg-slate-800 border border-slate-600 rounded text-slate-200 text-sm font-mono focus:outline-none focus:border-cyan-500"
                  />
                  <span class="text-xs text-slate-500">ISK/m³</span>
                </div>
              </div>

              <!-- Recalculate button -->
              <button
                @click="loadShoppingList"
                class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 rounded text-white text-sm font-medium"
              >
                Recalculer
              </button>
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

          <!-- Price info banner -->
          <div class="bg-cyan-900/20 border border-cyan-500/20 rounded-lg px-3 py-2 flex items-center gap-2">
            <svg class="w-4 h-4 text-cyan-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-cyan-300/80 text-xs">
              Prix = ordres de vente les plus bas (Jita Sell + import vs {{ shortStructureName }} Sell)
            </p>
            <div v-if="shoppingStructureFromCache && shoppingStructureLastSync" class="ml-auto text-xs text-slate-500 flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              {{ formatRelativeTime(shoppingStructureLastSync) }}
            </div>
          </div>

          <!-- Structure not accessible warning -->
          <div
            v-if="shoppingStructureAccessible === false && !shoppingPriceError && store.currentProject?.status !== 'completed'"
            class="bg-amber-900/30 border border-amber-500/50 rounded-lg p-3"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div class="text-sm">
                  <p class="text-amber-300">Prix de la structure non disponibles</p>
                  <p class="text-amber-400/70 text-xs mt-0.5">
                    Les données de marché pour {{ shoppingStructureName }} n'ont pas été synchronisées.
                  </p>
                </div>
              </div>
              <button
                @click="syncStructureMarket"
                :disabled="shoppingSyncing || (marketStructureProgress?.status === 'started' || marketStructureProgress?.status === 'in_progress')"
                class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
              >
                <svg v-if="shoppingSyncing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Synchroniser
              </button>
            </div>
          </div>

          <!-- Error message -->
          <div v-if="stockAnalysisError" class="p-3 bg-red-900/20 border border-red-500/30 rounded-lg flex items-center gap-2">
            <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span class="text-red-400 text-sm">{{ stockAnalysisError }}</span>
          </div>

          <!-- Paste area -->
          <div class="flex gap-3 items-start">
            <textarea
              v-model="pastedStock"
              placeholder="Coller votre inventaire EVE ici (Ctrl+A puis Ctrl+C depuis le jeu)..."
              class="flex-1 h-16 bg-slate-800 border border-slate-700 rounded-lg p-3 text-sm font-mono text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500 resize-none"
            />
            <div class="flex flex-col gap-2">
              <button
                @click="analyzeStock"
                :disabled="stockAnalysisLoading"
                class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-cyan-800 disabled:cursor-wait rounded-lg text-white text-sm font-medium flex items-center gap-2"
                title="Mettre à jour le stock"
              >
                <svg v-if="stockAnalysisLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                Mettre à jour
              </button>
              <button
                v-if="parsedStock.length > 0"
                @click="confirmClearStock"
                class="px-4 py-2 text-red-400 hover:bg-red-500/10 border border-red-500/30 rounded-lg text-sm font-medium flex items-center gap-2"
                title="Effacer tout le stock"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Effacer
              </button>
            </div>
          </div>

          <!-- Intermediates (collapsible) -->
          <details
            v-if="intermediatesInStock.length > 0"
            class="bg-slate-800/50 border border-slate-700 rounded-lg"
            @toggle="(e: Event) => intermediatesExpanded = (e.target as HTMLDetailsElement).open"
          >
            <summary
              class="px-4 py-3 cursor-pointer flex items-center gap-2 select-none hover:bg-slate-700/30 rounded-lg"
            >
              <svg
                class="w-4 h-4 text-slate-400 transition-transform"
                :class="{ 'rotate-90': intermediatesExpanded }"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
              <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
              </svg>
              <span class="text-sm font-semibold text-slate-200">
                Produits intermédiaires ({{ intermediatesInStock.length }})
              </span>
              <!-- Quick stats -->
              <span v-if="intermediatesInStock.some(i => i.inStock > 0)" class="text-xs text-cyan-400">
                {{ intermediatesInStock.filter(i => i.inStock > 0).length }} en stock
              </span>
            </summary>
            <div class="p-4 border-t border-slate-700">
              <p class="text-xs text-slate-400 mb-3">
                Produits à fabriquer ou réagir. Les items en stock peuvent être utilisés directement.
              </p>

              <!-- Summary for intermediates -->
              <div class="grid grid-cols-3 gap-2 mb-3 text-xs">
                <div class="bg-emerald-900/20 border border-emerald-500/20 rounded px-2 py-1 text-center">
                  <span class="font-bold text-emerald-400">{{ intermediatesInStock.filter(i => i.status === 'ok').length }}</span>
                  <span class="text-emerald-300/70 ml-1">en stock</span>
                </div>
                <div class="bg-amber-900/20 border border-amber-500/20 rounded px-2 py-1 text-center">
                  <span class="font-bold text-amber-400">{{ intermediatesInStock.filter(i => i.status === 'partial').length }}</span>
                  <span class="text-amber-300/70 ml-1">partiel</span>
                </div>
                <div class="bg-slate-700/50 border border-slate-600/50 rounded px-2 py-1 text-center">
                  <span class="font-bold text-slate-400">{{ intermediatesInStock.filter(i => i.status === 'missing').length }}</span>
                  <span class="text-slate-500 ml-1">à produire</span>
                </div>
              </div>

              <div class="space-y-2 max-h-64 overflow-y-auto">
                <div
                  v-for="item in intermediatesInStock"
                  :key="item.stepId"
                  :class="[
                    'flex items-center justify-between rounded-lg px-3 py-2',
                    item.status === 'ok' ? 'bg-emerald-900/20 border border-emerald-500/30' :
                    item.status === 'partial' ? 'bg-amber-900/20 border border-amber-500/30' :
                    'bg-slate-800/30 border border-slate-700/50'
                  ]"
                >
                  <div class="flex items-center gap-3 flex-1 min-w-0">
                    <img
                      :src="getTypeIconUrl(item.typeId, 32)"
                      class="w-6 h-6 rounded flex-shrink-0"
                      @error="onImageError"
                    />
                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-2">
                        <p class="text-sm text-slate-200 truncate">{{ item.name }}</p>
                        <span :class="[
                          'text-[10px] px-1.5 py-0.5 rounded flex-shrink-0',
                          item.activityType === 'reaction' ? 'bg-purple-500/20 text-purple-300' : 'bg-blue-500/20 text-blue-300'
                        ]">
                          {{ item.activityType === 'reaction' ? 'Réaction' : 'Manuf.' }}
                        </span>
                      </div>
                      <div class="flex items-center gap-2 text-xs">
                        <span class="text-slate-500">{{ item.needed.toLocaleString() }} requis</span>
                        <span v-if="item.inStock > 0" :class="item.status === 'ok' ? 'text-emerald-400' : 'text-amber-400'">
                          ({{ item.runsCovered }}/{{ item.runsNeeded }} runs)
                        </span>
                      </div>
                    </div>
                  </div>
                  <!-- Stock input -->
                  <div class="flex items-center gap-2 flex-shrink-0">
                    <input
                      type="number"
                      :value="item.inStock"
                      @change="(e) => handleIntermediateStockChange(item, parseInt((e.target as HTMLInputElement).value) || 0)"
                      min="0"
                      class="w-24 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm font-mono text-right focus:outline-none focus:border-cyan-500"
                      :class="item.status === 'ok' ? 'text-emerald-400' : item.status === 'partial' ? 'text-amber-400' : 'text-slate-500'"
                    />
                    <span
                      class="w-5 text-center text-sm"
                      :class="[
                        item.status === 'ok' ? 'text-emerald-400' :
                        item.status === 'partial' ? 'text-amber-400' : 'text-red-400'
                      ]"
                    >
                      {{ item.status === 'ok' ? '✓' : item.status === 'partial' ? '◐' : '✗' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </details>

          <!-- Raw Materials (collapsible) -->
          <details
            v-if="rawMaterials.length > 0"
            class="bg-slate-800/50 border border-slate-700 rounded-lg"
            :open="true"
            @toggle="(e: Event) => rawMaterialsExpanded = (e.target as HTMLDetailsElement).open"
          >
            <summary
              class="px-4 py-3 cursor-pointer flex items-center justify-between select-none hover:bg-slate-700/30 rounded-lg"
            >
              <div class="flex items-center gap-2">
                <svg
                  class="w-4 h-4 text-slate-400 transition-transform"
                  :class="{ 'rotate-90': rawMaterialsExpanded }"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
                <span class="text-sm font-semibold text-slate-200">
                  Matières premières ({{ rawMaterials.length }})
                </span>
                <!-- Quick stats -->
                <span v-if="rawMaterials.some(i => i.inStock > 0)" class="text-xs text-amber-400">
                  {{ rawMaterials.filter(i => i.inStock > 0).length }} en stock
                </span>
              </div>
            </summary>
            <div class="p-4 border-t border-slate-700">
              <p class="text-xs text-slate-400 mb-3">
                Matériaux de base à acheter ou collecter (minerais, PI, gaz, etc.)
              </p>

              <!-- Summary for raw materials -->
              <div class="grid grid-cols-3 gap-2 mb-3 text-xs">
                <div class="bg-emerald-900/20 border border-emerald-500/20 rounded px-2 py-1 text-center">
                  <span class="font-bold text-emerald-400">{{ rawMaterials.filter(i => i.status === 'ok').length }}</span>
                  <span class="text-emerald-300/70 ml-1">complet</span>
                </div>
                <div class="bg-amber-900/20 border border-amber-500/20 rounded px-2 py-1 text-center">
                  <span class="font-bold text-amber-400">{{ rawMaterials.filter(i => i.status === 'partial').length }}</span>
                  <span class="text-amber-300/70 ml-1">partiel</span>
                </div>
                <div class="bg-slate-700/50 border border-slate-600/50 rounded px-2 py-1 text-center">
                  <span class="font-bold text-slate-400">{{ rawMaterials.filter(i => i.status === 'missing').length }}</span>
                  <span class="text-slate-500 ml-1">manquant</span>
                </div>
              </div>

              <div class="space-y-2 max-h-80 overflow-y-auto">
                <div
                  v-for="item in rawMaterials"
                  :key="item.typeId"
                  :class="[
                    'flex items-center justify-between rounded-lg px-3 py-2',
                    item.status === 'ok' ? 'bg-emerald-900/20 border border-emerald-500/30' :
                    item.status === 'partial' ? 'bg-amber-900/20 border border-amber-500/30' :
                    'bg-slate-800/30 border border-slate-700/50'
                  ]"
                >
                  <div class="flex items-center gap-3 flex-1 min-w-0">
                    <img
                      :src="getTypeIconUrl(item.typeId, 32)"
                      class="w-6 h-6 rounded flex-shrink-0"
                      @error="onImageError"
                    />
                    <div class="min-w-0 flex-1">
                      <p class="text-sm text-slate-200 truncate">{{ item.name }}</p>
                      <div class="flex items-center gap-2 text-xs">
                        <span class="text-slate-500">{{ item.needed.toLocaleString() }} requis</span>
                        <span v-if="item.missing > 0" class="text-amber-400">
                          ({{ item.missing.toLocaleString() }} manquant)
                        </span>
                      </div>
                    </div>
                  </div>
                  <!-- Stock input -->
                  <div class="flex items-center gap-2 flex-shrink-0">
                    <input
                      type="number"
                      :value="item.inStock"
                      @change="(e) => updateStockByName(item.name, parseInt((e.target as HTMLInputElement).value) || 0)"
                      min="0"
                      class="w-24 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-sm font-mono text-right focus:outline-none focus:border-cyan-500"
                      :class="item.status === 'ok' ? 'text-emerald-400' : item.status === 'partial' ? 'text-amber-400' : 'text-slate-500'"
                    />
                    <span
                      class="w-5 text-center text-sm"
                      :class="[
                        item.status === 'ok' ? 'text-emerald-400' :
                        item.status === 'partial' ? 'text-amber-400' : 'text-red-400'
                      ]"
                    >
                      {{ item.status === 'ok' ? '✓' : item.status === 'partial' ? '◐' : '✗' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </details>

          <!-- Footer: Summary + Actions -->
          <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700">
            <!-- Totals summary -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
              <div>
                <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Volume manquant</p>
                <p class="text-lg font-mono text-slate-200">{{ missingTotals.volume.toLocaleString() }} m³</p>
              </div>
              <div>
                <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Coût total</p>
                <p class="text-sm font-mono text-slate-400">{{ shoppingTotals ? formatIsk(shoppingTotals.best) : '-' }}</p>
              </div>
              <div>
                <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">En stock (valeur)</p>
                <p class="text-sm font-mono text-emerald-400">
                  {{ shoppingTotals ? formatIsk(shoppingTotals.best - missingTotals.price) : '-' }}
                </p>
              </div>
              <div class="md:col-span-2">
                <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">À acheter</p>
                <p class="text-xl font-mono text-amber-400 font-bold">{{ formatIsk(missingTotals.price) }}</p>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap items-center gap-3 pt-3 border-t border-slate-700">
              <!-- Copy buttons -->
              <button
                v-if="jitaItems.length > 0"
                @click="copyToClipboard(jitaMultibuyFormat, 'jita')"
                class="flex items-center gap-2 px-3 py-1.5 bg-cyan-700 hover:bg-cyan-600 rounded-lg text-white text-sm font-medium transition-colors"
              >
                <svg v-if="!copiedJita" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <svg v-else class="w-4 h-4 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                {{ copiedJita ? 'Copié !' : `Copier Jita (${jitaItems.length})` }}
              </button>

              <button
                v-if="structureItems.length > 0"
                @click="copyToClipboard(structureMultibuyFormat, 'structure')"
                class="flex items-center gap-2 px-3 py-1.5 bg-purple-700 hover:bg-purple-600 rounded-lg text-white text-sm font-medium transition-colors"
              >
                <svg v-if="!copiedStructure" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <svg v-else class="w-4 h-4 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                {{ copiedStructure ? 'Copié !' : `Copier ${shortStructureName} (${structureItems.length})` }}
              </button>

              <div class="flex-1"></div>

              <!-- Apply as material cost (uses missing cost if stock exists, otherwise total) -->
              <div v-if="shoppingTotals && shoppingTotals.best > 0 && store.currentProject?.status !== 'completed'" class="flex items-center gap-3">
                <span class="text-sm text-slate-400">
                  Arrondi: <span class="font-mono text-emerald-400">{{ formatIsk(roundUpToMillion(missingTotals.price > 0 ? missingTotals.price : shoppingTotals.best)) }}</span>
                </span>
                <button
                  @click="applyAsMaterialCost"
                  class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  Appliquer comme coût matériaux
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- BPC Kit Modal -->
  <Teleport to="body">
    <div
      v-if="showBpcKitModal"
      class="fixed inset-0 z-50 flex items-center justify-center"
      @keydown.escape="closeBpcKitModal"
    >
      <!-- Backdrop -->
      <div
        class="absolute inset-0 bg-black/70 backdrop-blur-sm"
        @click="closeBpcKitModal"
      ></div>

      <!-- Modal -->
      <div class="relative bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-100 flex items-center gap-2">
            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            BPC Kit
          </h3>
          <button
            @click="closeBpcKitModal"
            class="p-1 hover:bg-slate-800 rounded text-slate-400 hover:text-slate-200"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="px-6 py-4">
          <p class="text-sm text-slate-400 mb-4">
            Cette action va marquer toutes les étapes BPC comme achetées et définir le coût du kit.
          </p>

          <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">
              Prix du kit BPC
            </label>
            <input
              v-model="bpcKitPrice"
              type="text"
              placeholder="ex: 50M, 1.5B, 500000 (vide = 0)"
              class="w-full px-4 py-3 bg-slate-800 border border-slate-600 rounded-lg text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 font-mono"
              @keydown.enter="confirmBpcKit"
              @keydown.escape="closeBpcKitModal"
              autofocus
            />
            <p class="text-xs text-slate-500 mt-2">
              Formats acceptés : 50M, 1.5B, 500K, 1000000
            </p>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-700 flex justify-end gap-3">
          <button
            @click="closeBpcKitModal"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium transition-colors"
          >
            Annuler
          </button>
          <button
            @click="confirmBpcKit"
            :disabled="bpcKitLoading"
            class="px-4 py-2 bg-blue-600 hover:bg-blue-500 disabled:bg-blue-800 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
          >
            <svg v-if="bpcKitLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            Confirmer
          </button>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- Clear Stock Confirmation Modal -->
  <Teleport to="body">
    <div
      v-if="showClearStockModal"
      class="fixed inset-0 z-50 flex items-center justify-center"
      @keydown.escape="showClearStockModal = false"
    >
      <!-- Backdrop -->
      <div
        class="absolute inset-0 bg-black/70 backdrop-blur-sm"
        @click="showClearStockModal = false"
      ></div>

      <!-- Modal -->
      <div class="relative bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-100 flex items-center gap-2">
            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            Effacer le stock ?
          </h3>
          <button
            @click="showClearStockModal = false"
            class="p-1 hover:bg-slate-800 rounded text-slate-400 hover:text-slate-200"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="px-6 py-4">
          <p class="text-sm text-slate-400">
            Cette action supprimera toutes les données de stock enregistrées pour ce projet.
          </p>
          <p class="text-sm text-slate-500 mt-2">
            {{ parsedStock.length }} item(s) seront effacés.
          </p>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-700 flex justify-end gap-3">
          <button
            @click="showClearStockModal = false"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium transition-colors"
          >
            Annuler
          </button>
          <button
            @click="clearStock"
            class="px-4 py-2 bg-red-600 hover:bg-red-500 rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            Effacer
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

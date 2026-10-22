import { ref, computed } from 'vue'
import type { Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTypeNames } from '@/composables/useTypeNames'
import { parseEveStock } from '@/composables/useStockAnalysis'
import type { ParsedStockItem, IntermediateInStock, RelevantStockItem } from '@/composables/useStockAnalysis'
import type { ShoppingListItem, ProductionTreeNode, IndustryProjectStep } from '@/stores/industry/types'

/**
 * Encapsulates stock analysis logic for ShoppingTab:
 * - Parsing pasted stock text
 * - Persisting stock to localStorage
 * - Analyzing stock against shopping list items
 */
export function useShoppingStockAnalysis(
  projectId: Ref<string>,
  shoppingList: Ref<ShoppingListItem[]>,
  _tree: Ref<ProductionTreeNode | null | undefined>,
  steps: Ref<IndustryProjectStep[]>,
  toggleStepInStock: (projectId: string, stepId: string, qty: number) => Promise<void>,
  fetchProject: (id: string) => Promise<void>,
  loadShoppingList: () => Promise<void>,
) {
  const { t } = useI18n()
  const { typeNameLabel } = useTypeNames()

  const pastedStock = ref('')
  const parsedStock = ref<ParsedStockItem[]>([])
  const relevantStock = ref<RelevantStockItem[]>([])
  const intermediatesInStock = ref<IntermediateInStock[]>([])
  const stockAnalysisError = ref<string | null>(null)
  const stockAnalysisLoading = ref(false)
  const showClearStockModal = ref(false)
  const stockPurchaseWarnings = ref<Array<{ name: string; stockQty: number; purchasedQty: number }>>([])

  const hasAnyStock = computed(() => parsedStock.value.length > 0)

  // Stock persistence
  function getStockStorageKey(): string {
    return `industry_stock_${projectId.value}`
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
    const allSteps = steps.value
    const rootProductTypeIds = new Set(
      allSteps
        .filter(s => s.depth === 0 && (s.activityType === 'manufacturing' || s.activityType === 'reaction'))
        .map(s => s.productTypeId),
    )

    for (const step of allSteps) {
      if (step.activityType !== 'manufacturing' && step.activityType !== 'reaction') continue
      if (rootProductTypeIds.has(step.productTypeId)) continue
      if (step.purchased || step.inStock) continue

      const inStock = step.productTypeName === null ? 0 : stockMap.get(step.productTypeName.toLowerCase()) ?? 0
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
          name: typeNameLabel(step.productTypeName, step.productTypeId),
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

  async function analyzeStock() {
    stockAnalysisError.value = null

    if (!pastedStock.value.trim()) {
      stockAnalysisError.value = t('industry.shoppingTab.pasteStockError')
      return
    }

    const newItems = parseEveStock(pastedStock.value)
    if (newItems.length === 0) {
      stockAnalysisError.value = t('industry.shoppingTab.noItemDetected')
      relevantStock.value = []
      intermediatesInStock.value = []
      return
    }

    // Detect potential duplicates between pasted stock and linked purchases
    const warnings: typeof stockPurchaseWarnings.value = []
    for (const newItem of newItems) {
      const material = shoppingList.value.find(
        m => m.typeName.toLowerCase() === newItem.name.toLowerCase(),
      )
      if (material && (material.purchasedQuantity ?? 0) > 0) {
        warnings.push({
          name: material.typeName,
          stockQty: newItem.quantity,
          purchasedQty: material.purchasedQuantity ?? 0,
        })
      }
    }
    stockPurchaseWarnings.value = warnings

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
      stockAnalysisError.value = t('industry.shoppingTab.loadShoppingError')
      relevantStock.value = []
      return
    }

    const allSteps = steps.value
    const rootProductTypeIds = new Set(
      allSteps
        .filter(s => s.depth === 0 && (s.activityType === 'manufacturing' || s.activityType === 'reaction'))
        .map(s => s.productTypeId),
    )

    let stepStockChanged = false
    for (const newItem of newItems) {
      const productionSteps = allSteps
        .filter(s => s.productTypeName?.toLowerCase() === newItem.name.toLowerCase() &&
          (s.activityType === 'manufacturing' || s.activityType === 'reaction') &&
          !rootProductTypeIds.has(s.productTypeId) &&
          s.depth > 0 &&
          !s.purchased)
        .sort((a, b) => a.splitIndex - b.splitIndex)

      let remainingQuantity = newItem.quantity
      for (const productionStep of productionSteps) {
        const newInStock = Math.min(remainingQuantity, productionStep.quantity)
        remainingQuantity -= newInStock
        if (newInStock !== productionStep.inStockQuantity) {
          await toggleStepInStock(projectId.value, productionStep.id, newInStock)
          stepStockChanged = true
        }
      }
    }

    if (stepStockChanged) {
      await fetchProject(projectId.value)
    }

    performStockAnalysis()
  }

  function clearStock() {
    pastedStock.value = ''
    parsedStock.value = []
    stockAnalysisError.value = null
    stockPurchaseWarnings.value = []
    localStorage.removeItem(getStockStorageKey())
    showClearStockModal.value = false
    performStockAnalysis()
  }

  function confirmClearStock() {
    showClearStockModal.value = true
  }

  function updateStockByName(typeName: string, newQty: number) {
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

  return {
    pastedStock,
    parsedStock,
    relevantStock,
    intermediatesInStock,
    stockAnalysisError,
    stockAnalysisLoading,
    showClearStockModal,
    stockPurchaseWarnings,
    hasAnyStock,
    loadPersistedStock,
    saveStockToStorage,
    performStockAnalysis,
    analyzeStock,
    clearStock,
    confirmClearStock,
    updateStockByName,
    reanalyzeStock,
  }
}

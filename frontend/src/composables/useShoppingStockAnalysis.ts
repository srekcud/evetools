import { ref, computed, watch } from 'vue'
import type { Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { parseEveStock } from '@/composables/useStockAnalysis'
import type { ParsedStockItem, IntermediateInStock, RelevantStockItem } from '@/composables/useStockAnalysis'
import type { ShoppingListItem, ProductionTreeNode, IndustryProjectStep } from '@/stores/industry/types'

interface ChildInfo {
  typeId: number
  typeName: string
  blueprintTypeId: number
  activityType: string
  quantityPerUnit: number
  isBuildable: boolean
}

/**
 * Encapsulates stock analysis logic for ShoppingTab:
 * - Parsing pasted stock text
 * - Persisting stock to localStorage
 * - Cascade stock to children intermediate steps
 * - Analyzing stock against shopping list items
 */
export function useShoppingStockAnalysis(
  projectId: Ref<string>,
  shoppingList: Ref<ShoppingListItem[]>,
  tree: Ref<ProductionTreeNode | null | undefined>,
  steps: Ref<IndustryProjectStep[]>,
  toggleStepInStock: (projectId: string, stepId: string, qty: number) => Promise<void>,
  fetchProject: (id: string) => Promise<void>,
  loadShoppingList: () => Promise<void>,
) {
  const { t } = useI18n()

  const pastedStock = ref('')
  const parsedStock = ref<ParsedStockItem[]>([])
  const relevantStock = ref<RelevantStockItem[]>([])
  const intermediatesInStock = ref<IntermediateInStock[]>([])
  const stockAnalysisError = ref<string | null>(null)
  const stockAnalysisLoading = ref(false)
  const showClearStockModal = ref(false)
  const parentChildrenMap = ref<Map<string, ChildInfo[]>>(new Map())
  const stockPurchaseWarnings = ref<Array<{ name: string; stockQty: number; purchasedQty: number }>>([])

  const hasAnyStock = computed(() => parsedStock.value.length > 0)

  // Rebuild parent-children map when tree changes
  watch(tree, (newTree) => {
    if (newTree) buildParentChildrenMap(newTree)
  }, { immediate: true })

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

  function buildParentChildrenMap(treeNode: ProductionTreeNode) {
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
    traverse(treeNode)
  }

  async function cascadeStockToChildren(blueprintTypeId: number, activityType: string, parentQuantityInStock: number) {
    const key = `${blueprintTypeId}_${activityType}`
    const children = parentChildrenMap.value.get(key)
    if (!children || parentQuantityInStock <= 0) return

    for (const child of children) {
      const childQuantityNeeded = Math.ceil(parentQuantityInStock * child.quantityPerUnit)
      if (child.isBuildable && child.blueprintTypeId > 0) {
        const childStep = steps.value.find(
          s => s.blueprintTypeId === child.blueprintTypeId && s.activityType === child.activityType,
        )
        if (childStep && childStep.depth > 0) {
          const newInStock = Math.min(childStep.inStockQuantity + childQuantityNeeded, childStep.quantity)
          if (newInStock > childStep.inStockQuantity) {
            await toggleStepInStock(projectId.value, childStep.id, newInStock)
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

    let cascadeTriggered = false
    for (const newItem of newItems) {
      const matchingStep = allSteps.find(
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
            await toggleStepInStock(projectId.value, matchingStep.id, newInStock)
          }
          await cascadeStockToChildren(matchingStep.blueprintTypeId, matchingStep.activityType, addedQuantity)
          cascadeTriggered = true
        }
      }
    }

    if (cascadeTriggered) {
      saveStockToStorage()
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

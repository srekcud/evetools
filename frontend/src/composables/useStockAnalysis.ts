import { ref, computed, watch, type Ref } from 'vue'
import type { IndustryProject, ProductionTreeNode } from '@/stores/industry/types'
import type { ShoppingListItem } from '@/stores/industry/types'

export interface ParsedStockItem {
  name: string
  quantity: number
}

export interface RelevantStockItem {
  typeId: number
  name: string
  needed: number
  inStock: number
  missing: number
  status: 'ok' | 'partial' | 'missing'
  isIntermediate?: boolean
}

export interface IntermediateInStock {
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

interface ChildInfo {
  typeId: number
  typeName: string
  blueprintTypeId: number
  activityType: string
  quantityPerUnit: number
  isBuildable: boolean
}

/**
 * Parse EVE inventory text into structured items.
 */
export function parseEveStock(text: string): ParsedStockItem[] {
  if (!text.trim()) return []

  const lines = text.trim().split(/\r?\n/)
  const items: ParsedStockItem[] = []

  function parseQuantity(str: string): number {
    const cleaned = str.trim().replace(/[\s,]/g, '').replace(/\.(?=\d{3})/g, '')
    return parseInt(cleaned, 10)
  }

  for (const line of lines) {
    const trimmed = line.trim()
    if (!trimmed) continue
    if (trimmed.toLowerCase().startsWith('total:')) continue

    // 1. Tab-separated format
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

    // 2. Space-separated with quantity at end
    const spaceMatch = trimmed.match(/^(.+?)\s+([\d\s,.]+)\s*(?:m[³3]|units?)?\s*$/i)
    if (spaceMatch) {
      const name = spaceMatch[1].trim()
      const quantity = parseQuantity(spaceMatch[2])
      if (name && /[A-Za-z]/.test(name) && !isNaN(quantity) && quantity > 0) {
        items.push({ name, quantity })
        continue
      }
    }

    // 3. Last word as quantity
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

    // 4. Fallback: whole line = item name, qty = 1
    if (/[A-Za-z]/.test(trimmed) && !trimmed.includes('\t')) {
      items.push({ name: trimmed, quantity: 1 })
    }
  }

  return items
}

/**
 * Composable for stock analysis: parsing, matching, cascade.
 */
export function useStockAnalysis(
  project: Ref<IndustryProject | null>,
  projectId: Ref<string>,
  shoppingList: Ref<ShoppingListItem[]>,
  toggleStepInStock: (projectId: string, stepId: string, qty: number) => Promise<void>,
  fetchProject: (id: string) => Promise<void>,
) {
  const pastedStock = ref('')
  const parsedStock = ref<ParsedStockItem[]>([])
  const relevantStock = ref<RelevantStockItem[]>([])
  const intermediatesInStock = ref<IntermediateInStock[]>([])
  const stockAnalysisError = ref<string | null>(null)
  const stockAnalysisLoading = ref(false)
  const showClearStockModal = ref(false)
  const intermediatesExpanded = ref(false)
  const rawMaterialsExpanded = ref(true)
  const parentChildrenMap = ref<Map<string, ChildInfo[]>>(new Map())

  const rawMaterials = computed(() =>
    relevantStock.value.filter(item => !item.isIntermediate)
  )

  const stockStats = computed(() => {
    const items = relevantStock.value
    return {
      ok: items.filter(i => i.status === 'ok').length,
      partial: items.filter(i => i.status === 'partial').length,
      missing: items.filter(i => i.status === 'missing').length,
    }
  })

  // Rebuild parent-children map when tree changes
  watch(
    () => project.value?.tree,
    (tree) => {
      if (tree) buildParentChildrenMap(tree)
    },
    { immediate: true },
  )

  function getStockStorageKey() {
    return `industry_stock_${projectId.value}`
  }

  function loadPersistedStock() {
    const key = getStockStorageKey()
    const stored = localStorage.getItem(key)
    if (stored) {
      try {
        const data = JSON.parse(stored)
        if (data.parsedItems && Array.isArray(data.parsedItems)) {
          parsedStock.value = data.parsedItems
        }
      } catch (e) {
        // ignore
      }
    }
  }

  function saveStockToStorage() {
    const key = getStockStorageKey()
    localStorage.setItem(key, JSON.stringify({
      raw: pastedStock.value,
      parsedItems: parsedStock.value,
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

    // Detect intermediates
    const intermediateMap = new Map<number, IntermediateInStock>()
    const steps = project.value?.steps ?? []
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
        existing.status = existing.inStock >= existing.needed ? 'ok' : existing.inStock > 0 ? 'partial' : 'missing'
      } else {
        const runsCovered = step.quantity > 0
          ? Math.min(step.runs, Math.floor(step.runs * inStock / step.quantity))
          : 0
        intermediateMap.set(step.productTypeId, {
          typeId: step.productTypeId,
          name: step.productTypeName,
          inStock,
          needed: step.quantity,
          stepId: step.id,
          blueprintTypeId: step.blueprintTypeId,
          runsNeeded: step.runs,
          runsCovered,
          status: inStock >= step.quantity ? 'ok' : inStock > 0 ? 'partial' : 'missing',
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

    // Match against shopping list
    const results: RelevantStockItem[] = []
    for (const material of shoppingList.value) {
      const key = material.typeName.toLowerCase()
      const inStock = stockMap.get(key) ?? 0
      if (inStock > 0 || material.quantity > 0) {
        const missing = Math.max(0, material.quantity - inStock)
        results.push({
          typeId: material.typeId,
          name: material.typeName,
          needed: material.quantity,
          inStock,
          missing,
          status: inStock >= material.quantity ? 'ok' : inStock > 0 ? 'partial' : 'missing',
        })
      }
    }

    const statusOrder = { missing: 0, partial: 1, ok: 2 }
    results.sort((a, b) => {
      if (statusOrder[a.status] !== statusOrder[b.status]) {
        return statusOrder[a.status] - statusOrder[b.status]
      }
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

  async function cascadeStockToChildren(
    blueprintTypeId: number,
    activityType: string,
    parentQuantityInStock: number,
  ) {
    const key = `${blueprintTypeId}_${activityType}`
    const children = parentChildrenMap.value.get(key)
    if (!children || parentQuantityInStock <= 0) return

    for (const child of children) {
      const childQuantityNeeded = Math.ceil(parentQuantityInStock * child.quantityPerUnit)
      if (child.isBuildable && child.blueprintTypeId > 0) {
        const childStep = project.value?.steps?.find(
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

  async function handleIntermediateStockChange(item: IntermediateInStock, newQuantity: number) {
    const quantity = Math.max(0, newQuantity)
    const oldQuantity = item.inStock
    const addedQuantity = Math.max(0, quantity - oldQuantity)

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
      const step = project.value?.steps?.find(s => s.id === item.stepId)
      if (step) {
        const newInStock = Math.min(step.inStockQuantity + addedQuantity, step.quantity)
        if (newInStock > step.inStockQuantity) {
          await toggleStepInStock(projectId.value, step.id, newInStock)
        }
        await cascadeStockToChildren(step.blueprintTypeId, step.activityType, addedQuantity)
      }
      saveStockToStorage()
      await fetchProject(projectId.value)
    } else {
      saveStockToStorage()
    }
    performStockAnalysis()
  }

  async function analyzeStock(loadShoppingList: () => Promise<void>) {
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

    // Merge with existing
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

    // Check cascade for intermediates
    const steps = project.value?.steps ?? []
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

  function updateStockByName(itemName: string, newQuantity: number) {
    const quantity = Math.max(0, newQuantity)
    const key = itemName.toLowerCase()
    const existingIndex = parsedStock.value.findIndex(p => p.name.toLowerCase() === key)
    if (existingIndex >= 0) {
      if (quantity === 0) {
        parsedStock.value.splice(existingIndex, 1)
      } else {
        parsedStock.value[existingIndex].quantity = quantity
      }
    } else if (quantity > 0) {
      parsedStock.value.push({ name: itemName, quantity })
    }
    saveStockToStorage()
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

  return {
    pastedStock,
    parsedStock,
    relevantStock,
    intermediatesInStock,
    stockAnalysisError,
    stockAnalysisLoading,
    showClearStockModal,
    intermediatesExpanded,
    rawMaterialsExpanded,
    rawMaterials,
    stockStats,
    loadPersistedStock,
    performStockAnalysis,
    analyzeStock,
    updateStockByName,
    handleIntermediateStockChange,
    clearStock,
    confirmClearStock,
  }
}

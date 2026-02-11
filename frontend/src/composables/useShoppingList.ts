import { ref, computed, type Ref } from 'vue'
import type { ShoppingListItem, ShoppingListTotals, IndustryProject } from '@/stores/industry/types'
import type { ParsedStockItem } from './useStockAnalysis'
import { useProjectsStore } from '@/stores/industry/projects'

export interface EnrichedShoppingItem extends ShoppingListItem {
  inStock: number
  missing: number
  missingPrice: number | null
  status: 'ok' | 'partial' | 'missing'
}

export function useShoppingList(
  project: Ref<IndustryProject | null>,
  projectId: Ref<string>,
  parsedStock: Ref<ParsedStockItem[]>,
) {
  const projectsStore = useProjectsStore()

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

  const enrichedShoppingList = computed<EnrichedShoppingItem[]>(() => {
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
      if (inStock >= item.quantity) status = 'ok'
      else if (inStock > 0) status = 'partial'

      const missingPrice = item.bestPrice !== null && item.quantity > 0
        ? (item.bestPrice / item.quantity) * missing
        : null

      return { ...item, inStock, missing, missingPrice, status }
    })
  })

  const missingTotals = computed(() => {
    const items = enrichedShoppingList.value
    let jita = 0, structure = 0, volume = 0
    for (const item of items) {
      if (item.missing <= 0) continue
      const ratio = item.quantity > 0 ? item.missing / item.quantity : 0
      if (item.jitaTotal !== null) jita += item.jitaTotal * ratio
      if (item.structureTotal !== null) structure += item.structureTotal * ratio
      volume += item.totalVolume * ratio
    }
    return { jita, structure, volume }
  })

  const shortStructureName = computed(() => {
    return shoppingStructureName.value?.split(' - ')[0] || 'Structure'
  })

  const jitaItems = computed(() =>
    enrichedShoppingList.value.filter(item =>
      (item.bestLocation === 'jita' || item.bestLocation === null) && item.missing > 0,
    ),
  )

  const structureItems = computed(() =>
    enrichedShoppingList.value.filter(item => item.bestLocation === 'structure' && item.missing > 0),
  )

  const jitaMultibuyFormat = computed(() =>
    jitaItems.value.map(item => `${item.typeName}\t${item.missing}`).join('\n'),
  )

  const structureMultibuyFormat = computed(() =>
    structureItems.value.map(item => `${item.typeName}\t${item.missing}`).join('\n'),
  )

  async function loadShoppingList(structureId?: number) {
    shoppingLoading.value = true
    shoppingPriceError.value = null
    try {
      const data = await projectsStore.fetchShoppingList(
        projectId.value,
        structureId,
        transportCostPerM3.value,
      )
      if (data) {
        shoppingList.value = data.materials
        shoppingStructureName.value = data.structureName
        shoppingStructureAccessible.value = data.structureAccessible
        shoppingStructureFromCache.value = data.structureFromCache
        shoppingStructureLastSync.value = data.structureLastSync
        shoppingTotals.value = data.totals
        shoppingPriceError.value = data.priceError
        transportCostPerM3.value = data.transportCostPerM3

        // Auto-fill materialCost if not set
        if (project.value && project.value.materialCost === null && data.totals.best > 0) {
          await projectsStore.updateProject(projectId.value, {
            materialCost: data.totals.best,
          })
        }
      }
    } catch (e) {
      shoppingPriceError.value = e instanceof Error ? e.message : 'Failed to load shopping list'
    } finally {
      shoppingLoading.value = false
    }
  }

  function roundUpToMillion(value: number): number {
    return Math.ceil(value / 1_000_000) * 1_000_000
  }

  async function applyAsMaterialCost() {
    if (!shoppingTotals.value) return
    await projectsStore.updateProject(projectId.value, {
      materialCost: shoppingTotals.value.best,
    })
  }

  async function copyToClipboard(text: string, type: 'jita' | 'structure') {
    try {
      await navigator.clipboard.writeText(text)
      if (type === 'jita') {
        copiedJita.value = true
        setTimeout(() => { copiedJita.value = false }, 2000)
      } else {
        copiedStructure.value = true
        setTimeout(() => { copiedStructure.value = false }, 2000)
      }
    } catch (e) {
      console.error('Failed to copy:', e)
    }
  }

  return {
    shoppingList,
    shoppingStructureName,
    shoppingStructureAccessible,
    shoppingStructureFromCache,
    shoppingStructureLastSync,
    shoppingTotals,
    shoppingPriceError,
    shoppingLoading,
    shoppingSyncing,
    transportCostPerM3,
    copiedJita,
    copiedStructure,
    enrichedShoppingList,
    missingTotals,
    shortStructureName,
    jitaItems,
    structureItems,
    jitaMultibuyFormat,
    structureMultibuyFormat,
    loadShoppingList,
    roundUpToMillion,
    applyAsMaterialCost,
    copyToClipboard,
  }
}

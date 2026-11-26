import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'

export interface PlanetaryStockRow {
  typeId: number
  typeName: string | null
  volumePerUnit: number | null
  weeklyConsumption: number | null
  home: number | null
  hub: number
  corp: number | null
  openOrders: number
  coverageWeeks: number | null
  coverageWithOrdersWeeks: number | null
  toBuy: number | null
}

export interface PlanetaryStock {
  homeLocationId: number | null
  homeLocationName: string | null
  hubStructureId: number
  hubStructureName: string
  coverageWeeks: number
  corpUnknownReason: string | null
  complete: boolean
  rows: PlanetaryStockRow[]
}

export interface PurchaseLine {
  typeId: number
  typeName: string
  quantity: number
}

const STOCK_ENDPOINT = '/planetary/stock'

export const usePlanetaryStockStore = defineStore('planetaryStock', () => {
  const stock = ref<PlanetaryStock | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  const rows = computed<PlanetaryStockRow[]>(() => stock.value?.rows ?? [])

  const purchaseList = computed<PurchaseLine[]>(() => {
    const lines: PurchaseLine[] = []
    for (const row of rows.value) {
      if (row.toBuy !== null && row.toBuy > 0) {
        lines.push({ typeId: row.typeId, typeName: row.typeName ?? String(row.typeId), quantity: row.toBuy })
      }
    }
    return lines
  })

  const purchaseVolume = computed<number | null>(() => {
    let total = 0
    for (const row of rows.value) {
      if (row.toBuy === null || row.toBuy <= 0) continue
      if (row.volumePerUnit === null) return null
      total += row.toBuy * row.volumePerUnit
    }
    return total
  })

  async function load(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      stock.value = await apiRequest<PlanetaryStock>(STOCK_ENDPOINT)
    } catch {
      stock.value = null
      error.value = i18n.global.t('pi.stock.loadError')
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  return { stock, rows, purchaseList, purchaseVolume, isLoading, error, load, clearError }
})

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

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

export const usePlanetaryStockStore = defineStore('planetaryStock', () => {
  const stock = ref<PlanetaryStock | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  const rows = computed<PlanetaryStockRow[]>(() => [])
  const purchaseList = computed<PurchaseLine[]>(() => [])
  const purchaseVolume = computed<number | null>(() => null)

  async function load(): Promise<void> {
    return undefined
  }

  function clearError(): void {
    error.value = null
  }

  return { stock, rows, purchaseList, purchaseVolume, isLoading, error, load, clearError }
})

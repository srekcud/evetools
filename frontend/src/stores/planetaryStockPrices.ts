import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export type PlanetaryStockSignal = 'buy_jita' | 'buy_hub' | 'outbid' | 'place_order'

export interface PlanetaryStockPriceRow {
  typeId: number
  typeName: string | null
  paidPrice: number | null
  hubSell: number | null
  jitaSell: number | null
  paidVsHubPercent: number | null
  myBuyOrder: number | null
  buyOrderVsHubPercent: number | null
  trend7dPercent: number | null
  hubBestBuy: number | null
  signal: PlanetaryStockSignal | null
}

export interface PlanetaryStockUnsyncedWallet {
  characterId: number
  characterName: string
  status: string
}

export interface PlanetaryStockPrices {
  hubStructureId: number
  hubStructureName: string
  paidPriceDays: number
  complete: boolean
  unsyncedWallets: PlanetaryStockUnsyncedWallet[]
  rows: PlanetaryStockPriceRow[]
}

export const usePlanetaryStockPricesStore = defineStore('planetaryStockPrices', () => {
  const prices = ref<PlanetaryStockPrices | null>(null)
  const error = ref<string | null>(null)

  const rows = computed<PlanetaryStockPriceRow[]>(() => prices.value?.rows ?? [])

  async function load(): Promise<void> {
    return Promise.resolve()
  }

  return { prices, rows, error, load }
})

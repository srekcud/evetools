import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'

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

const STOCK_PRICES_ENDPOINT = '/planetary/stock-prices'

export const usePlanetaryStockPricesStore = defineStore('planetaryStockPrices', () => {
  const prices = ref<PlanetaryStockPrices | null>(null)
  const error = ref<string | null>(null)

  const rows = computed<PlanetaryStockPriceRow[]>(() => prices.value?.rows ?? [])

  async function load(): Promise<void> {
    error.value = null

    try {
      prices.value = await apiRequest<PlanetaryStockPrices>(STOCK_PRICES_ENDPOINT)
    } catch {
      prices.value = null
      error.value = i18n.global.t('pi.stock.prices.loadError')
    }
  }

  return { prices, rows, error, load }
})

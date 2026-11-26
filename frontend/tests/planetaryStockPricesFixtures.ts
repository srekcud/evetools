import type { PlanetaryStockPriceRow, PlanetaryStockPrices } from '@/stores/planetaryStockPrices'
import { COOLANT, WATER } from './planetaryPlannerFixtures'
import { BACTERIA, PLASMA } from './planetaryStockFixtures'
import { FAVORITE_HUB_ID, FAVORITE_HUB_NAME } from './planetarySettingsFixtures'

export const PAID_PRICE_DAYS = 90

export function priceRow(typeId: number, typeName: string | null, overrides: Partial<PlanetaryStockPriceRow> = {}): PlanetaryStockPriceRow {
  return {
    typeId,
    typeName,
    paidPrice: null,
    hubSell: null,
    jitaSell: null,
    paidVsHubPercent: null,
    myBuyOrder: null,
    buyOrderVsHubPercent: null,
    trend7dPercent: null,
    hubBestBuy: null,
    signal: null,
    ...overrides,
  }
}

export function pricesOf(rows: PlanetaryStockPriceRow[], overrides: Partial<PlanetaryStockPrices> = {}): PlanetaryStockPrices {
  return {
    hubStructureId: FAVORITE_HUB_ID,
    hubStructureName: FAVORITE_HUB_NAME,
    paidPriceDays: PAID_PRICE_DAYS,
    complete: true,
    unsyncedWallets: [],
    rows,
    ...overrides,
  }
}

export const PLASMA_PRICE_ROW = priceRow(PLASMA, 'Plasma', {
  paidPrice: 412,
  hubSell: 500,
  jitaSell: 388,
  paidVsHubPercent: -17.6,
  myBuyOrder: 61,
  buyOrderVsHubPercent: -87.8,
  trend7dPercent: 4.5,
  hubBestBuy: 64.5,
  signal: 'outbid',
})

export const WATER_PRICE_ROW = priceRow(WATER, 'Water', {
  paidPrice: 90,
  hubSell: 80,
  jitaSell: 70,
  paidVsHubPercent: 12.5,
  trend7dPercent: -3.2,
  signal: 'buy_jita',
})

export const COOLANT_PRICE_ROW = priceRow(COOLANT, 'Coolant', {
  paidPrice: 300,
  hubSell: 320,
  jitaSell: 340,
  paidVsHubPercent: -6.3,
  myBuyOrder: 250,
  buyOrderVsHubPercent: -21.9,
  trend7dPercent: 0,
  hubBestBuy: 250,
  signal: null,
})

export const UNKNOWN_PRICE_ROW = priceRow(BACTERIA, 'Bacteria')

export const PRICES_OF_ALL_KINDS = pricesOf([PLASMA_PRICE_ROW, WATER_PRICE_ROW, COOLANT_PRICE_ROW, UNKNOWN_PRICE_ROW])

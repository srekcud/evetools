import type { RevenueTodoItem } from '@/types/revenueGrid'
import type { PlanetaryStockRow } from '@/stores/planetaryStock'
import type { PlanetaryStockPriceRow } from '@/stores/planetaryStockPrices'

export type StockPinKind = 'bringBack' | 'toBuy' | 'signal'

export type StockPins = Record<StockPinKind, Record<number, number>>

export type StockTranslate = (key: string, params?: Record<string, string | number>) => string

export interface StockTodo {
  items: RevenueTodoItem[]
  pins: StockPins
}

export function buildStockTodo(coverageRows: PlanetaryStockRow[], priceRows: PlanetaryStockPriceRow[], translate: StockTranslate): StockTodo {
  void coverageRows
  void priceRows
  void translate

  return { items: [], pins: { bringBack: {}, toBuy: {}, signal: {} } }
}

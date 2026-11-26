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

const VOLUME_DECIMALS = 2
const PRICE_DECIMALS = 2

function rounded(value: number, decimals: number): number {
  const factor = 10 ** decimals

  return Math.round(value * factor) / factor
}

function volumeEffect(translate: StockTranslate, key: string, quantity: number, volumePerUnit: number | null): string {
  if (volumePerUnit === null) return translate(`${key}.effectUnknownVolume`)

  return translate(`${key}.effect`, { volume: rounded(quantity * volumePerUnit, VOLUME_DECIMALS) })
}

export function buildStockTodo(coverageRows: PlanetaryStockRow[], priceRows: PlanetaryStockPriceRow[], translate: StockTranslate): StockTodo {
  const items: RevenueTodoItem[] = []
  const pins: StockPins = { bringBack: {}, toBuy: {}, signal: {} }

  function addItem(item: RevenueTodoItem): number {
    items.push(item)

    return items.length
  }

  for (const row of coverageRows) {
    const type = row.typeName ?? String(row.typeId)

    if (row.hub > 0) {
      pins.bringBack[row.typeId] = addItem({
        text: translate('pi.stock.todo.bringBack.text', { type, quantity: row.hub }),
        effect: volumeEffect(translate, 'pi.stock.todo.bringBack', row.hub, row.volumePerUnit),
      })
    }

    if (row.toBuy !== null && row.toBuy > 0) {
      pins.toBuy[row.typeId] = addItem({
        text: translate('pi.stock.todo.toBuy.text', { type, quantity: row.toBuy }),
        effect: volumeEffect(translate, 'pi.stock.todo.toBuy', row.toBuy, row.volumePerUnit),
      })
    }
  }

  for (const row of priceRows) {
    const type = row.typeName ?? String(row.typeId)

    if (row.signal === 'buy_jita' && row.jitaSell !== null && row.paidPrice !== null) {
      pins.signal[row.typeId] = addItem({
        text: translate('pi.stock.todo.buyJita.text', { type, jitaSell: row.jitaSell, paidPrice: row.paidPrice }),
        effect: translate('pi.stock.todo.buyJita.effect', { gap: rounded(row.paidPrice - row.jitaSell, PRICE_DECIMALS) }),
      })
    }

    if (row.signal === 'outbid' && row.myBuyOrder !== null && row.hubBestBuy !== null) {
      pins.signal[row.typeId] = addItem({
        text: translate('pi.stock.todo.outbid.text', { type, myBuyOrder: row.myBuyOrder, hubBestBuy: row.hubBestBuy }),
        effect: translate('pi.stock.todo.outbid.effect', { gap: rounded(row.hubBestBuy - row.myBuyOrder, PRICE_DECIMALS) }),
      })
    }
  }

  return { items, pins }
}

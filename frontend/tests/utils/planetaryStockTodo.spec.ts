import { buildStockTodo, type StockTranslate } from '@/utils/planetaryStockTodo'
import { COOLANT, WATER } from '../planetaryPlannerFixtures'
import { MECHANICAL_PARTS_ROW, NO_HOME_ROW, PLASMA, UNKNOWN_EXTRACTION_ROW, WATER_ROW, stockRow } from '../planetaryStockFixtures'
import { priceRow } from '../planetaryStockPricesFixtures'

const translate: StockTranslate = (key, params = {}) => {
  const rendered = Object.keys(params).sort().map(name => `${name}=${params[name]}`).join(',')
  return `${key}|${rendered}`
}

const PLASMA_TO_HANDLE = stockRow(PLASMA, 'Plasma', { volumePerUnit: 0.25, hub: 500, toBuy: 600 })
const COOLANT_TO_BUY = stockRow(COOLANT, 'Coolant', { volumePerUnit: 1.5, hub: 0, toBuy: 40 })

describe('buildStockTodo', () => {
  it('numbers bring back then buy for each coverage row, then the Jita and outbid signals of the price rows: 1 bring back A, 2 buy A, 3 buy at Jita B, 4 outbid A', () => {
    const todo = buildStockTodo(
      [PLASMA_TO_HANDLE, WATER_ROW],
      [
        priceRow(WATER, 'Water', { paidPrice: 90, jitaSell: 70, signal: 'buy_jita' }),
        priceRow(PLASMA, 'Plasma', { myBuyOrder: 61, hubBestBuy: 64.5, signal: 'outbid' }),
      ],
      translate,
    )

    expect(todo.items).toEqual([
      { text: 'pi.stock.todo.bringBack.text|quantity=500,type=Plasma', effect: 'pi.stock.todo.bringBack.effect|volume=125' },
      { text: 'pi.stock.todo.toBuy.text|quantity=600,type=Plasma', effect: 'pi.stock.todo.toBuy.effect|volume=150' },
      { text: 'pi.stock.todo.buyJita.text|jitaSell=70,paidPrice=90,type=Water', effect: 'pi.stock.todo.buyJita.effect|gap=20' },
      { text: 'pi.stock.todo.outbid.text|hubBestBuy=64.5,myBuyOrder=61,type=Plasma', effect: 'pi.stock.todo.outbid.effect|gap=3.5' },
    ])
    expect(todo.pins).toEqual({
      bringBack: { [PLASMA]: 1 },
      toBuy: { [PLASMA]: 2 },
      signal: { [WATER]: 3, [PLASMA]: 4 },
    })
  })

  it('adds no point for a hub quantity of 0, a quantity to buy of 0 or unknown, nor for the buy at the hub and place an order signals', () => {
    const todo = buildStockTodo(
      [UNKNOWN_EXTRACTION_ROW, WATER_ROW, COOLANT_TO_BUY, NO_HOME_ROW],
      [
        priceRow(PLASMA, 'Plasma', { paidPrice: 100, hubSell: 90, signal: 'buy_hub' }),
        priceRow(WATER, 'Water', { paidPrice: 100, hubSell: 120, trend7dPercent: -3.2, signal: 'place_order' }),
        priceRow(MECHANICAL_PARTS_ROW.typeId, 'Mechanical Parts'),
      ],
      translate,
    )

    expect(todo.items.map(item => item.text)).toEqual([
      'pi.stock.todo.toBuy.text|quantity=40,type=Coolant',
      'pi.stock.todo.bringBack.text|quantity=80,type=Oxygen',
    ])
    expect(todo.pins).toEqual({
      bringBack: { [NO_HOME_ROW.typeId]: 2 },
      toBuy: { [COOLANT]: 1 },
      signal: {},
    })
  })

  it('says the volume is unknown for the quantity to bring back of a type without volume per unit', () => {
    const withoutVolume = stockRow(PLASMA, 'Plasma', { volumePerUnit: null, hub: 500, toBuy: 0 })

    const todo = buildStockTodo([withoutVolume], [], translate)

    expect(todo.items).toEqual([{ text: 'pi.stock.todo.bringBack.text|quantity=500,type=Plasma', effect: 'pi.stock.todo.bringBack.effectUnknownVolume|' }])
    expect(todo.pins).toEqual({ bringBack: { [PLASMA]: 1 }, toBuy: {}, signal: {} })
  })
})

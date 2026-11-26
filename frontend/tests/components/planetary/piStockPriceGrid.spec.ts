import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import UnknownValue from '@/components/common/UnknownValue.vue'
import PiStockPriceGrid from '@/components/planetary/PiStockPriceGrid.vue'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'
import { usePlanetaryStockPricesStore, type PlanetaryStockPriceRow, type PlanetaryStockPrices } from '@/stores/planetaryStockPrices'
import { stockOf } from '../../planetaryStockFixtures'
import { COOLANT_PRICE_ROW, PLASMA_PRICE_ROW, UNKNOWN_PRICE_ROW, WATER_PRICE_ROW, priceRow, pricesOf } from '../../planetaryStockPricesFixtures'
import { expectedMessage } from '../../expectedMessage'

const BUY_HUB_TYPE = 91_201
const PLACE_ORDER_TYPE = 91_202
const GAP_TYPES = [91_211, 91_212, 91_213, 91_214, 91_215]

function mountGrid(prices: PlanetaryStockPrices | null) {
  setActivePinia(createPinia())
  usePlanetaryStockStore().stock = stockOf([])
  usePlanetaryStockPricesStore().prices = prices
  return mount(PiStockPriceGrid, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

type Wrapper = ReturnType<typeof mountGrid>

function cell(wrapper: Wrapper, field: string, typeId: number) {
  return wrapper.get(`[data-testid="price-cell-${field}-${typeId}"]`)
}

function digitsOf(text: string): string {
  return text.replace(/[^0-9]/g, '')
}

function isUnknown(wrapper: Wrapper, field: string, typeId: number): boolean {
  return cell(wrapper, field, typeId).findComponent(UnknownValue).exists()
}

function gapRow(typeId: number, paidVsHubPercent: number, buyOrderVsHubPercent: number): PlanetaryStockPriceRow {
  return priceRow(typeId, `Type ${typeId}`, { paidVsHubPercent, buyOrderVsHubPercent })
}

enableAutoUnmount(afterEach)

describe('PiStockPriceGrid', () => {
  it('shows one row per type in the order received with the nine columns and the figures, an unknown figure by the unknown value component and no order as the known label', () => {
    const wrapper = mountGrid(pricesOf([UNKNOWN_PRICE_ROW, PLASMA_PRICE_ROW]))

    expect(wrapper.findAll('[data-testid^="price-cell-paidPrice-"]').map(element => element.attributes('data-testid'))).toEqual([
      `price-cell-paidPrice-${UNKNOWN_PRICE_ROW.typeId}`,
      `price-cell-paidPrice-${PLASMA_PRICE_ROW.typeId}`,
    ])
    expect(wrapper.findAll('th')).toHaveLength(9)
    expect(['paidPrice', 'hubSell', 'jitaSell', 'myBuyOrder'].map(field => digitsOf(cell(wrapper, field, PLASMA_PRICE_ROW.typeId).text()))).toEqual(['412', '500', '388', '61'])
    expect(['paidVsHub', 'buyOrderVsHub', 'trend'].map(field => digitsOf(cell(wrapper, field, PLASMA_PRICE_ROW.typeId).text()))).toEqual(['176', '878', '45'])
    const unknownOfNothingKnown = ['paidPrice', 'hubSell', 'jitaSell', 'paidVsHub', 'buyOrderVsHub', 'trend'].filter(field => isUnknown(wrapper, field, UNKNOWN_PRICE_ROW.typeId))
    expect(unknownOfNothingKnown).toEqual(['paidPrice', 'hubSell', 'jitaSell', 'paidVsHub', 'buyOrderVsHub', 'trend'])
    expect(isUnknown(wrapper, 'myBuyOrder', UNKNOWN_PRICE_ROW.typeId)).toBe(false)
    expect(cell(wrapper, 'myBuyOrder', UNKNOWN_PRICE_ROW.typeId).text()).toBe(expectedMessage('en', 'pi.stock.prices.noOrder'))
    expect(isUnknown(wrapper, 'myBuyOrder', PLASMA_PRICE_ROW.typeId)).toBe(false)
    expect(wrapper.findAllComponents(UnknownValue)).toHaveLength(6)
  })

  it('colours the gaps green under -10 %, amber under -3 % and red otherwise, and the trend with an up arrow in red, a down arrow in green and an equal sign at zero', () => {
    const wrapper = mountGrid(pricesOf([
      gapRow(GAP_TYPES[0], -10.1, -12),
      gapRow(GAP_TYPES[1], -10, -10),
      gapRow(GAP_TYPES[2], -3.1, -3.1),
      gapRow(GAP_TYPES[3], -3, -3),
      gapRow(GAP_TYPES[4], 4, 0),
      PLASMA_PRICE_ROW,
      WATER_PRICE_ROW,
      COOLANT_PRICE_ROW,
    ]))

    expect(GAP_TYPES.map(typeId => cell(wrapper, 'paidVsHub', typeId).attributes('data-tone'))).toEqual(['ok', 'warn', 'warn', 'bad', 'bad'])
    expect(GAP_TYPES.map(typeId => cell(wrapper, 'buyOrderVsHub', typeId).attributes('data-tone'))).toEqual(['ok', 'warn', 'warn', 'bad', 'bad'])
    expect(cell(wrapper, 'trend', PLASMA_PRICE_ROW.typeId).text()).toContain('▲')
    expect(cell(wrapper, 'trend', PLASMA_PRICE_ROW.typeId).html()).toMatch(/text-red-/)
    expect(cell(wrapper, 'trend', WATER_PRICE_ROW.typeId).text()).toContain('▼')
    expect(cell(wrapper, 'trend', WATER_PRICE_ROW.typeId).html()).toMatch(/text-emerald-/)
    expect(cell(wrapper, 'trend', COOLANT_PRICE_ROW.typeId).text()).toContain('= 0')
    expect(cell(wrapper, 'trend', COOLANT_PRICE_ROW.typeId).text()).not.toMatch(/[▲▼]/)
  })

  it('shows the signal pill with its label, a plain dash without signal and the number of the to-do point beside the Jita and outbid signals only', () => {
    const wrapper = mountGrid(pricesOf([
      PLASMA_PRICE_ROW,
      WATER_PRICE_ROW,
      priceRow(BUY_HUB_TYPE, 'Bacteria', { paidPrice: 100, hubSell: 90, signal: 'buy_hub' }),
      priceRow(PLACE_ORDER_TYPE, 'Oxygen', { paidPrice: 100, hubSell: 120, trend7dPercent: -3.2, signal: 'place_order' }),
      COOLANT_PRICE_ROW,
    ]))

    const signalOf = (typeId: number) => cell(wrapper, 'signal', typeId).text()
    expect(signalOf(PLASMA_PRICE_ROW.typeId)).toContain(expectedMessage('en', 'pi.stock.prices.signal.outbid'))
    expect(signalOf(WATER_PRICE_ROW.typeId)).toContain(expectedMessage('en', 'pi.stock.prices.signal.buy_jita'))
    expect(signalOf(BUY_HUB_TYPE)).toContain(expectedMessage('en', 'pi.stock.prices.signal.buy_hub'))
    expect(signalOf(PLACE_ORDER_TYPE)).toContain(expectedMessage('en', 'pi.stock.prices.signal.place_order'))
    expect(signalOf(COOLANT_PRICE_ROW.typeId)).toBe('—')
    expect(wrapper.get(`[data-testid="stock-pin-signal-${PLASMA_PRICE_ROW.typeId}"]`).text()).toBe('1')
    expect(wrapper.get(`[data-testid="stock-pin-signal-${WATER_PRICE_ROW.typeId}"]`).text()).toBe('2')
    expect(wrapper.findAll('[data-testid^="stock-pin-signal-"]')).toHaveLength(2)
  })

  it('adds a note under the grid only when the market is not synced or a wallet is not read', () => {
    const complete = mountGrid(pricesOf([PLASMA_PRICE_ROW]))
    const marketMissing = mountGrid(pricesOf([PLASMA_PRICE_ROW], { complete: false }))
    const walletMissing = mountGrid(pricesOf([PLASMA_PRICE_ROW], { unsyncedWallets: [{ characterId: 2_139_700_001, characterName: 'Alice Tarn', status: 'never_read' }] }))

    expect(complete.find('[data-testid="price-grid-note"]').exists()).toBe(false)
    expect(marketMissing.get('[data-testid="price-grid-note"]').text().length).toBeGreaterThan(0)
    expect(walletMissing.get('[data-testid="price-grid-note"]').text().length).toBeGreaterThan(0)
    expect(marketMissing.get('[data-testid="price-grid-note"]').text()).not.toBe(walletMissing.get('[data-testid="price-grid-note"]').text())
  })
})

import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import OrderBookPanel from '@/components/market/OrderBookPanel.vue'
import type { MarketOrder } from '@/stores/market'
import { tablesInScrollContainer } from '../tableScrollContainer'

const JITA_SELL_ORDERS: MarketOrder[] = [{ price: 5.12, volume: 1_200_000 }, { price: 5.15, volume: 800_000 }]
const JITA_BUY_ORDERS: MarketOrder[] = [{ price: 4.98, volume: 2_500_000 }]
const STRUCTURE_SELL_ORDERS: MarketOrder[] = [{ price: 5.6, volume: 90_000 }]
const STRUCTURE_BUY_ORDERS: MarketOrder[] = [{ price: 4.4, volume: 350_000 }]

function mountOrderBook() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(OrderBookPanel, {
    global: { plugins: [i18n] },
    props: {
      sellOrders: JITA_SELL_ORDERS,
      buyOrders: JITA_BUY_ORDERS,
      structureSellOrders: STRUCTURE_SELL_ORDERS,
      structureBuyOrders: STRUCTURE_BUY_ORDERS,
      hasStructure: true,
      structureName: 'Perimeter - Tranquility Trading Tower',
    },
  })
}

describe('OrderBookPanel on a narrow screen', () => {
  it('puts each of the four order tables in a horizontally scrollable container', () => {
    const wrapper = mountOrderBook()

    expect(tablesInScrollContainer(wrapper)).toEqual([true, true, true, true])
  })
})

import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StockpileShoppingList from '@/components/industry/StockpileShoppingList.vue'
import type { StockpileShoppingItem } from '@/stores/industry/types'
import { tablesInScrollContainer } from '../tableScrollContainer'

const KERNITE_BELOW_TARGET: StockpileShoppingItem = {
  typeId: 20,
  typeName: 'Kernite',
  stage: 'raw_material',
  stock: 6,
  targetQuantity: 40,
  deficit: 34,
  deficitCost: 1_360,
  percent: 15,
}

const FUEL_BLOCK_BELOW_TARGET: StockpileShoppingItem = {
  typeId: 4246,
  typeName: 'Hydrogen Fuel Block',
  stage: 'component',
  stock: 18,
  targetQuantity: 25,
  deficit: 7,
  deficitCost: 112_000,
  percent: 72,
}

function mountShoppingList() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(StockpileShoppingList, {
    global: { plugins: [i18n] },
    props: { shoppingList: [KERNITE_BELOW_TARGET, FUEL_BLOCK_BELOW_TARGET] },
  })
}

describe('StockpileShoppingList on a narrow screen', () => {
  it('puts the shopping list table in a horizontally scrollable container', () => {
    const wrapper = mountShoppingList()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})

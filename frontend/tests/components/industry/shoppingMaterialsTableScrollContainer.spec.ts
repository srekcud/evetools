import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingMaterialsTable from '@/components/industry/shopping/ShoppingMaterialsTable.vue'
import type { EnrichedShoppingItem } from '@/components/industry/ShoppingTab.vue'
import { tablesInScrollContainer } from '../tableScrollContainer'

const TRITANIUM_TO_BUY: EnrichedShoppingItem = {
  typeId: 34,
  typeName: 'Tritanium',
  quantity: 12_500,
  volume: 0.01,
  totalVolume: 125,
  jitaUnitPrice: 4.12,
  jitaTotal: 51_500,
  importCost: 3_000,
  jitaWithImport: 54_500,
  structureUnitPrice: 4.6,
  structureTotal: 57_500,
  bestLocation: 'jita',
  bestPrice: 54_500,
  savings: 3_000,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: 4.2,
  jitaWeightedTotal: 52_500,
  jitaCoverage: 1,
  inStock: 2_500,
  missing: 10_000,
  missingPrice: 41_200,
  missingVolume: 100,
  missingJita: 41_200,
  missingJitaWeighted: 42_000,
  missingJitaCoverage: 1,
  missingStructure: 46_000,
  missingBest: 43_600,
  missingSavings: 2_400,
  status: 'partial',
}

const PYERITE_IN_STOCK: EnrichedShoppingItem = {
  ...TRITANIUM_TO_BUY,
  typeId: 35,
  typeName: 'Pyerite',
  quantity: 3_200,
  totalVolume: 32,
  jitaUnitPrice: 7.25,
  jitaTotal: 23_200,
  structureUnitPrice: 8.1,
  structureTotal: 25_920,
  bestLocation: 'structure',
  bestPrice: 25_920,
  inStock: 3_200,
  missing: 0,
  missingPrice: 0,
  missingVolume: 0,
  missingJita: 0,
  missingJitaWeighted: 0,
  missingStructure: 0,
  missingBest: 0,
  missingSavings: 0,
  status: 'ok',
}

function mountShoppingMaterials() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ShoppingMaterialsTable, {
    global: { plugins: [i18n], stubs: { OpenInGameButton: true } },
    props: { items: [TRITANIUM_TO_BUY, PYERITE_IN_STOCK] },
  })
}

describe('ShoppingMaterialsTable on a narrow screen', () => {
  it('puts the materials table in a horizontally scrollable container', () => {
    const wrapper = mountShoppingMaterials()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})

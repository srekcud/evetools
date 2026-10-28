import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StockpileImportPanel from '@/components/industry/StockpileImportPanel.vue'
import { useStockpileStore } from '@/stores/industry/stockpile'
import type { StockpileImportPreview, StockpileImportPreviewItem } from '@/stores/industry/types'

const NITROGEN_FUEL_BLOCK_TYPE_ID = 4051
const ENRICHED_URANIUM_TYPE_ID = 44
const OXYGEN_TYPE_ID = 3683
const MECHANICAL_PARTS_TYPE_ID = 3689
const COOLANT_TYPE_ID = 9832
const ROBOTICS_TYPE_ID = 9848
const HEAVY_WATER_TYPE_ID = 16272
const LIQUID_OZONE_TYPE_ID = 16273
const STRONTIUM_CLATHRATES_TYPE_ID = 16275
const NITROGEN_ISOTOPES_TYPE_ID = 17888

function previewItem(typeId: number, typeName: string, quantity: number, unitPrice: number | null): StockpileImportPreviewItem {
  return { typeId, typeName, quantity, unitPrice }
}

const NITROGEN_FUEL_BLOCK_PREVIEW_WITH_SEVEN_UNPRICED_TYPES: StockpileImportPreview = {
  stages: {
    raw_material: [
      previewItem(ENRICHED_URANIUM_TYPE_ID, 'Enriched Uranium', 4, null),
      previewItem(OXYGEN_TYPE_ID, 'Oxygen', 20, null),
      previewItem(MECHANICAL_PARTS_TYPE_ID, 'Mechanical Parts', 4, null),
      previewItem(COOLANT_TYPE_ID, 'Coolant', 9, 150),
      previewItem(ROBOTICS_TYPE_ID, 'Robotics', 1, null),
      previewItem(HEAVY_WATER_TYPE_ID, 'Heavy Water', 153, null),
      previewItem(LIQUID_OZONE_TYPE_ID, 'Liquid Ozone', 315, null),
      previewItem(STRONTIUM_CLATHRATES_TYPE_ID, 'Strontium Clathrates', 18, null),
      previewItem(NITROGEN_ISOTOPES_TYPE_ID, 'Nitrogen Isotopes', 405, 0.25),
    ],
    intermediate: [],
    component: [],
    final_product: [previewItem(NITROGEN_FUEL_BLOCK_TYPE_ID, 'Nitrogen Fuel Block', 40, 600.5)],
  },
  totalItems: 10,
  estimatedCost: null,
  missingPriceTypeIds: [
    ENRICHED_URANIUM_TYPE_ID,
    OXYGEN_TYPE_ID,
    MECHANICAL_PARTS_TYPE_ID,
    ROBOTICS_TYPE_ID,
    HEAVY_WATER_TYPE_ID,
    LIQUID_OZONE_TYPE_ID,
    STRONTIUM_CLATHRATES_TYPE_ID,
  ],
}

const NITROGEN_FUEL_BLOCK_PREVIEW_WITHOUT_ROBOTICS_PRICE: StockpileImportPreview = {
  stages: {
    raw_material: [
      previewItem(ENRICHED_URANIUM_TYPE_ID, 'Enriched Uranium', 4, 2),
      previewItem(OXYGEN_TYPE_ID, 'Oxygen', 20, 2),
      previewItem(MECHANICAL_PARTS_TYPE_ID, 'Mechanical Parts', 4, 2),
      previewItem(COOLANT_TYPE_ID, 'Coolant', 9, 150),
      previewItem(ROBOTICS_TYPE_ID, 'Robotics', 1, null),
      previewItem(HEAVY_WATER_TYPE_ID, 'Heavy Water', 153, 2),
      previewItem(LIQUID_OZONE_TYPE_ID, 'Liquid Ozone', 315, 2),
      previewItem(STRONTIUM_CLATHRATES_TYPE_ID, 'Strontium Clathrates', 18, 2),
      previewItem(NITROGEN_ISOTOPES_TYPE_ID, 'Nitrogen Isotopes', 405, 0.25),
    ],
    intermediate: [],
    component: [],
    final_product: [previewItem(NITROGEN_FUEL_BLOCK_TYPE_ID, 'Nitrogen Fuel Block', 40, 600.5)],
  },
  totalItems: 10,
  estimatedCost: null,
  missingPriceTypeIds: [ROBOTICS_TYPE_ID],
}

function mountWithPreview(preview: StockpileImportPreview, locale: 'en' | 'fr') {
  useStockpileStore().importPreview = preview
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(StockpileImportPanel, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  return { wrapper, t: i18n.global.t }
}

function labelledAmount(wrapper: VueWrapper, text: string) {
  const candidates = wrapper.findAll('span, div, p').filter(element => element.text().includes(text) && element.text().replace(text, '').replace(':', '').trim() !== '')
  expect(candidates.length, `element containing ${text} and its amount`).toBeGreaterThan(0)
  return candidates.reduce((smallest, candidate) => (candidate.text().length < smallest.text().length ? candidate : smallest))
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StockpileImportPanel preview with types without a price', () => {
  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows the estimated total cost as unknown, never as the sum of the priced types (%s)', (locale, unknownValueTitle) => {
    const { wrapper, t } = mountWithPreview(NITROGEN_FUEL_BLOCK_PREVIEW_WITH_SEVEN_UNPRICED_TYPES, locale)

    const estimatedCostLine = labelledAmount(wrapper, t('industry.stockpile.import.estTotalCost'))
    expect(estimatedCostLine.get(`[title="${unknownValueTitle}"]`).text()).toBe('—')
    expect(estimatedCostLine.text()).not.toMatch(/\d/)
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('names every type without a price of the preview (%s)', locale => {
    const { wrapper, t } = mountWithPreview(NITROGEN_FUEL_BLOCK_PREVIEW_WITH_SEVEN_UNPRICED_TYPES, locale)

    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missingPriceTypes', {
      types: 'Enriched Uranium, Oxygen, Mechanical Parts, Robotics, Heavy Water, Liquid Ozone, Strontium Clathrates',
    }))
  })

  it('names only the type without a price when every other type of the preview is priced', () => {
    const { wrapper, t } = mountWithPreview(NITROGEN_FUEL_BLOCK_PREVIEW_WITHOUT_ROBOTICS_PRICE, 'en')

    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missingPriceTypes', { types: 'Robotics' }))
    expect(labelledAmount(wrapper, t('industry.stockpile.import.estTotalCost')).get('[title="Unknown value"]').text()).toBe('—')
  })
})

import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StockpileColumn from '@/components/industry/StockpileColumn.vue'
import type { StockpileItemStatus, StockpileStageStatus } from '@/stores/industry/types'

const UNPRICED_COMPONENT_TARGET: StockpileItemStatus = {
  id: '00000000-0000-4000-8000-000000057486',
  typeId: 57486,
  typeName: 'Unpriced Component',
  targetQuantity: 100,
  remainingTargetQuantity: 100,
  stock: 40,
  percent: 40,
  status: 'critical',
  unitPrice: null,
  stockValue: null,
  deficitCost: null,
  sourceProductTypeId: null,
  locations: [],
}

const COMPONENT_STAGE_WITHOUT_PRICE: StockpileStageStatus = {
  items: [UNPRICED_COMPONENT_TARGET],
  totalValue: null,
  healthPercent: 40,
}

function mountComponentColumn(locale: 'en' | 'fr') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(StockpileColumn, {
    global: { plugins: [i18n] },
    props: {
      stage: 'component',
      stageData: COMPONENT_STAGE_WITHOUT_PRICE,
      isLastColumn: false,
      stageColor: 'violet',
      stageName: 'Components',
    },
  })
}

describe('StockpileColumn with a target without a price', () => {
  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows the stage value as unknown, never as 0 (%s)', (locale, unknownValueTitle) => {
    const wrapper = mountComponentColumn(locale)

    expect(wrapper.findAll(`[title="${unknownValueTitle}"]`).map(unknownValue => unknownValue.text())).toEqual(['—'])
    expect(wrapper.text()).not.toContain('---')
  })
})

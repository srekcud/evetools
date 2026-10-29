import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingConfigPanel from '@/components/industry/shopping/ShoppingConfigPanel.vue'

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ShoppingConfigPanel market structure placeholder', () => {
  it('shows the effective market structure name of the shopping list when no market structure is picked', () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(ShoppingConfigPanel, {
      global: { plugins: [i18n] },
      props: {
        selectedStructure: { id: null, name: '' },
        effectiveStructureName: 'Preferred Market',
        transportCostPerM3: 800,
        pastedStock: '',
        parsedStockCount: 0,
        stockAnalysisLoading: false,
        shoppingSyncing: false,
        marketStructureProgress: null,
      },
    })

    expect(wrapper.get('input[id$="-structure"]').attributes('placeholder')).toBe('Preferred Market')
  })

  it('shows the picked market structure name rather than the effective one of the shopping list', () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(ShoppingConfigPanel, {
      global: { plugins: [i18n] },
      props: {
        selectedStructure: { id: 1_000_000_000_004, name: 'Picked Market' },
        effectiveStructureName: 'Preferred Market',
        transportCostPerM3: 650,
        pastedStock: '',
        parsedStockCount: 0,
        stockAnalysisLoading: false,
        shoppingSyncing: false,
        marketStructureProgress: null,
      },
    })

    expect(wrapper.get('input[id$="-structure"]').attributes('placeholder')).toBe('Picked Market')
  })
})

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BuyVsBuildResult } from '@/stores/industry/types'

const NITROGEN_FUEL_BLOCK_TYPE_ID = 4051

const ANALYSIS_WITHOUT_COMPONENT: BuyVsBuildResult = {
  typeId: NITROGEN_FUEL_BLOCK_TYPE_ID,
  typeName: 'Nitrogen Fuel Block',
  isT2: false,
  runs: 1,
  totalProductionCost: 39_452.95,
  sellPrice: 15_000,
  marginPercent: 1375.17,
  components: [],
  buildAllCost: 0,
  buyAllCost: 0,
  optimalMixCost: 0,
  buildTypeIds: [],
  buyTypeIds: [],
  missingData: [],
  sellStructureId: 1_035_466_617_946,
}

const BLACKLIST_NOTICES = {
  en: 'Your industry blacklist does not apply here: every component is compared with buying it',
  fr: "La blacklist industrie ne s'applique pas ici : chaque composant est comparé à son achat",
} as const

function mountWithAnalysis(analysis: BuyVsBuildResult, locale: 'en' | 'fr') {
  useScannerStore().buyVsBuildResult = analysis
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(BuyVsBuildTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BuyVsBuildTab blacklist notice', () => {
  it.each(['en', 'fr'] as const)('tells that the industry blacklist does not apply to the analysis (%s)', (locale) => {
    const wrapper = mountWithAnalysis(ANALYSIS_WITHOUT_COMPONENT, locale)

    expect(wrapper.text()).toContain(BLACKLIST_NOTICES[locale])
  })
})

import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import type { PivotAnalysisResult } from '@/stores/industry/types'

const SOURCE_PRODUCT_TYPE_ID = 11379
const COMPONENT_TWO_TYPE_ID = 11399

function candidate(typeId: number, typeName: string, figures: {
  marginPercent: number | null
  coveragePercent: number | null
  estimatedProfit: number | null
  score: number | null
  dailyVolume: number
}) {
  const isCostUnknown = figures.marginPercent == null
  return {
    typeId,
    typeName,
    groupName: 'Cruiser',
    profitPerUnit: figures.estimatedProfit == null ? null : figures.estimatedProfit / 3,
    missingComponents: [],
    additionalCost: figures.coveragePercent == null ? null : 0,
    unknownReason: isCostUnknown ? 'missing_material_price' : null,
    missingPriceTypeIds: isCostUnknown ? [16273] : [],
    ...figures,
  }
}

const CANDIDATES_IN_SERVER_ORDER = [
  candidate(22480, 'Candidate Z', { marginPercent: null, coveragePercent: null, estimatedProfit: null, score: null, dailyVolume: 49 }),
  candidate(22462, 'Candidate W', { marginPercent: 412.5, coveragePercent: 10, estimatedProfit: 1540, score: 6100.2, dailyVolume: 9 }),
  candidate(22490, 'Candidate V', { marginPercent: -12.4, coveragePercent: 75, estimatedProfit: -310, score: -820.5, dailyVolume: 19 }),
  candidate(22470, 'Candidate Y', { marginPercent: null, coveragePercent: 50, estimatedProfit: null, score: null, dailyVolume: 999 }),
  candidate(22456, 'Candidate X', { marginPercent: 261.09, coveragePercent: 25, estimatedProfit: 2013, score: 13054.47, dailyVolume: 99 }),
]

const PIVOT_RESULT = {
  typeId: SOURCE_PRODUCT_TYPE_ID,
  sourceProduct: {
    typeId: SOURCE_PRODUCT_TYPE_ID,
    typeName: 'Source Ship',
    groupName: 'Destroyer',
    marginPercent: 823.38,
    sellPrice: 4000,
    dailyVolume: 25,
    keyComponents: [{ typeId: COMPONENT_TWO_TYPE_ID, typeName: 'Component Two', inStock: 1 }],
    unknownReason: null,
    missingPriceTypeIds: [],
  },
  matrix: [],
  candidates: CANDIDATES_IN_SERVER_ORDER,
  matrixProductIds: [SOURCE_PRODUCT_TYPE_ID, ...CANDIDATES_IN_SERVER_ORDER.map(c => c.typeId)],
} as unknown as PivotAnalysisResult

function mountPivotAdvisor(): VueWrapper {
  useScannerStore().pivotResult = PIVOT_RESULT
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(PivotAdvisorTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

function displayedCandidateNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('div.grid > div.relative').map(card => card.find('span.font-bold').text())
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('PivotAdvisorTab candidate sort', () => {
  it.each([
    ['score', ['Candidate X', 'Candidate W', 'Candidate V', 'Candidate Z', 'Candidate Y']],
    ['margin', ['Candidate W', 'Candidate X', 'Candidate V', 'Candidate Z', 'Candidate Y']],
    ['coverage', ['Candidate V', 'Candidate Y', 'Candidate X', 'Candidate W', 'Candidate Z']],
    ['volume', ['Candidate Y', 'Candidate X', 'Candidate Z', 'Candidate V', 'Candidate W']],
    ['profit', ['Candidate X', 'Candidate W', 'Candidate V', 'Candidate Z', 'Candidate Y']],
  ])('sorts by %s in descending order with unknown values last, after negative ones, in server order', async (sortKey, expectedNames) => {
    const wrapper = mountPivotAdvisor()

    const sortSelect = wrapper.findAll('select').find(select => select.find('option[value="margin"]').exists())
    expect(sortSelect, 'sort select').toBeDefined()
    await sortSelect!.setValue(sortKey)

    expect(displayedCandidateNames(wrapper)).toEqual(expectedNames)
  })
})

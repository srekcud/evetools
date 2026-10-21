import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import type { PivotAnalysisResult } from '@/stores/industry/types'

const SOURCE_PRODUCT_TYPE_ID = 11379
const COMPONENT_TWO_TYPE_ID = 11399
const CANDIDATE_X_TYPE_ID = 22456
const CANDIDATE_W_TYPE_ID = 22462
const CANDIDATE_V_TYPE_ID = 22490

const MISSING_COST_INDEX_LABEL = { en: 'System cost index unknown', fr: 'Indice de coût du système inconnu' }
const MISSING_ADJUSTED_PRICE_LABEL = { en: 'ESI adjusted price unknown', fr: 'Prix ajusté ESI inconnu' }

const PIVOT_RESULT_WITH_UNKNOWN_JOB_INSTALL_COSTS = {
  typeId: SOURCE_PRODUCT_TYPE_ID,
  sourceProduct: {
    typeId: SOURCE_PRODUCT_TYPE_ID,
    typeName: 'Source Ship',
    groupName: 'Destroyer',
    marginPercent: null,
    sellPrice: 4000,
    dailyVolume: 25,
    keyComponents: [{ typeId: COMPONENT_TWO_TYPE_ID, typeName: 'Component Two', inStock: 1 }],
    unknownReason: 'missing_cost_index',
    missingPriceTypeIds: [],
  },
  matrix: [
    {
      typeId: COMPONENT_TWO_TYPE_ID,
      typeName: 'Component Two',
      inStock: 1,
      candidates: {
        [SOURCE_PRODUCT_TYPE_ID]: { needed: 6, status: 'partial' },
        [CANDIDATE_W_TYPE_ID]: { needed: 3, status: 'partial' },
        [CANDIDATE_X_TYPE_ID]: { needed: 4, status: 'partial' },
        [CANDIDATE_V_TYPE_ID]: { needed: 4, status: 'partial' },
      },
    },
  ],
  candidates: [
    {
      typeId: CANDIDATE_W_TYPE_ID,
      typeName: 'Candidate W',
      groupName: 'Frigate',
      marginPercent: 420.01,
      profitPerUnit: 899.45,
      dailyVolume: 9,
      coveragePercent: 33.33,
      missingComponents: [{ typeId: COMPONENT_TWO_TYPE_ID, typeName: 'Component Two', quantity: 2, cost: 100 }],
      additionalCost: 100,
      estimatedProfit: 2698.35,
      score: 14000.31,
      unknownReason: null,
      missingPriceTypeIds: [],
    },
    {
      typeId: CANDIDATE_X_TYPE_ID,
      typeName: 'Candidate X',
      groupName: 'Cruiser',
      marginPercent: null,
      profitPerUnit: null,
      dailyVolume: 99,
      coveragePercent: 25,
      missingComponents: [{ typeId: COMPONENT_TWO_TYPE_ID, typeName: 'Component Two', quantity: 3, cost: 150 }],
      additionalCost: 150,
      estimatedProfit: null,
      score: null,
      unknownReason: 'missing_cost_index',
      missingPriceTypeIds: [],
    },
    {
      typeId: CANDIDATE_V_TYPE_ID,
      typeName: 'Candidate V',
      groupName: 'Hauler',
      marginPercent: null,
      profitPerUnit: null,
      dailyVolume: 999,
      coveragePercent: 25,
      missingComponents: [{ typeId: COMPONENT_TWO_TYPE_ID, typeName: 'Component Two', quantity: 3, cost: 150 }],
      additionalCost: 150,
      estimatedProfit: null,
      score: null,
      unknownReason: 'missing_adjusted_price',
      missingPriceTypeIds: [],
    },
  ],
  matrixProductIds: [SOURCE_PRODUCT_TYPE_ID, CANDIDATE_W_TYPE_ID, CANDIDATE_X_TYPE_ID, CANDIDATE_V_TYPE_ID],
} as unknown as PivotAnalysisResult

function mountPivotAdvisorIn(locale: 'en' | 'fr'): VueWrapper {
  useScannerStore().pivotResult = PIVOT_RESULT_WITH_UNKNOWN_JOB_INSTALL_COSTS
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(PivotAdvisorTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

function candidateCard(wrapper: VueWrapper, candidateName: string): DOMWrapper<Element> {
  const card = wrapper.findAll('div.relative').find(candidate => candidate.text().includes(candidateName))
  expect(card, `candidate card ${candidateName}`).toBeDefined()
  return card!
}

function sourceProductCard(wrapper: VueWrapper): DOMWrapper<Element> {
  const card = wrapper.findAll('div.rounded-xl').find(candidate => candidate.find('h2').exists())
  expect(card, 'source product card').toBeDefined()
  return card!
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('PivotAdvisorTab with an unknown job install cost', () => {
  it.each(['en', 'fr'] as const)('shows the missing cost index reason of a candidate with its %s label', (locale) => {
    const card = candidateCard(mountPivotAdvisorIn(locale), 'Candidate X')

    expect(card.text()).toContain(MISSING_COST_INDEX_LABEL[locale])
    expect(card.text()).not.toContain(MISSING_ADJUSTED_PRICE_LABEL[locale])
    expect(card.text()).not.toContain('missing_cost_index')
  })

  it.each(['en', 'fr'] as const)('shows the missing adjusted price reason of a candidate with its %s label', (locale) => {
    const card = candidateCard(mountPivotAdvisorIn(locale), 'Candidate V')

    expect(card.text()).toContain(MISSING_ADJUSTED_PRICE_LABEL[locale])
    expect(card.text()).not.toContain(MISSING_COST_INDEX_LABEL[locale])
    expect(card.text()).not.toContain('missing_adjusted_price')
  })

  it.each(['en', 'fr'] as const)('shows the missing cost index reason of the source product with its %s label', (locale) => {
    const card = sourceProductCard(mountPivotAdvisorIn(locale))

    expect(card.text()).toContain(MISSING_COST_INDEX_LABEL[locale])
    expect(card.text()).not.toContain('missing_cost_index')
  })

  it('shows a figured candidate without any unknown job install cost reason', () => {
    const card = candidateCard(mountPivotAdvisorIn('en'), 'Candidate W')

    expect(card.text()).toContain('+420.0%')
    expect(card.text()).not.toContain(MISSING_COST_INDEX_LABEL.en)
    expect(card.text()).not.toContain(MISSING_ADJUSTED_PRICE_LABEL.en)
  })
})

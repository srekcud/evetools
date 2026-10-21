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
const COMPONENT_FOUR_TYPE_ID = 11400
const CANDIDATE_X_TYPE_ID = 22456
const CANDIDATE_Z_TYPE_ID = 22480

const PIVOT_RESULT_WITH_UNPRICED_MATERIAL = {
  typeId: SOURCE_PRODUCT_TYPE_ID,
  sourceProduct: {
    typeId: SOURCE_PRODUCT_TYPE_ID,
    typeName: 'Source Ship',
    groupName: 'Destroyer',
    marginPercent: null,
    sellPrice: 4000,
    dailyVolume: 25,
    keyComponents: [
      { typeId: COMPONENT_TWO_TYPE_ID, typeName: 'Component Two', inStock: 1 },
      { typeId: COMPONENT_FOUR_TYPE_ID, typeName: 'Component Four', inStock: 2 },
    ],
    unknownReason: 'missing_material_price',
    missingPriceTypeIds: [COMPONENT_FOUR_TYPE_ID],
  },
  matrix: [
    {
      typeId: COMPONENT_TWO_TYPE_ID,
      typeName: 'Component Two',
      inStock: 1,
      candidates: {
        [SOURCE_PRODUCT_TYPE_ID]: { needed: 6, status: 'partial' },
        [CANDIDATE_X_TYPE_ID]: { needed: 4, status: 'partial' },
        [CANDIDATE_Z_TYPE_ID]: { needed: 4, status: 'partial' },
      },
    },
  ],
  candidates: [
    {
      typeId: CANDIDATE_X_TYPE_ID,
      typeName: 'Candidate X',
      groupName: 'Cruiser',
      marginPercent: 261.09,
      profitPerUnit: 671,
      dailyVolume: 99,
      coveragePercent: 25,
      missingComponents: [{ typeId: COMPONENT_TWO_TYPE_ID, typeName: 'Component Two', quantity: 3, cost: 150 }],
      additionalCost: 150,
      estimatedProfit: 2013,
      score: 13054.47,
      unknownReason: null,
      missingPriceTypeIds: [],
    },
    {
      typeId: CANDIDATE_Z_TYPE_ID,
      typeName: 'Candidate Z',
      groupName: 'Industrial',
      marginPercent: null,
      profitPerUnit: null,
      dailyVolume: 49,
      coveragePercent: null,
      missingComponents: [
        { typeId: COMPONENT_TWO_TYPE_ID, typeName: 'Component Two', quantity: 3, cost: 150 },
        { typeId: COMPONENT_FOUR_TYPE_ID, typeName: 'Component Four', quantity: 3, cost: null },
      ],
      additionalCost: null,
      estimatedProfit: null,
      score: null,
      unknownReason: 'missing_material_price',
      missingPriceTypeIds: [COMPONENT_FOUR_TYPE_ID],
    },
  ],
  matrixProductIds: [SOURCE_PRODUCT_TYPE_ID, CANDIDATE_X_TYPE_ID, CANDIDATE_Z_TYPE_ID],
} as unknown as PivotAnalysisResult

const pivotLabels = en.industry.scanner.pivotAdvisor
const unknownLabels = en.industry.inventionUnknown

function mountPivotAdvisorShowing(result: PivotAnalysisResult): VueWrapper {
  useScannerStore().pivotResult = result
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
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

function valueNextToLabel(card: DOMWrapper<Element>, label: string): string {
  const labelElement = card.findAll('span, div').find(candidate => candidate.element.childElementCount === 0 && candidate.text() === label)
  expect(labelElement, `label ${label}`).toBeDefined()
  return labelElement!.element.nextElementSibling?.textContent?.trim() ?? ''
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('PivotAdvisorTab with an unpriced material', () => {
  it('shows the margin, coverage, additional cost and estimated profit of a candidate with an unknown cost as unknown, never as zero', () => {
    const card = candidateCard(mountPivotAdvisorShowing(PIVOT_RESULT_WITH_UNPRICED_MATERIAL), 'Candidate Z')

    expect(valueNextToLabel(card, pivotLabels.margin)).toBe(unknownLabels.label)
    expect(valueNextToLabel(card, pivotLabels.componentCoverage)).toBe(unknownLabels.label)
    expect(valueNextToLabel(card, pivotLabels.additionalCost)).toBe(unknownLabels.label)
    expect(valueNextToLabel(card, pivotLabels.estimatedProfit)).toBe(unknownLabels.label)
    expect(card.text()).not.toMatch(/N\/A|---|null|NaN|(^|[^\d.])0(\.0)?%/)
  })

  it('shows the missing material price reason of a candidate with an unknown cost with the scanner label', () => {
    const card = candidateCard(mountPivotAdvisorShowing(PIVOT_RESULT_WITH_UNPRICED_MATERIAL), 'Candidate Z')

    expect(card.html()).toContain(unknownLabels.missing_material_price)
  })

  it('shows a priced candidate with its figures and without the missing material price reason', () => {
    const card = candidateCard(mountPivotAdvisorShowing(PIVOT_RESULT_WITH_UNPRICED_MATERIAL), 'Candidate X')

    expect(valueNextToLabel(card, pivotLabels.margin)).toBe('+261.1%')
    expect(valueNextToLabel(card, pivotLabels.componentCoverage)).toBe('25%')
    expect(card.html()).not.toContain(unknownLabels.missing_material_price)
  })

  it('shows the margin of a source product with an unpriced material as unknown with the missing material price reason', () => {
    const card = sourceProductCard(mountPivotAdvisorShowing(PIVOT_RESULT_WITH_UNPRICED_MATERIAL))

    expect(valueNextToLabel(card, pivotLabels.marginPercent)).toBe(unknownLabels.label)
    expect(card.html()).toContain(unknownLabels.missing_material_price)
  })
})

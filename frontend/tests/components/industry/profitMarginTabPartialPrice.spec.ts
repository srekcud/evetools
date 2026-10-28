import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { ProfitMarginInventionOption, ProfitMarginResult } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SABRE_TYPE_ID = 22456
const TRITANIUM_TYPE_ID = 34
const PYERITE_TYPE_ID = 35
const MEXALLON_TYPE_ID = 36
const DATACORE_MECHANICAL_ENGINEERING_TYPE_ID = 20424

const TRITANIUM_COVERAGE_PERCENT = 30
const MEXALLON_COVERAGE_PERCENT = 62
const DATACORE_COVERAGE_PERCENT = 45

const TRITANIUM_TOTAL_PRICE = 72_000
const MEXALLON_TOTAL_PRICE = 72_750
const PYERITE_TOTAL_PRICE = 29_610

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  inventionCost: 26_000,
  totalProductionCost: 205_360,
  bestMargin: 10.23,
}

const SABRE_WITH_PARTIAL_PRICES: ProfitMarginResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 4,
  outputQuantity: 40,
  outputPerRun: 10,
  materialCost: 174_360,
  materials: [
    { typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 12_000, unitPrice: 6, totalPrice: TRITANIUM_TOTAL_PRICE },
    { typeId: PYERITE_TYPE_ID, typeName: 'Pyerite', quantity: 3_000, unitPrice: 9.87, totalPrice: PYERITE_TOTAL_PRICE },
    { typeId: MEXALLON_TYPE_ID, typeName: 'Mexallon', quantity: 1_500, unitPrice: 48.5, totalPrice: MEXALLON_TOTAL_PRICE },
  ],
  jobInstallCost: 5_000,
  jobInstallSteps: [],
  inventionCost: 26_000,
  totalCost: 205_360,
  costPerUnit: 5_134,
  invention: {
    baseProbability: 0.34,
    unknownReason: null,
    datacores: ['Datacore - Mechanical Engineering'],
    selectedDecryptorTypeId: null,
    selectedDecryptorName: 'No Decryptor',
    options: [NO_DECRYPTOR_OPTION],
  },
  sellPrices: {
    jitaSell: null,
    structureSell: 6_000,
    structureBuy: 5_800,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.05,
  salesTaxRate: 0.02,
  margins: {
    structureSell: { revenue: 240_000, fees: 16_800, profit: 17_840, margin: 8.69 },
    structureBuy: { revenue: 232_000, fees: 4_640, profit: 21_000, margin: 10.23 },
  },
  dailyVolume: null,
  missingData: [],
  warnings: [
    { reason: 'partial_market_depth', typeId: TRITANIUM_TYPE_ID, coveragePercent: TRITANIUM_COVERAGE_PERCENT },
    { reason: 'partial_market_depth', typeId: MEXALLON_TYPE_ID, coveragePercent: MEXALLON_COVERAGE_PERCENT },
    { reason: 'partial_market_depth', typeId: DATACORE_MECHANICAL_ENGINEERING_TYPE_ID, coveragePercent: DATACORE_COVERAGE_PERCENT },
  ],
}

const SABRE_WITHOUT_PARTIAL_PRICE: ProfitMarginResult = {
  ...SABRE_WITH_PARTIAL_PRICES,
  warnings: [],
}

function mountWithAnalysis(analysis: ProfitMarginResult, locale: 'en' | 'fr') {
  vi.mocked(apiRequest).mockResolvedValue(null)
  useProjectsStore().marginResult = analysis
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  return { wrapper, t: i18n.global.t }
}

function materialRow(wrapper: VueWrapper, typeName: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('td').length === 4 && candidate.find('td').text() === typeName)
  expect(row, `material row ${typeName}`).toBeDefined()
  return row!
}

function totalPriceCell(wrapper: VueWrapper, typeName: string): string {
  return materialRow(wrapper, typeName).findAll('td')[3]!.text()
}

function costSummaryAmount(wrapper: VueWrapper, label: string): string {
  const cell = wrapper.findAll('div.grid-cols-4 > div').find(candidate => candidate.find('div').text() === label)
  expect(cell, `cost summary ${label}`).toBeDefined()
  return cell!.findAll('div')[1]!.text()
}

function summaryCardAmount(wrapper: VueWrapper, label: string): string {
  const card = wrapper.findAll('div.eve-card.p-4').find(candidate => candidate.find('p').exists() && candidate.find('p').text() === label)
  expect(card, `summary card ${label}`).toBeDefined()
  return card!.find('p.font-mono').text()
}

function wholeIsk(amount: number): string {
  return amount.toLocaleString(undefined, { maximumFractionDigits: 0 })
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProfitMarginTab with partial prices', () => {
  it.each([
    ['en'],
    ['fr'],
  ] as const)('shows the amount of a partially priced material with its order book coverage next to it (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_PRICES, locale)

    const tritaniumTotal = totalPriceCell(wrapper, 'Tritanium')
    expect(tritaniumTotal).toContain(wholeIsk(TRITANIUM_TOTAL_PRICE))
    expect(tritaniumTotal).toContain(t('industry.partialPrice.marker', { percent: TRITANIUM_COVERAGE_PERCENT }))
    const mexallonTotal = totalPriceCell(wrapper, 'Mexallon')
    expect(mexallonTotal).toContain(wholeIsk(MEXALLON_TOTAL_PRICE))
    expect(mexallonTotal).toContain(t('industry.partialPrice.marker', { percent: MEXALLON_COVERAGE_PERCENT }))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('leaves a fully priced material without coverage marker (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_PRICES, locale)

    expect(totalPriceCell(wrapper, 'Pyerite')).toBe(wholeIsk(PYERITE_TOTAL_PRICE))
    expect(materialRow(wrapper, 'Pyerite').text()).not.toContain(t('industry.partialPrice.marker', { percent: '' }).trim())
  })

  it.each([
    ['en', 'Unknown', '174.36 K', '205.36 K', '+10.2%'],
    ['fr', 'Inconnu', '174,36 K', '205,36 K', '+10.2%'],
  ] as const)('keeps the material cost, the total cost and the margin figured, never unknown (%s)', (locale, unknownLabel, materialCost, totalCost, bestMargin) => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_PRICES, locale)

    expect(materialRow(wrapper, 'Tritanium').text()).not.toContain(unknownLabel)
    expect(materialRow(wrapper, 'Mexallon').text()).not.toContain(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.costEstimation.materials'))).toBe(materialCost)
    expect(costSummaryAmount(wrapper, t('industry.margins.totalProductionCost'))).toContain(totalCost)
    expect(summaryCardAmount(wrapper, t('industry.margins.totalCost'))).toBe(totalCost)
    expect(summaryCardAmount(wrapper, t('industry.margins.margin'))).toBe(bestMargin)
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('lists every partially priced type with its coverage in a notice, by material name (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_PRICES, locale)

    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeTitle'))
    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: 'Tritanium', percent: TRITANIUM_COVERAGE_PERCENT }))
    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: 'Mexallon', percent: MEXALLON_COVERAGE_PERCENT }))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('names a partially priced type missing from the materials by its typeId in the notice (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_PRICES, locale)

    const datacoreName = t('common.unknownTypeName', { typeId: DATACORE_MECHANICAL_ENGINEERING_TYPE_ID })
    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: datacoreName, percent: DATACORE_COVERAGE_PERCENT }))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('shows neither notice nor coverage marker without partial price (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITHOUT_PARTIAL_PRICE, locale)

    expect(wrapper.text()).not.toContain(t('industry.partialPrice.noticeTitle'))
    expect(wrapper.text()).not.toContain(t('industry.partialPrice.marker', { percent: '' }).trim())
    expect(totalPriceCell(wrapper, 'Tritanium')).toBe(wholeIsk(TRITANIUM_TOTAL_PRICE))
  })
})

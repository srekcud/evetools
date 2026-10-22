import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BuyVsBuildComponent, BuyVsBuildResult } from '@/stores/industry/types'

const SABRE_TYPE_ID = 22456
const PLASMA_THRUSTER_TYPE_ID = 11530
const MEXALLON_TYPE_ID = 36

const PLASMA_THRUSTER_WITHOUT_ANY_PRICE: BuyVsBuildComponent = {
  typeId: PLASMA_THRUSTER_TYPE_ID,
  typeName: 'Plasma Thruster',
  quantity: 10,
  stage: 'T1 Component',
  buildCost: null,
  buildMaterials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 100, unitPrice: 5, totalPrice: 500 },
    { typeId: MEXALLON_TYPE_ID, typeName: 'Mexallon', quantity: 20, unitPrice: null, totalPrice: null },
  ],
  buildJobInstallCost: 200,
  buyCostJita: null,
  buyCostStructure: null,
  verdict: 'unknown',
  savings: null,
  savingsPercent: null,
  meUsed: 10,
  runs: 1,
  missingData: [
    { reason: 'market_price', typeId: MEXALLON_TYPE_ID },
    { reason: 'market_price', typeId: PLASMA_THRUSTER_TYPE_ID },
  ],
}

const PLASMA_THRUSTER_WITHOUT_BUY_PRICE: BuyVsBuildComponent = {
  ...PLASMA_THRUSTER_WITHOUT_ANY_PRICE,
  buildCost: 1700,
  buildMaterials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 100, unitPrice: 5, totalPrice: 500 },
    { typeId: MEXALLON_TYPE_ID, typeName: 'Mexallon', quantity: 20, unitPrice: 50, totalPrice: 1000 },
  ],
  verdict: 'build',
  missingData: [{ reason: 'market_price', typeId: PLASMA_THRUSTER_TYPE_ID }],
}

const ANALYSIS_WITH_AN_UNKNOWN_VERDICT: BuyVsBuildResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 2,
  totalProductionCost: null,
  sellPrice: 50_000,
  marginPercent: null,
  components: [PLASMA_THRUSTER_WITHOUT_ANY_PRICE],
  buildAllCost: null,
  buyAllCost: null,
  optimalMixCost: null,
  buildTypeIds: [],
  buyTypeIds: [],
  missingData: [
    { reason: 'market_price', typeId: MEXALLON_TYPE_ID },
    { reason: 'market_price', typeId: PLASMA_THRUSTER_TYPE_ID },
  ],
  sellStructureId: null,
}

const ANALYSIS_WITH_AN_UNKNOWN_BUY_COST: BuyVsBuildResult = {
  ...ANALYSIS_WITH_AN_UNKNOWN_VERDICT,
  totalProductionCost: 14_600,
  marginPercent: 533.56,
  components: [PLASMA_THRUSTER_WITHOUT_BUY_PRICE],
  buildAllCost: 1700,
  buyAllCost: null,
  optimalMixCost: 1700,
  buildTypeIds: [PLASMA_THRUSTER_TYPE_ID],
  missingData: [{ reason: 'market_price', typeId: PLASMA_THRUSTER_TYPE_ID }],
}

const ANALYSIS_WITHOUT_SELL_PRICE: BuyVsBuildResult = {
  ...ANALYSIS_WITH_AN_UNKNOWN_BUY_COST,
  sellPrice: null,
  marginPercent: null,
  sellStructureId: null,
}

function mountWithAnalysis(analysis: BuyVsBuildResult, locale: 'en' | 'fr') {
  useScannerStore().buyVsBuildResult = analysis
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(BuyVsBuildTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

type Wrapper = ReturnType<typeof mountWithAnalysis>

function amountUnder(wrapper: Wrapper, cardSelector: string, label: string): string {
  const card = wrapper.findAll(cardSelector).find(candidate => candidate.find('p').text() === label)
  expect(card, `card ${label}`).toBeDefined()
  return card!.find('p.font-mono').text()
}

function summaryAmount(wrapper: Wrapper, label: string): string {
  const card = wrapper.findAll('div.text-center').find(candidate => candidate.find('p').text() === label)
  expect(card, `summary card ${label}`).toBeDefined()
  return card!.find('p.font-mono').text()
}

function componentCard(wrapper: Wrapper, typeName: string) {
  const card = wrapper.findAll('div.overflow-hidden').find(candidate => candidate.find('p.font-semibold').exists() && candidate.find('p.font-semibold').text() === typeName)
  expect(card, `component card ${typeName}`).toBeDefined()
  return card!
}

function buildCostOf(wrapper: Wrapper, typeName: string): string {
  return componentCard(wrapper, typeName).find('div.grid > div p.text-lg').text()
}

function verdictBarOf(wrapper: Wrapper, typeName: string): string {
  return componentCard(wrapper, typeName).find('div.px-4.py-2\\.5').text()
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BuyVsBuildTab with unknown prices', () => {
  it.each([
    ['en', en, 'Unknown price'],
    ['fr', fr, 'Prix inconnu'],
  ] as const)('defines a label for the unknown verdict (%s)', (_locale, messages, unknownVerdictLabel) => {
    const buyVsBuildLabels: Record<string, unknown> = messages.industry.scanner.buyVsBuild

    expect(Object.values(buyVsBuildLabels)).toContain(unknownVerdictLabel)
  })

  it.each([
    ['en', 'Unknown price'],
    ['fr', 'Prix inconnu'],
  ] as const)('shows the unknown verdict with its label (%s)', (locale, unknownVerdictLabel) => {
    const wrapper = mountWithAnalysis(ANALYSIS_WITH_AN_UNKNOWN_VERDICT, locale)

    expect(verdictBarOf(wrapper, 'Plasma Thruster')).toContain(unknownVerdictLabel)
  })

  it.each([
    ['en', en, 'Unknown'],
    ['fr', fr, 'Inconnu'],
  ] as const)('shows the unknown build cost, totals and margin as unknown, never as 0 (%s)', (locale, messages, unknownLabel) => {
    const labels = messages.industry.scanner.buyVsBuild
    const wrapper = mountWithAnalysis(ANALYSIS_WITH_AN_UNKNOWN_VERDICT, locale)

    expect(buildCostOf(wrapper, 'Plasma Thruster')).toBe(unknownLabel)
    expect(amountUnder(wrapper, 'div.text-right', labels.totalProductionCost)).toBe(unknownLabel)
    expect(amountUnder(wrapper, 'div.text-right', labels.margin)).toBe(unknownLabel)
    expect(summaryAmount(wrapper, labels.buildEverything)).toBe(unknownLabel)
    expect(summaryAmount(wrapper, labels.buyEverything)).toBe(unknownLabel)
    expect(summaryAmount(wrapper, labels.optimalMix)).toBe(unknownLabel)
  })

  it.each([
    ['en', en, 'Unknown'],
    ['fr', fr, 'Inconnu'],
  ] as const)('shows the unknown savings and buy-all cost of a component without buy price as unknown, never as 0 (%s)', (locale, messages, unknownLabel) => {
    const labels = messages.industry.scanner.buyVsBuild
    const wrapper = mountWithAnalysis(ANALYSIS_WITH_AN_UNKNOWN_BUY_COST, locale)

    const verdictBar = verdictBarOf(wrapper, 'Plasma Thruster')
    expect(verdictBar).toContain(unknownLabel)
    expect(verdictBar).not.toMatch(/\(0[.,]0%\)/)
    expect(summaryAmount(wrapper, labels.buyEverything)).toBe(unknownLabel)
  })

  it.each([
    ['en', en, 'Unknown'],
    ['fr', fr, 'Inconnu'],
  ] as const)('shows the margin of a product without sell price as unknown, never as 0 nor NaN (%s)', (locale, messages, unknownLabel) => {
    const labels = messages.industry.scanner.buyVsBuild
    const wrapper = mountWithAnalysis(ANALYSIS_WITHOUT_SELL_PRICE, locale)

    expect(amountUnder(wrapper, 'div.text-right', labels.margin)).toBe(unknownLabel)
    expect(wrapper.text()).not.toContain('NaN')
  })
})

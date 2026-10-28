import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BuyVsBuildComponent, BuyVsBuildResult } from '@/stores/industry/types'

const SABRE_TYPE_ID = 22456
const MAGPULSE_THRUSTER_TYPE_ID = 11533
const SUSTAINED_SHIELD_EMITTER_TYPE_ID = 11558
const TITANIUM_CARBIDE_TYPE_ID = 16671
const TRITANIUM_TYPE_ID = 34
const PYERITE_TYPE_ID = 35
const MORPHITE_TYPE_ID = 11399

const MAGPULSE_THRUSTER_JITA_COVERAGE_PERCENT = 45
const SUSTAINED_SHIELD_EMITTER_JITA_COVERAGE_PERCENT = 70
const TRITANIUM_COVERAGE_PERCENT = 55
const MORPHITE_COVERAGE_PERCENT = 20

const MAGPULSE_THRUSTER_BOUGHT_AT_A_PARTIAL_JITA_PRICE: BuyVsBuildComponent = {
  typeId: MAGPULSE_THRUSTER_TYPE_ID,
  typeName: 'Magpulse Thruster',
  quantity: 10,
  stage: 'T2 Component',
  buildCost: 520_000,
  buildMaterials: [
    { typeId: PYERITE_TYPE_ID, typeName: 'Pyerite', quantity: 2_000, unitPrice: 10, totalPrice: 20_000 },
  ],
  buildJobInstallCost: 3_000,
  buyCostJita: 450_000,
  buyCostStructure: null,
  buyCostJitaCoveragePercent: MAGPULSE_THRUSTER_JITA_COVERAGE_PERCENT,
  verdict: 'buy',
  savings: 70_000,
  savingsPercent: 13.5,
  meUsed: 10,
  runs: 1,
  missingData: [],
  warnings: [{ reason: 'partial_market_depth', typeId: MAGPULSE_THRUSTER_TYPE_ID, coveragePercent: MAGPULSE_THRUSTER_JITA_COVERAGE_PERCENT }],
}

const SUSTAINED_SHIELD_EMITTER_BOUGHT_IN_STRUCTURE: BuyVsBuildComponent = {
  typeId: SUSTAINED_SHIELD_EMITTER_TYPE_ID,
  typeName: 'Sustained Shield Emitter',
  quantity: 10,
  stage: 'T2 Component',
  buildCost: 900_000,
  buildMaterials: [
    { typeId: PYERITE_TYPE_ID, typeName: 'Pyerite', quantity: 3_500, unitPrice: 10, totalPrice: 35_000 },
  ],
  buildJobInstallCost: 4_200,
  buyCostJita: 820_000,
  buyCostStructure: 780_000,
  buyCostJitaCoveragePercent: SUSTAINED_SHIELD_EMITTER_JITA_COVERAGE_PERCENT,
  verdict: 'buy',
  savings: 120_000,
  savingsPercent: 13.3,
  meUsed: 10,
  runs: 1,
  missingData: [],
  warnings: [],
}

const TITANIUM_CARBIDE_BUILT_FROM_A_PARTIAL_MATERIAL: BuyVsBuildComponent = {
  typeId: TITANIUM_CARBIDE_TYPE_ID,
  typeName: 'Titanium Carbide',
  quantity: 2_000,
  stage: 'Reaction',
  buildCost: 180_000,
  buildMaterials: [
    { typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 20_000, unitPrice: 5.5, totalPrice: 110_000 },
  ],
  buildJobInstallCost: 1_800,
  buyCostJita: 240_000,
  buyCostStructure: null,
  buyCostJitaCoveragePercent: null,
  verdict: 'build',
  savings: 60_000,
  savingsPercent: 25,
  meUsed: 0,
  runs: 20,
  missingData: [],
  warnings: [{ reason: 'partial_market_depth', typeId: TRITANIUM_TYPE_ID, coveragePercent: TRITANIUM_COVERAGE_PERCENT }],
}

const ANALYSIS_WITH_PARTIAL_PRICES: BuyVsBuildResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 2,
  totalProductionCost: 1_650_000,
  sellPrice: 2_100_000,
  marginPercent: 27.27,
  components: [
    MAGPULSE_THRUSTER_BOUGHT_AT_A_PARTIAL_JITA_PRICE,
    SUSTAINED_SHIELD_EMITTER_BOUGHT_IN_STRUCTURE,
    TITANIUM_CARBIDE_BUILT_FROM_A_PARTIAL_MATERIAL,
  ],
  buildAllCost: 1_600_000,
  buyAllCost: 1_470_000,
  optimalMixCost: 1_410_000,
  buildTypeIds: [TITANIUM_CARBIDE_TYPE_ID],
  buyTypeIds: [MAGPULSE_THRUSTER_TYPE_ID, SUSTAINED_SHIELD_EMITTER_TYPE_ID],
  missingData: [],
  warnings: [
    { reason: 'partial_market_depth', typeId: MAGPULSE_THRUSTER_TYPE_ID, coveragePercent: MAGPULSE_THRUSTER_JITA_COVERAGE_PERCENT },
    { reason: 'partial_market_depth', typeId: TRITANIUM_TYPE_ID, coveragePercent: TRITANIUM_COVERAGE_PERCENT },
    { reason: 'partial_market_depth', typeId: MORPHITE_TYPE_ID, coveragePercent: MORPHITE_COVERAGE_PERCENT },
  ],
  sellStructureId: null,
}

const ANALYSIS_WITHOUT_PARTIAL_PRICE: BuyVsBuildResult = {
  ...ANALYSIS_WITH_PARTIAL_PRICES,
  components: [
    { ...MAGPULSE_THRUSTER_BOUGHT_AT_A_PARTIAL_JITA_PRICE, buyCostJitaCoveragePercent: null, warnings: [] },
    { ...SUSTAINED_SHIELD_EMITTER_BOUGHT_IN_STRUCTURE, buyCostJitaCoveragePercent: null },
    { ...TITANIUM_CARBIDE_BUILT_FROM_A_PARTIAL_MATERIAL, warnings: [] },
  ],
  warnings: [],
}

function mountWithAnalysis(analysis: BuyVsBuildResult, locale: 'en' | 'fr') {
  useScannerStore().buyVsBuildResult = analysis
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(BuyVsBuildTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  return { wrapper, t: i18n.global.t }
}

type Wrapper = ReturnType<typeof mountWithAnalysis>['wrapper']

function componentCard(wrapper: Wrapper, typeName: string) {
  const card = wrapper.findAll('div.overflow-hidden').find(candidate => candidate.find('p.font-semibold').exists() && candidate.find('p.font-semibold').text() === typeName)
  expect(card, `component card ${typeName}`).toBeDefined()
  return card!
}

function priceLineOf(wrapper: Wrapper, typeName: string, label: string): string {
  const line = componentCard(wrapper, typeName).findAll('div.flex.justify-between').find(candidate => candidate.find('span').text() === label)
  expect(line, `${label} line of ${typeName}`).toBeDefined()
  return line!.text()
}

function verdictBarOf(wrapper: Wrapper, typeName: string): string {
  return componentCard(wrapper, typeName).find('div.px-4.py-2\\.5').text()
}

function summaryCard(wrapper: Wrapper, label: string) {
  const card = wrapper.findAll('div.text-center').find(candidate => candidate.find('p').text() === label)
  expect(card, `summary card ${label}`).toBeDefined()
  return card!
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BuyVsBuildTab with partial prices', () => {
  it.each([
    ['en', en, '45.00 K /u'],
    ['fr', fr, '45,00 K /u'],
  ] as const)('marks the Jita price of a component bought at a partial Jita price with its coverage (%s)', (locale, messages, jitaUnitPrice) => {
    const { wrapper, t } = mountWithAnalysis(ANALYSIS_WITH_PARTIAL_PRICES, locale)

    const jitaLine = priceLineOf(wrapper, 'Magpulse Thruster', messages.industry.scanner.buyVsBuild.jitaSell)
    expect(jitaLine).toContain(jitaUnitPrice)
    expect(jitaLine).toContain(t('industry.partialPrice.marker', { percent: MAGPULSE_THRUSTER_JITA_COVERAGE_PERCENT }))
  })

  it.each([
    ['en', en, '82.00 K /u', '78.00 K /u'],
    ['fr', fr, '82,00 K /u', '78,00 K /u'],
  ] as const)('marks the partial Jita price even when the structure price is the one retained, and leaves the structure price unmarked (%s)', (locale, messages, jitaUnitPrice, structureUnitPrice) => {
    const labels = messages.industry.scanner.buyVsBuild
    const { wrapper, t } = mountWithAnalysis(ANALYSIS_WITH_PARTIAL_PRICES, locale)

    const jitaLine = priceLineOf(wrapper, 'Sustained Shield Emitter', labels.jitaSell)
    expect(jitaLine).toContain(jitaUnitPrice)
    expect(jitaLine).toContain(t('industry.partialPrice.marker', { percent: SUSTAINED_SHIELD_EMITTER_JITA_COVERAGE_PERCENT }))
    const structureLine = priceLineOf(wrapper, 'Sustained Shield Emitter', labels.structureSell)
    expect(structureLine).toContain(structureUnitPrice)
    expect(structureLine).not.toContain(t('industry.partialPrice.marker', { percent: '' }).trim())
  })

  it.each([
    ['en', en],
    ['fr', fr],
  ] as const)('leaves the Jita price of a component without Jita coverage unmarked (%s)', (locale, messages) => {
    const { wrapper, t } = mountWithAnalysis(ANALYSIS_WITH_PARTIAL_PRICES, locale)

    const jitaLine = priceLineOf(wrapper, 'Titanium Carbide', messages.industry.scanner.buyVsBuild.jitaSell)
    expect(jitaLine).toContain('120 /u')
    expect(jitaLine).not.toContain(t('industry.partialPrice.marker', { percent: '' }).trim())
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('lists the partially priced types of the analysis with their coverage in a notice, by name (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(ANALYSIS_WITH_PARTIAL_PRICES, locale)

    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeTitle'))
    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: 'Magpulse Thruster', percent: MAGPULSE_THRUSTER_JITA_COVERAGE_PERCENT }))
    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: 'Tritanium', percent: TRITANIUM_COVERAGE_PERCENT }))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('names a partially priced type found in no component by its typeId in the notice (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(ANALYSIS_WITH_PARTIAL_PRICES, locale)

    const morphiteName = t('common.unknownTypeName', { typeId: MORPHITE_TYPE_ID })
    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: morphiteName, percent: MORPHITE_COVERAGE_PERCENT }))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('leaves out of the notice a partial Jita price the analysis did not retain (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(ANALYSIS_WITH_PARTIAL_PRICES, locale)

    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeTitle'))
    expect(wrapper.text()).not.toContain(t('industry.partialPrice.noticeLine', { type: 'Sustained Shield Emitter', percent: SUSTAINED_SHIELD_EMITTER_JITA_COVERAGE_PERCENT }))
  })

  it.each([
    ['en', en, '1.60 M', '1.47 M'],
    ['fr', fr, '1,60 M', '1,47 M'],
  ] as const)('shows the build-all and buy-all costs without coverage marker (%s)', (locale, messages, buildAllCost, buyAllCost) => {
    const labels = messages.industry.scanner.buyVsBuild
    const { wrapper, t } = mountWithAnalysis(ANALYSIS_WITH_PARTIAL_PRICES, locale)
    const markerSuffix = t('industry.partialPrice.marker', { percent: '' }).trim()

    const buildAllCard = summaryCard(wrapper, labels.buildEverything)
    expect(buildAllCard.find('p.font-mono').text()).toBe(buildAllCost)
    expect(buildAllCard.text()).not.toContain(markerSuffix)
    const buyAllCard = summaryCard(wrapper, labels.buyEverything)
    expect(buyAllCard.find('p.font-mono').text()).toBe(buyAllCost)
    expect(buyAllCard.text()).not.toContain(markerSuffix)
  })

  it.each([
    ['en', en, '70.00 K', '(13.5%)'],
    ['fr', fr, '70,00 K', '(13.5%)'],
  ] as const)('keeps the verdict and the savings of a partially priced component (%s)', (locale, messages, savings, savingsPercent) => {
    const { wrapper } = mountWithAnalysis(ANALYSIS_WITH_PARTIAL_PRICES, locale)

    const verdictBar = verdictBarOf(wrapper, 'Magpulse Thruster')
    expect(verdictBar).toContain(messages.industry.scanner.buyVsBuild.save)
    expect(verdictBar).toContain(savings)
    expect(verdictBar).toContain(savingsPercent)
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('shows neither notice nor coverage marker without partial price (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(ANALYSIS_WITHOUT_PARTIAL_PRICE, locale)

    expect(wrapper.text()).not.toContain(t('industry.partialPrice.noticeTitle'))
    expect(wrapper.text()).not.toContain(t('industry.partialPrice.marker', { percent: '' }).trim())
  })
})

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BuyVsBuildComponent, BuyVsBuildResult } from '@/stores/industry/types'

const HAIL_L_TYPE_ID = 12779
const RAM_AMMUNITION_TECH_TYPE_ID = 11476
const FERNITE_CARBIDE_TYPE_ID = 16673
const CERAMIC_POWDER_TYPE_ID = 16660

const FERNITE_CARBIDE_WITH_AN_UNKNOWN_INSTALL_COST: BuyVsBuildComponent = {
  typeId: FERNITE_CARBIDE_TYPE_ID,
  typeName: 'Fernite Carbide',
  quantity: 2940,
  stage: 'Reaction',
  buildCost: null,
  buildMaterials: [
    { typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 5, unitPrice: 19_500, totalPrice: 97_500 },
    { typeId: 16656, typeName: 'Fernite Alloy', quantity: 100, unitPrice: 310, totalPrice: 31_000 },
    { typeId: CERAMIC_POWDER_TYPE_ID, typeName: 'Ceramic Powder', quantity: 100, unitPrice: 260, totalPrice: 26_000 },
  ],
  buildJobInstallCost: null,
  buyCostJita: 188_160,
  buyCostStructure: null,
  verdict: 'buy',
  savings: null,
  savingsPercent: null,
  meUsed: 0,
  runs: 1,
}

const RAM_WITH_A_KNOWN_INSTALL_COST: BuyVsBuildComponent = {
  typeId: RAM_AMMUNITION_TECH_TYPE_ID,
  typeName: 'R.A.M.- Ammunition Tech',
  quantity: 1,
  stage: 'T1 Component',
  buildCost: 53_506.8,
  buildMaterials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 501, unitPrice: 5, totalPrice: 2505 },
    { typeId: 38, typeName: 'Nocxium', quantity: 33, unitPrice: 790, totalPrice: 26_070 },
  ],
  buildJobInstallCost: 2361.8,
  buyCostJita: 60_000,
  buyCostStructure: null,
  verdict: 'build',
  savings: 6493.2,
  savingsPercent: 10.82,
  meUsed: 10,
  runs: 1,
}

const ANALYSIS_WITH_AN_UNKNOWN_INSTALL_COST: BuyVsBuildResult = {
  typeId: HAIL_L_TYPE_ID,
  typeName: 'Hail L',
  isT2: true,
  runs: 1,
  totalProductionCost: null,
  sellPrice: 520,
  marginPercent: null,
  components: [RAM_WITH_A_KNOWN_INSTALL_COST, FERNITE_CARBIDE_WITH_AN_UNKNOWN_INSTALL_COST],
  buildAllCost: null,
  buyAllCost: 248_160,
  optimalMixCost: 241_666.8,
  buildTypeIds: [RAM_AMMUNITION_TECH_TYPE_ID],
  buyTypeIds: [FERNITE_CARBIDE_TYPE_ID],
  missingPriceTypeIds: [],
  unknownInstallCostReasons: ['missing_adjusted_price', 'missing_cost_index'],
  sellStructureId: null,
}

const ANALYSIS_WITH_ONLY_A_MISSING_COST_INDEX: BuyVsBuildResult = {
  ...ANALYSIS_WITH_AN_UNKNOWN_INSTALL_COST,
  unknownInstallCostReasons: ['missing_cost_index'],
}

const ANALYSIS_WITH_KNOWN_INSTALL_COSTS: BuyVsBuildResult = {
  ...ANALYSIS_WITH_AN_UNKNOWN_INSTALL_COST,
  totalProductionCost: 598_956.8,
  marginPercent: 301.53,
  components: [RAM_WITH_A_KNOWN_INSTALL_COST],
  buildAllCost: 53_506.8,
  buyAllCost: 60_000,
  optimalMixCost: 53_506.8,
  buyTypeIds: [],
  unknownInstallCostReasons: [],
}

const COST_INDEX_REASON_LABELS = { en: 'System cost index unknown', fr: 'Indice de coût du système inconnu' } as const
const ADJUSTED_PRICE_REASON_LABELS = { en: 'ESI adjusted price unknown', fr: 'Prix ajusté ESI inconnu' } as const

function mountWithAnalysis(analysis: BuyVsBuildResult, locale: 'en' | 'fr') {
  useScannerStore().buyVsBuildResult = analysis
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(BuyVsBuildTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

type Wrapper = ReturnType<typeof mountWithAnalysis>

function componentCard(wrapper: Wrapper, typeName: string) {
  const card = wrapper.findAll('div.overflow-hidden').find(candidate => candidate.find('p.font-semibold').exists() && candidate.find('p.font-semibold').text() === typeName)
  expect(card, `component card ${typeName}`).toBeDefined()
  return card!
}

function jobInstallCostOf(wrapper: Wrapper, typeName: string, jobInstallLabel: string): string {
  const row = componentCard(wrapper, typeName).findAll('div.flex').find(candidate => candidate.find('span').exists() && candidate.find('span').text() === jobInstallLabel)
  expect(row, `job install row of ${typeName}`).toBeDefined()
  return row!.find('span.font-mono').text()
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BuyVsBuildTab with an unknown job install cost', () => {
  it.each([
    ['en', en, 'Unknown', '2.36 K'],
    ['fr', fr, 'Inconnu', '2,36 K'],
  ] as const)('shows the unknown job install cost of a component as unknown, never as --- or 0 (%s)', (locale, messages, unknownLabel, ramJobInstallCost) => {
    const jobInstallLabel = messages.industry.scanner.buyVsBuild.jobInstall
    const wrapper = mountWithAnalysis(ANALYSIS_WITH_AN_UNKNOWN_INSTALL_COST, locale)

    expect(jobInstallCostOf(wrapper, 'Fernite Carbide', jobInstallLabel)).toBe(unknownLabel)
    expect(jobInstallCostOf(wrapper, 'R.A.M.- Ammunition Tech', jobInstallLabel)).toBe(ramJobInstallCost)
  })

  it.each(['en', 'fr'] as const)('lists every unknown install cost reason with its label (%s)', (locale) => {
    const wrapper = mountWithAnalysis(ANALYSIS_WITH_AN_UNKNOWN_INSTALL_COST, locale)

    expect(wrapper.text()).toContain(COST_INDEX_REASON_LABELS[locale])
    expect(wrapper.text()).toContain(ADJUSTED_PRICE_REASON_LABELS[locale])
  })

  it.each(['en', 'fr'] as const)('lists only the reasons of the analysis (%s)', (locale) => {
    const wrapper = mountWithAnalysis(ANALYSIS_WITH_ONLY_A_MISSING_COST_INDEX, locale)

    expect(wrapper.text()).toContain(COST_INDEX_REASON_LABELS[locale])
    expect(wrapper.text()).not.toContain(ADJUSTED_PRICE_REASON_LABELS[locale])
  })

  it.each(['en', 'fr'] as const)('lists no reason when every install cost is known (%s)', (locale) => {
    const wrapper = mountWithAnalysis(ANALYSIS_WITH_KNOWN_INSTALL_COSTS, locale)

    expect(wrapper.text()).not.toContain(COST_INDEX_REASON_LABELS[locale])
    expect(wrapper.text()).not.toContain(ADJUSTED_PRICE_REASON_LABELS[locale])
  })
})

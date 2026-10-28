import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { ProfitMarginInventionOption, ProfitMarginJobStep, ProfitMarginResult } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FERNITE_CARBIDE_TYPE_ID = 16673
const FERNITE_ALLOY_TYPE_ID = 16656
const HYDROGEN_FUEL_BLOCK_TYPE_ID = 4246
const HAIL_L_TYPE_ID = 12779
const RAM_AMMUNITION_TECH_TYPE_ID = 11476
const PROCESS_DECRYPTOR_TYPE_ID = 34205

const FERNITE_CARBIDE_REACTION_WITHOUT_COST_INDEX: ProfitMarginJobStep = {
  productTypeId: FERNITE_CARBIDE_TYPE_ID,
  productName: 'Fernite Carbide',
  activityType: 'reaction',
  runs: 3,
  installCost: null,
  missingData: [{ reason: 'cost_index', typeId: null }],
}

const HYDROGEN_FUEL_BLOCK_JOB_FIGURED: ProfitMarginJobStep = {
  productTypeId: HYDROGEN_FUEL_BLOCK_TYPE_ID,
  productName: 'Hydrogen Fuel Block',
  activityType: 'manufacturing',
  runs: 2,
  installCost: 1_873.47974,
  missingData: [],
}

const FERNITE_ALLOY_REACTION_WITHOUT_ADJUSTED_PRICE: ProfitMarginJobStep = {
  productTypeId: FERNITE_ALLOY_TYPE_ID,
  productName: 'Fernite Alloy',
  activityType: 'reaction',
  runs: 4,
  installCost: null,
  missingData: [{ reason: 'adjusted_price', typeId: HYDROGEN_FUEL_BLOCK_TYPE_ID }],
}

const FERNITE_CARBIDE_WITH_AN_UNKNOWN_JOB_INSTALL_COST: ProfitMarginResult = {
  typeId: FERNITE_CARBIDE_TYPE_ID,
  typeName: 'Fernite Carbide',
  isT2: false,
  runs: 3,
  outputQuantity: 30_000,
  outputPerRun: 10_000,
  materialCost: 1_564_012.71,
  materials: [
    { typeId: 16635, typeName: 'Evaporite Deposits', quantity: 294, unitPrice: 166.35, totalPrice: 48_906.9 },
    { typeId: 17889, typeName: 'Hydrogen Isotopes', quantity: 3_609, unitPrice: 178.89, totalPrice: 645_614.01 },
  ],
  jobInstallCost: null,
  jobInstallSteps: [FERNITE_CARBIDE_REACTION_WITHOUT_COST_INDEX, HYDROGEN_FUEL_BLOCK_JOB_FIGURED],
  inventionCost: 0,
  totalCost: null,
  costPerUnit: null,
  invention: null,
  sellPrices: {
    jitaSell: 61.2,
    structureSell: 59.8,
    structureBuy: 54.1,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.03,
  salesTaxRate: 0.045,
  margins: { jitaSell: null, structureSell: null, structureBuy: null, contractSell: null },
  dailyVolume: 125_000,
  missingData: [{ reason: 'cost_index', typeId: null }],
  warnings: [],
}

const FERNITE_CARBIDE_WITH_BOTH_UNKNOWN_INSTALL_COST_REASONS: ProfitMarginResult = {
  ...FERNITE_CARBIDE_WITH_AN_UNKNOWN_JOB_INSTALL_COST,
  jobInstallSteps: [FERNITE_CARBIDE_REACTION_WITHOUT_COST_INDEX, HYDROGEN_FUEL_BLOCK_JOB_FIGURED, FERNITE_ALLOY_REACTION_WITHOUT_ADJUSTED_PRICE],
  missingData: [
    { reason: 'cost_index', typeId: null },
    { reason: 'adjusted_price', typeId: HYDROGEN_FUEL_BLOCK_TYPE_ID },
  ],
}

const FERNITE_CARBIDE_WITH_FIGURED_JOB_INSTALL_COSTS: ProfitMarginResult = {
  ...FERNITE_CARBIDE_WITH_AN_UNKNOWN_JOB_INSTALL_COST,
  jobInstallCost: 2_315.04926,
  jobInstallSteps: [
    { ...FERNITE_CARBIDE_REACTION_WITHOUT_COST_INDEX, installCost: 441.56952, missingData: [] },
    HYDROGEN_FUEL_BLOCK_JOB_FIGURED,
  ],
  totalCost: 1_566_327.75926,
  costPerUnit: 52.210925,
  margins: {
    jitaSell: { revenue: 1_836_000, fees: 137_700, profit: 131_972.24074, margin: 8.43 },
    structureSell: { revenue: 1_794_000, fees: 134_550, profit: 93_122.24074, margin: 5.95 },
    structureBuy: { revenue: 1_623_000, fees: 73_035, profit: -16_362.75926, margin: -1.04 },
    contractSell: null,
  },
  missingData: [],
}

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  inventionCost: 254.424,
  totalProductionCost: null,
  bestMargin: null,
}

const PROCESS_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: PROCESS_DECRYPTOR_TYPE_ID,
  decryptorName: 'Process Decryptor',
  me: 5,
  te: 10,
  runs: 10,
  probability: 0.374,
  inventionCost: 1_280.574,
  totalProductionCost: null,
  bestMargin: null,
}

const HAIL_L_WITH_AN_UNKNOWN_JOB_INSTALL_COST: ProfitMarginResult = {
  typeId: HAIL_L_TYPE_ID,
  typeName: 'Hail L',
  isT2: true,
  runs: 1,
  outputQuantity: 5_000,
  outputPerRun: 5_000,
  materialCost: 647_325.76,
  materials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 496, unitPrice: 0.34, totalPrice: 168.64 },
    { typeId: 11399, typeName: 'Morphite', quantity: 14, unitPrice: 113.99, totalPrice: 1_595.86 },
  ],
  jobInstallCost: null,
  jobInstallSteps: [
    { productTypeId: HAIL_L_TYPE_ID, productName: 'Hail L', activityType: 'manufacturing', runs: 1, installCost: null, missingData: [{ reason: 'cost_index', typeId: null }] },
    { productTypeId: RAM_AMMUNITION_TECH_TYPE_ID, productName: 'R.A.M.- Ammunition Tech', activityType: 'manufacturing', runs: 1, installCost: null, missingData: [{ reason: 'cost_index', typeId: null }] },
  ],
  inventionCost: 254.424,
  totalCost: null,
  costPerUnit: null,
  invention: {
    baseProbability: 0.34,
    unknownReason: null,
    datacores: ['Stub Datacore A', 'Stub Datacore B'],
    selectedDecryptorTypeId: null,
    selectedDecryptorName: 'No Decryptor',
    options: [NO_DECRYPTOR_OPTION, PROCESS_DECRYPTOR_OPTION],
  },
  sellPrices: {
    jitaSell: 140,
    structureSell: null,
    structureBuy: null,
    contractSell: 138,
    contractCount: 3,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.036,
  salesTaxRate: 0.036,
  margins: { jitaSell: null, structureSell: null, structureBuy: null, contractSell: null },
  dailyVolume: 48_000,
  missingData: [{ reason: 'cost_index', typeId: null }],
  warnings: [],
}

function mountWith(analysis: ProfitMarginResult, locale: 'en' | 'fr') {
  vi.mocked(apiRequest).mockResolvedValue(null)
  useProjectsStore().marginResult = analysis
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  return { wrapper, t: i18n.global.t }
}

function jobInstallStepCost(wrapper: VueWrapper, productName: string): string {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('td').length === 5 && candidate.findAll('td')[1]!.text() === productName)
  expect(row, `job install step ${productName}`).toBeDefined()
  return row!.findAll('td')[4]!.text()
}

function lastCellOfRowContaining(wrapper: VueWrapper, text: string): string {
  const row = wrapper.findAll('tr').find(candidate => candidate.text().includes(text))
  expect(row, `row ${text}`).toBeDefined()
  const cells = row!.findAll('td')
  return cells[cells.length - 1]!.text()
}

function summaryCardAmount(wrapper: VueWrapper, label: string): string {
  const card = wrapper.findAll('div.eve-card.p-4').find(candidate => candidate.find('p').exists() && candidate.find('p').text() === label)
  expect(card, `summary card ${label}`).toBeDefined()
  return card!.find('p.font-mono').text()
}

function costSummaryAmount(wrapper: VueWrapper, label: string): string {
  const cell = wrapper.findAll('div.grid-cols-4 > div').find(candidate => candidate.find('div').text() === label)
  expect(cell, `cost summary ${label}`).toBeDefined()
  return cell!.findAll('div')[1]!.text()
}

async function selectProcessDecryptor(wrapper: VueWrapper): Promise<void> {
  const processRow = wrapper.findAll('tbody tr').find(row => row.text().includes('Process Decryptor'))
  expect(processRow, 'Process Decryptor row').toBeDefined()
  await processRow!.trigger('click')
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProfitMarginTab with an unknown job install cost', () => {
  it.each([
    ['en', 'Unknown'],
    ['fr', 'Inconnu'],
  ] as const)('shows the unknown job install step and the job install total as unknown, never as 0 (%s)', (locale, unknownLabel) => {
    const { wrapper, t } = mountWith(FERNITE_CARBIDE_WITH_AN_UNKNOWN_JOB_INSTALL_COST, locale)

    expect(jobInstallStepCost(wrapper, 'Fernite Carbide')).toBe(unknownLabel)
    expect(lastCellOfRowContaining(wrapper, t('industry.margins.totalJobInstallCost'))).toBe(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.costEstimation.jobInstall'))).toBe(unknownLabel)
  })

  it('keeps the figured job install step of the same tree figured', () => {
    const { wrapper } = mountWith(FERNITE_CARBIDE_WITH_AN_UNKNOWN_JOB_INSTALL_COST, 'en')

    expect(jobInstallStepCost(wrapper, 'Hydrogen Fuel Block')).toBe('1,873')
  })

  it.each([
    ['en', 'Unknown'],
    ['fr', 'Inconnu'],
  ] as const)('shows the production cost, profit and margin as unknown with the missing cost index reason (%s)', (locale, unknownLabel) => {
    const { wrapper, t } = mountWith(FERNITE_CARBIDE_WITH_AN_UNKNOWN_JOB_INSTALL_COST, locale)

    expect(summaryCardAmount(wrapper, t('industry.margins.totalCost'))).toBe(unknownLabel)
    expect(summaryCardAmount(wrapper, t('industry.margins.profit'))).toBe(unknownLabel)
    expect(summaryCardAmount(wrapper, t('industry.margins.margin'))).toBe(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.margins.totalProductionCost'))).toContain(unknownLabel)
    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missing_cost_index'))
    expect(wrapper.text()).not.toContain(t('industry.inventionUnknown.missing_adjusted_price'))
    expect(wrapper.text()).not.toContain(t('industry.inventionUnknown.missing_material_price'))
    expect(wrapper.text()).not.toContain(t('industry.margins.noData'))
  })

  it.each(['en', 'fr'] as const)('lists every unknown install cost reason with its label (%s)', locale => {
    const { wrapper, t } = mountWith(FERNITE_CARBIDE_WITH_BOTH_UNKNOWN_INSTALL_COST_REASONS, locale)

    expect(jobInstallStepCost(wrapper, 'Fernite Alloy')).toBe(t('industry.inventionUnknown.label'))
    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missing_cost_index'))
    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missing_adjusted_price'))
  })

  it.each(['en', 'fr'] as const)('lists no unknown install cost reason when every job install cost is figured (%s)', locale => {
    const { wrapper, t } = mountWith(FERNITE_CARBIDE_WITH_FIGURED_JOB_INSTALL_COSTS, locale)

    expect(wrapper.text()).not.toContain(t('industry.inventionUnknown.missing_cost_index'))
    expect(wrapper.text()).not.toContain(t('industry.inventionUnknown.missing_adjusted_price'))
    expect(jobInstallStepCost(wrapper, 'Fernite Carbide')).toBe('442')
  })

  it.each([
    ['en', 'Unknown', '1.28 K'],
    ['fr', 'Inconnu', '1,28 K'],
  ] as const)('keeps the production cost, profit and margin unknown once a decryptor is chosen, with its invention cost known (%s)', async (locale, unknownLabel, processInventionCost) => {
    const { wrapper, t } = mountWith(HAIL_L_WITH_AN_UNKNOWN_JOB_INSTALL_COST, locale)

    await selectProcessDecryptor(wrapper)

    expect(summaryCardAmount(wrapper, t('industry.margins.totalCost'))).toBe(unknownLabel)
    expect(summaryCardAmount(wrapper, t('industry.margins.profit'))).toBe(unknownLabel)
    expect(summaryCardAmount(wrapper, t('industry.margins.margin'))).toBe(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.costEstimation.jobInstall'))).toBe(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.margins.inventionCostLabel'))).toBe(processInventionCost)
    expect(costSummaryAmount(wrapper, t('industry.margins.totalProductionCost'))).toContain(unknownLabel)
    expect(jobInstallStepCost(wrapper, 'Hail L')).toBe(unknownLabel)
    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missing_cost_index'))
  })
})

import { mount, type VueWrapper } from '@vue/test-utils'
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
const ROBOTICS_TYPE_ID = 9848
const NITROGEN_ISOTOPES_TYPE_ID = 17888
const ATTAINMENT_DECRYPTOR_TYPE_ID = 34202

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  inventionCost: 26_000,
  totalProductionCost: null,
  bestMargin: null,
}

const ATTAINMENT_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: ATTAINMENT_DECRYPTOR_TYPE_ID,
  decryptorName: 'Attainment Decryptor',
  me: 1,
  te: 8,
  runs: 14,
  probability: 0.612,
  inventionCost: 45_000,
  totalProductionCost: null,
  bestMargin: null,
}

const SABRE_WITH_UNPRICED_MATERIALS: ProfitMarginResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 4,
  outputQuantity: 40,
  outputPerRun: 10,
  materialCost: null,
  materials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 12_000, unitPrice: 4.12, totalPrice: 49_440 },
    { typeId: 35, typeName: 'Pyerite', quantity: 3_000, unitPrice: 9.87, totalPrice: 29_610 },
    { typeId: ROBOTICS_TYPE_ID, typeName: 'Robotics', quantity: 8, unitPrice: null, totalPrice: null },
    { typeId: 37, typeName: 'Isogen', quantity: 220, unitPrice: 115.4, totalPrice: 25_388 },
    { typeId: 38, typeName: 'Nocxium', quantity: 64, unitPrice: 803, totalPrice: 51_392 },
    { typeId: 39, typeName: 'Zydrine', quantity: 18, unitPrice: 1_520, totalPrice: 27_360 },
    { typeId: NITROGEN_ISOTOPES_TYPE_ID, typeName: 'Nitrogen Isotopes', quantity: 450, unitPrice: null, totalPrice: null },
  ],
  jobInstallCost: 5_000,
  jobInstallSteps: [],
  inventionCost: 25_000,
  copyCost: 1_000,
  totalCost: null,
  costPerUnit: null,
  invention: {
    baseProbability: 0.34,
    unknownReason: null,
    datacores: ['Datacore - Mechanical Engineering'],
    selectedDecryptorTypeId: null,
    selectedDecryptorName: 'No Decryptor',
    options: [NO_DECRYPTOR_OPTION, ATTAINMENT_OPTION],
  },
  sellPrices: {
    jitaSell: 4_300,
    structureSell: 4_150,
    structureBuy: 4_000,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.05,
  salesTaxRate: 0.02,
  margins: { jitaSell: null, structureSell: null, structureBuy: null, contractSell: null },
  dailyVolume: null,
  missingPriceTypeIds: [ROBOTICS_TYPE_ID, NITROGEN_ISOTOPES_TYPE_ID],
}

function mountWithUnpricedMaterials(locale: 'en' | 'fr') {
  vi.mocked(apiRequest).mockResolvedValue(null)
  useProjectsStore().marginResult = SABRE_WITH_UNPRICED_MATERIALS
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  return { wrapper, t: i18n.global.t }
}

async function selectAttainmentDecryptor(wrapper: VueWrapper): Promise<void> {
  const attainmentRow = wrapper.findAll('tbody tr').find(row => row.text().includes('Attainment Decryptor'))
  expect(attainmentRow, 'Attainment Decryptor row').toBeDefined()
  await attainmentRow!.trigger('click')
}

function materialPriceCells(wrapper: VueWrapper, typeName: string): string[] {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('td').length === 4 && candidate.find('td').text() === typeName)
  expect(row, `material row ${typeName}`).toBeDefined()
  return row!.findAll('td').slice(2).map(cell => cell.text())
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

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProfitMarginTab with materials without a price', () => {
  it.each([
    ['en', 'Unknown'],
    ['fr', 'Inconnu'],
  ] as const)('shows the unpriced material lines and the material cost as unknown, never as 0 (%s)', (locale, unknownLabel) => {
    const { wrapper, t } = mountWithUnpricedMaterials(locale)

    expect(materialPriceCells(wrapper, 'Robotics')).toEqual([unknownLabel, unknownLabel])
    expect(lastCellOfRowContaining(wrapper, t('industry.costEstimation.moreItems', { count: 1 }))).toBe(unknownLabel)
    expect(lastCellOfRowContaining(wrapper, t('industry.costEstimation.totalMaterialCost'))).toBe(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.costEstimation.materials'))).toBe(unknownLabel)
  })

  it.each([
    ['en', 'Unknown'],
    ['fr', 'Inconnu'],
  ] as const)('shows the production cost, profit and margin as unknown with the missing material price reason (%s)', (locale, unknownLabel) => {
    const { wrapper, t } = mountWithUnpricedMaterials(locale)

    expect(summaryCardAmount(wrapper, t('industry.margins.totalCost'))).toBe(unknownLabel)
    expect(summaryCardAmount(wrapper, t('industry.margins.profit'))).toBe(unknownLabel)
    expect(summaryCardAmount(wrapper, t('industry.margins.margin'))).toBe(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.margins.totalProductionCost'))).toContain(unknownLabel)
    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missing_material_price'))
    expect(wrapper.text()).not.toContain(t('industry.margins.noData'))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('lists the materials without a price by name (%s)', locale => {
    const { wrapper, t } = mountWithUnpricedMaterials(locale)

    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missingPriceTypes', { types: 'Robotics, Nitrogen Isotopes' }))
  })

  it.each([
    ['en', 'Unknown', '45.00 K'],
    ['fr', 'Inconnu', '45,00 K'],
  ] as const)('keeps the production cost, profit and margin unknown once a decryptor is chosen, with its invention cost known (%s)', async (locale, unknownLabel, attainmentInventionCost) => {
    const { wrapper, t } = mountWithUnpricedMaterials(locale)

    await selectAttainmentDecryptor(wrapper)

    expect(summaryCardAmount(wrapper, t('industry.margins.totalCost'))).toBe(unknownLabel)
    expect(summaryCardAmount(wrapper, t('industry.margins.profit'))).toBe(unknownLabel)
    expect(summaryCardAmount(wrapper, t('industry.margins.margin'))).toBe(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.costEstimation.materials'))).toBe(unknownLabel)
    expect(costSummaryAmount(wrapper, t('industry.margins.inventionCostLabel'))).toBe(attainmentInventionCost)
    expect(costSummaryAmount(wrapper, t('industry.margins.totalProductionCost'))).toContain(unknownLabel)
    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missing_material_price'))
  })
})

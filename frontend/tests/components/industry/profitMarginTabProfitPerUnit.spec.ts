import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { MarginEntry, ProfitMarginInventionOption, ProfitMarginResult } from '@/stores/industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SABRE_TYPE_ID = 22456
const TRITANIUM_TYPE_ID = 34
const STRUCTURE_NAME = 'Tatara Alpha'
const JITA_SELL_UNIT_PRICE = 100
const BASE_TOTAL_COST = 860

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  manufacturingJobs: 1,
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  inventionCost: 300,
  totalProductionCost: BASE_TOTAL_COST,
  bestMargin: 116.28,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

const ATTAINMENT_OPTION: ProfitMarginInventionOption = {
  manufacturingJobs: 1,
  decryptorTypeId: 34_204,
  decryptorName: 'Attainment Decryptor',
  me: 1,
  te: 4,
  runs: 14,
  probability: 0.4,
  inventionCost: 300,
  totalProductionCost: 800,
  bestMargin: 132.5,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

const NO_MARGINS: Record<string, MarginEntry | null> = {
  jitaSell: null,
  structureSell: null,
  structureBuy: null,
  contractSell: null,
}

function jitaSellMargin(profit: number): Record<string, MarginEntry | null> {
  return { ...NO_MARGINS, jitaSell: { revenue: 2_000, fees: 140, profit, margin: 116.28 } }
}

const SABRE_WITH_JITA_SELL: ProfitMarginResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 2,
  outputQuantity: 20,
  outputPerRun: 10,
  materialCost: 400,
  materials: [
    { typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 500, unitPrice: 0.8, totalPrice: 400 },
  ],
  jobInstallCost: 160,
  jobInstallSteps: [],
  inventionCost: 300,
  totalCost: BASE_TOTAL_COST,
  costPerUnit: 43,
  invention: {
    baseProbability: 0.34,
    unknownReason: null,
    datacores: ['Datacore - Mechanical Engineering'],
    datacoresCostPerAttempt: null,
    inventor: null,
    selectedDecryptorTypeId: null,
    selectedDecryptorName: 'No Decryptor',
    options: [NO_DECRYPTOR_OPTION, ATTAINMENT_OPTION],
  },
  sellPrices: {
    jitaSell: JITA_SELL_UNIT_PRICE,
    jitaSellCoveragePercent: null,
    structureSell: null,
    structureBuy: null,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: STRUCTURE_NAME,
  },
  brokerFeeRate: 0.05,
  salesTaxRate: 0.02,
  margins: jitaSellMargin(1_000),
  dailyVolume: 420,
  missingData: [],
  warnings: [],
}

const SABRE_WITH_ONE_UNIT_PER_RUN_OVER_THREE_RUNS: ProfitMarginResult = {
  ...SABRE_WITH_JITA_SELL,
  runs: 3,
  outputQuantity: 3,
  outputPerRun: 1,
  margins: jitaSellMargin(900),
}

const SABRE_WITH_NO_SELL_PRICE: ProfitMarginResult = {
  ...SABRE_WITH_JITA_SELL,
  sellPrices: { ...SABRE_WITH_JITA_SELL.sellPrices, jitaSell: null },
  margins: NO_MARGINS,
}

const SABRE_WITH_NO_OUTPUT_QUANTITY: ProfitMarginResult = {
  ...SABRE_WITH_JITA_SELL,
  runs: 1,
  outputQuantity: 0,
  outputPerRun: 0,
}

const SABRE_SOLD_AT_A_LOSS: ProfitMarginResult = {
  ...SABRE_WITH_JITA_SELL,
  runs: 3,
  outputQuantity: 30,
  outputPerRun: 10,
  margins: jitaSellMargin(-450),
}

const SABRE_WITH_ATTAINMENT_CHOSEN: ProfitMarginResult = {
  ...SABRE_WITH_JITA_SELL,
  materialCost: 350,
  jobInstallCost: 150,
  inventionCost: ATTAINMENT_OPTION.inventionCost,
  totalCost: ATTAINMENT_OPTION.totalProductionCost,
  margins: jitaSellMargin(1_060),
  invention: {
    ...present(SABRE_WITH_JITA_SELL.invention),
    selectedDecryptorTypeId: ATTAINMENT_OPTION.decryptorTypeId,
    selectedDecryptorName: ATTAINMENT_OPTION.decryptorName,
  },
}

function mountWithAnalysis(analysis: ProfitMarginResult) {
  vi.mocked(apiRequest).mockImplementation(path => Promise.resolve(path.includes(`decryptorTypeId=${ATTAINMENT_OPTION.decryptorTypeId}`) ? SABRE_WITH_ATTAINMENT_CHOSEN : null))
  useProjectsStore().marginResult = analysis
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  return { wrapper, t: i18n.global.t }
}

function profitCard(wrapper: VueWrapper, t: (key: string) => string): DOMWrapper<Element> {
  const card = wrapper.findAll('.eve-card').find(candidate => candidate.findAll('p')[0]?.text() === t('industry.margins.profit'))
  expect(card, 'Profit card').toBeDefined()
  return present(card)
}

function profitCardLines(wrapper: VueWrapper, t: (key: string) => string): { profit: string; perUnit: string } {
  const [, profit, perUnit] = profitCard(wrapper, t).findAll('p')
  return { profit: present(profit).text(), perUnit: present(perUnit).text() }
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProfitMarginTab profit per unit', () => {
  it('divides the profit by the output quantity of all runs', () => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_JITA_SELL)

    expect(profitCardLines(wrapper, t)).toEqual({
      profit: '+1.00 K',
      perUnit: `${t('industry.margins.perUnit')}: +50 ISK`,
    })
  })

  it('shows 300 ISK per unit for 900 ISK of profit over 3 runs of 1 unit', () => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_ONE_UNIT_PER_RUN_OVER_THREE_RUNS)

    expect(profitCardLines(wrapper, t)).toEqual({
      profit: '+900',
      perUnit: `${t('industry.margins.perUnit')}: +300 ISK`,
    })
  })

  it('shows the profit and the profit per unit as unknown when no sell price is known', () => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_NO_SELL_PRICE)

    expect(profitCardLines(wrapper, t)).toEqual({
      profit: t('industry.inventionUnknown.label'),
      perUnit: `${t('industry.margins.perUnit')}: ${t('industry.inventionUnknown.label')}`,
    })
  })

  it('shows the profit per unit as unknown when the output quantity is 0', () => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_NO_OUTPUT_QUANTITY)

    expect(profitCardLines(wrapper, t)).toEqual({
      profit: '+1.00 K',
      perUnit: `${t('industry.margins.perUnit')}: ${t('industry.inventionUnknown.label')}`,
    })
  })

  it('divides a negative profit by the output quantity and keeps its sign', () => {
    const { wrapper, t } = mountWithAnalysis(SABRE_SOLD_AT_A_LOSS)

    expect(profitCardLines(wrapper, t)).toEqual({
      profit: '-450',
      perUnit: `${t('industry.margins.perUnit')}: -15 ISK`,
    })
    expect(present(profitCard(wrapper, t).findAll('p')[1]).classes()).toContain('text-red-400')
  })

  it('divides the profit returned by the server for the chosen decryptor by the output quantity', async () => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_JITA_SELL)

    const attainmentRow = wrapper.findAll('tbody tr').find(row => row.text().includes('Attainment Decryptor'))
    expect(attainmentRow, 'Attainment Decryptor row').toBeDefined()
    await present(attainmentRow).trigger('click')
    await flushPromises()

    expect(profitCardLines(wrapper, t)).toEqual({
      profit: '+1.06 K',
      perUnit: `${t('industry.margins.perUnit')}: +53 ISK`,
    })
  })
})

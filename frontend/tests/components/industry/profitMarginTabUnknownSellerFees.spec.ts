import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { ProfitMarginInventionOption, ProfitMarginResult } from '@/stores/industry/types'
import { expectedMessage } from '../../expectedMessage'
import type { TestLocale } from '../../localeTexts'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const UNKNOWN_FEES_KEY = 'industry.fees.unknown'
const UNKNOWN_FEES_REASON_KEY = 'industry.fees.unknownReason'
const REVENUE_COLUMN = 2
const FEES_COLUMN = 3
const PROFIT_COLUMN = 4
const MARGIN_COLUMN = 5

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  inventionCost: 1_000,
  totalProductionCost: 5_000,
  bestMargin: null,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

const ATTAINMENT_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: 34_204,
  decryptorName: 'Attainment Decryptor',
  me: 1,
  te: 4,
  runs: 14,
  probability: 0.4,
  inventionCost: 1_800,
  totalProductionCost: 5_800,
  bestMargin: null,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

const SABRE_WITH_UNKNOWN_SELLER_FEES: ProfitMarginResult = {
  typeId: 22_456,
  typeName: 'Sabre',
  isT2: true,
  runs: 4,
  outputQuantity: 40,
  outputPerRun: 10,
  materialCost: 3_000,
  materials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 500, unitPrice: 6, totalPrice: 3_000 },
  ],
  jobInstallCost: 1_000,
  jobInstallSteps: [],
  inventionCost: 1_000,
  totalCost: 5_000,
  costPerUnit: 125,
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
    jitaSell: 190,
    jitaSellCoveragePercent: null,
    structureSell: null,
    structureBuy: null,
    contractSell: 185,
    contractCount: 3,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: null,
  salesTaxRate: null,
  margins: {
    jitaSell: { revenue: 7_600, fees: null, profit: null, margin: null },
    structureSell: null,
    structureBuy: null,
    contractSell: { revenue: 7_400, fees: 0, profit: 2_400, margin: 48 },
  },
  dailyVolume: 420,
  missingData: [],
  warnings: [],
}

function mountWithAnalysis(locale: TestLocale): VueWrapper {
  vi.mocked(apiRequest).mockResolvedValue(SABRE_WITH_UNKNOWN_SELLER_FEES)
  useProjectsStore().marginResult = SABRE_WITH_UNKNOWN_SELLER_FEES
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(ProfitMarginTab, { global: { plugins: [i18n], stubs: { ProductSearch: true } } })
}

function venueRowTagged(wrapper: VueWrapper, tag: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('span').some(span => span.text() === tag))
  if (row === undefined) throw new Error(`venue row ${tag} not found`)
  return row
}

function unknownValueTitle(cell: DOMWrapper<Element>): string | null {
  const marker = cell.find('span[title]')
  return marker.exists() ? (marker.attributes('title') ?? null) : null
}

function jitaRowCell(wrapper: VueWrapper, index: number): DOMWrapper<Element> {
  const cell = venueRowTagged(wrapper, en.industry.margins.sellTag).findAll('td')[index]
  if (cell === undefined) throw new Error(`no cell ${index}`)
  return cell
}

function unknownFeesState(wrapper: VueWrapper) {
  return {
    feesUnknown: unknownValueTitle(jitaRowCell(wrapper, FEES_COLUMN)),
    profitUnknown: unknownValueTitle(jitaRowCell(wrapper, PROFIT_COLUMN)),
    marginText: jitaRowCell(wrapper, MARGIN_COLUMN).text(),
    revenueKept: jitaRowCell(wrapper, REVENUE_COLUMN).text().includes('7.60 K'),
    garbage: /NaN|undefined|null/.test(wrapper.text()),
  }
}

const EXPECTED_UNKNOWN_FEES_STATE = {
  feesUnknown: en.common.unknownValue,
  profitUnknown: en.common.unknownValue,
  marginText: en.industry.inventionUnknown.label,
  revenueKept: true,
  garbage: false,
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProfitMarginTab with unknown seller fees', () => {
  it('prompts to sync the skills and standings and keeps the revenue with unknown fees, profit and margin on the market rows', () => {
    const wrapper = mountWithAnalysis('en')

    expect({
      state: unknownFeesState(wrapper),
      notice: [expectedMessage('en', UNKNOWN_FEES_KEY), expectedMessage('en', UNKNOWN_FEES_REASON_KEY)].map(message => wrapper.text().includes(message)),
    }).toEqual({ state: EXPECTED_UNKNOWN_FEES_STATE, notice: [true, true] })
  })

  it('shows the prompt in French', () => {
    const wrapper = mountWithAnalysis('fr')

    expect([expectedMessage('fr', UNKNOWN_FEES_KEY), expectedMessage('fr', UNKNOWN_FEES_REASON_KEY)].map(message => wrapper.text().includes(message))).toEqual([true, true])
  })

  it('keeps the market rows unknown, not recomputed with zero rates, when a decryptor option is selected', async () => {
    const wrapper = mountWithAnalysis('en')
    const attainmentRow = wrapper.findAll('tbody tr').find(row => row.text().includes('Attainment Decryptor'))
    if (attainmentRow === undefined) throw new Error('Attainment Decryptor row not found')

    await attainmentRow.trigger('click')
    await flushPromises()

    expect(unknownFeesState(wrapper)).toEqual(EXPECTED_UNKNOWN_FEES_STATE)
  })
})

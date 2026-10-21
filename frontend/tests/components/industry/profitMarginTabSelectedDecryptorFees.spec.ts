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
const ATTAINMENT_DECRYPTOR_TYPE_ID = 34202
const STRUCTURE_NAME = 'Tatara Alpha'

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  inventionCost: 26_000,
  totalProductionCost: 131_000,
  bestMargin: 19.69,
}

const ATTAINMENT_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: ATTAINMENT_DECRYPTOR_TYPE_ID,
  decryptorName: 'Attainment Decryptor',
  me: 1,
  te: 8,
  runs: 14,
  probability: 0.612,
  inventionCost: 45_000,
  totalProductionCost: 150_000,
  bestMargin: 4.53,
}

const SABRE_MARGIN_RESULT: ProfitMarginResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 4,
  outputQuantity: 40,
  outputPerRun: 10,
  materialCost: 100_000,
  materials: [],
  jobInstallCost: 5_000,
  jobInstallSteps: [],
  inventionCost: 25_000,
  copyCost: 1_000,
  totalCost: 131_000,
  costPerUnit: 3_275,
  invention: {
    baseProbability: 0.34,
    unknownReason: null,
    datacores: ['Datacore - Mechanical Engineering'],
    selectedDecryptorTypeId: null,
    selectedDecryptorName: 'No Decryptor',
    options: [NO_DECRYPTOR_OPTION, ATTAINMENT_OPTION],
  },
  sellPrices: {
    jitaSell: null,
    structureSell: 4_150,
    structureBuy: 4_000,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: STRUCTURE_NAME,
  },
  brokerFeeRate: 0.05,
  salesTaxRate: 0.02,
  margins: {
    structureSell: { revenue: 166_000, fees: 11_620, profit: 23_380, margin: 17.85 },
    structureBuy: { revenue: 160_000, fees: 3_200, profit: 25_800, margin: 19.69 },
  },
  dailyVolume: null,
  missingPriceTypeIds: [],
  unknownInstallCostReasons: [],
}

const marginLabels = en.industry.margins

async function mountProfitMarginWithAttainmentSelected(): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(null)
  useProjectsStore().marginResult = SABRE_MARGIN_RESULT
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  const attainmentRow = wrapper.findAll('tbody tr').find(row => row.text().includes('Attainment Decryptor'))
  expect(attainmentRow, 'Attainment Decryptor row').toBeDefined()
  await attainmentRow!.trigger('click')
  return wrapper
}

function venueRowTagged(wrapper: VueWrapper, tag: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('span').some(span => span.text() === tag))
  expect(row, `venue row ${tag}`).toBeDefined()
  return row!
}

function figuresOf(row: DOMWrapper<Element>): string[] {
  return row.findAll('td').slice(2, 6).map(cell => cell.text())
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProfitMarginTab with a selected decryptor', () => {
  it('recomputes the sale to the structure buy order with the sales tax only and the sell order with the broker fee too', async () => {
    const wrapper = await mountProfitMarginWithAttainmentSelected()

    expect(figuresOf(venueRowTagged(wrapper, marginLabels.instantSellTag))).toEqual(['160.00 K', '-3.20 K', '+6.80 K', '+4.5%'])
    expect(figuresOf(venueRowTagged(wrapper, marginLabels.undercutTag))).toEqual(['166.00 K', '-11.62 K', '+4.38 K', '+2.9%'])
  })

  it('ranks the sale to the structure buy order as the best venue once its broker fee is left out', async () => {
    const wrapper = await mountProfitMarginWithAttainmentSelected()

    expect(venueRowTagged(wrapper, marginLabels.instantSellTag).text()).toContain(marginLabels.best)
    expect(venueRowTagged(wrapper, marginLabels.undercutTag).text()).not.toContain(marginLabels.best)
    expect(wrapper.text()).toContain(`${STRUCTURE_NAME} (${marginLabels.instantSellTag})`)
  })
})

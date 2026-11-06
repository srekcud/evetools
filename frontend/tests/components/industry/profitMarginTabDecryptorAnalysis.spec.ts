import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import type { ProfitMarginInventionOption, ProfitMarginResult, SearchResult } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const SABRE_TYPE_ID = 22456
const ATTAINMENT_DECRYPTOR_TYPE_ID = 34202
const TRITANIUM_TYPE_ID = 34
const PYERITE_TYPE_ID = 35
const PROFIT_MARGIN_ENDPOINT = `/industry/profit-margin/${SABRE_TYPE_ID}`
const SABRE: SearchResult = { typeId: SABRE_TYPE_ID, typeName: 'Sabre', isT2: true }
const PYERITE_COVERAGE_PERCENT = 41

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  materialCost: 100_000,
  jobInstallCost: 5_000,
  inventionCost: 26_000,
  totalProductionCost: 131_000,
  bestMargin: 19.69,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

const ATTAINMENT_OPTION: ProfitMarginInventionOption = {
  ...NO_DECRYPTOR_OPTION,
  decryptorTypeId: ATTAINMENT_DECRYPTOR_TYPE_ID,
  decryptorName: 'Attainment Decryptor',
  me: 1,
  te: 8,
  materialCost: 96_000,
  jobInstallCost: 9_000,
  inventionCost: 45_000,
  totalProductionCost: 150_000,
  bestMargin: 4.53,
}

const WITHOUT_DECRYPTOR_RESULT: ProfitMarginResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 4,
  outputQuantity: 40,
  outputPerRun: 10,
  materialCost: 100_000,
  materials: [{ typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 12_000, unitPrice: 6, totalPrice: 72_000 }],
  jobInstallCost: 5_000,
  jobInstallSteps: [],
  inventionCost: 26_000,
  totalCost: 131_000,
  costPerUnit: 3_275,
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
    jitaSell: null,
    structureSell: 4_150,
    structureBuy: 4_000,
    contractSell: null,
    contractCount: null,
    jitaSellCoveragePercent: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.05,
  salesTaxRate: 0.02,
  margins: {
    structureSell: { revenue: 166_000, fees: 11_620, profit: 23_380, margin: 17.85 },
    structureBuy: { revenue: 160_000, fees: 3_200, profit: 25_800, margin: 19.69 },
  },
  dailyVolume: null,
  missingData: [],
  warnings: [],
}

const WITH_ATTAINMENT_RESULT: ProfitMarginResult = {
  ...WITHOUT_DECRYPTOR_RESULT,
  materialCost: 96_000,
  materials: [{ typeId: PYERITE_TYPE_ID, typeName: 'Pyerite', quantity: 3_000, unitPrice: 9.87, totalPrice: 29_610 }],
  jobInstallCost: 9_000,
  inventionCost: 45_000,
  totalCost: 150_000,
  costPerUnit: 3_750,
  invention: {
    ...WITHOUT_DECRYPTOR_RESULT.invention!,
    selectedDecryptorTypeId: ATTAINMENT_DECRYPTOR_TYPE_ID,
    selectedDecryptorName: 'Attainment Decryptor',
  },
  warnings: [{ reason: 'partial_market_depth', typeId: PYERITE_TYPE_ID, coveragePercent: PYERITE_COVERAGE_PERCENT }],
}

function respondLikeTheServer(path: string): Promise<unknown> {
  if (path === `/industry/types/${SABRE_TYPE_ID}`) return Promise.resolve(SABRE)
  if (path.startsWith(PROFIT_MARGIN_ENDPOINT)) {
    const decryptorChosen = path.includes(`decryptorTypeId=${ATTAINMENT_DECRYPTOR_TYPE_ID}`)
    return Promise.resolve(decryptorChosen ? WITH_ATTAINMENT_RESULT : WITHOUT_DECRYPTOR_RESULT)
  }
  return Promise.resolve(null)
}

async function mountSabreAnalysis(): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(respondLikeTheServer)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
    props: { initialTypeId: SABRE_TYPE_ID },
  })
  await flushPromises()
  return wrapper
}

async function chooseAttainment(wrapper: VueWrapper): Promise<void> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('td').length === 14 && candidate.text().includes('Attainment Decryptor'))
  expect(row, 'Attainment Decryptor row').toBeDefined()
  await row!.trigger('click')
  await flushPromises()
}

function marginRequests(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([path]) => path).filter(path => path.startsWith(PROFIT_MARGIN_ENDPOINT))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ProfitMarginTab choosing a decryptor', () => {
  it('analyzes the product again with the type of the chosen decryptor', async () => {
    const wrapper = await mountSabreAnalysis()

    await chooseAttainment(wrapper)

    expect(marginRequests()).toHaveLength(2)
    expect(marginRequests()[1]).toContain(`decryptorTypeId=${ATTAINMENT_DECRYPTOR_TYPE_ID}`)
  })

  it('shows the materials returned by the server for the chosen decryptor, not those of the analysis without decryptor', async () => {
    const wrapper = await mountSabreAnalysis()
    expect(wrapper.text()).toContain('Tritanium')

    await chooseAttainment(wrapper)

    expect(wrapper.text()).toContain('Pyerite')
    expect(wrapper.text()).not.toContain('Tritanium')
  })

  it('shows the partial price warning returned by the server for the chosen decryptor', async () => {
    const wrapper = await mountSabreAnalysis()
    expect(wrapper.text()).not.toContain(en.industry.partialPrice.noticeTitle)

    await chooseAttainment(wrapper)

    expect(wrapper.text()).toContain(en.industry.partialPrice.noticeTitle)
    expect(wrapper.text()).toContain(`Pyerite: order book covers ${PYERITE_COVERAGE_PERCENT}%`)
  })
})

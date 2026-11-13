import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import type { ProfitMarginInventionOption, ProfitMarginResult, SearchResult } from '@/stores/industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const SABRE_TYPE_ID = 22456
const ATTAINMENT_DECRYPTOR_TYPE_ID = 34202
const PARITY_DECRYPTOR_TYPE_ID = 34204
const PROFIT_MARGIN_ENDPOINT = `/industry/profit-margin/${SABRE_TYPE_ID}`
const SABRE: SearchResult = { typeId: SABRE_TYPE_ID, typeName: 'Sabre', isT2: true }

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  manufacturingJobs: 1,
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  inventionCost: 26_000,
  totalProductionCost: 131_000,
  bestMargin: 19.69,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

const ATTAINMENT_OPTION: ProfitMarginInventionOption = {
  manufacturingJobs: 1,
  decryptorTypeId: ATTAINMENT_DECRYPTOR_TYPE_ID,
  decryptorName: 'Attainment Decryptor',
  me: 1,
  te: 8,
  runs: 14,
  probability: 0.612,
  inventionCost: 45_000,
  totalProductionCost: 150_000,
  bestMargin: 4.53,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

const PARITY_OPTION_WITHOUT_MATERIAL_PRICE: ProfitMarginInventionOption = {
  manufacturingJobs: 1,
  decryptorTypeId: PARITY_DECRYPTOR_TYPE_ID,
  decryptorName: 'Parity Decryptor',
  me: 3,
  te: 2,
  runs: 13,
  probability: 0.51,
  inventionCost: 33_000,
  totalProductionCost: null,
  bestMargin: null,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
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
    options: [NO_DECRYPTOR_OPTION, ATTAINMENT_OPTION, PARITY_OPTION_WITHOUT_MATERIAL_PRICE],
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

const ATTAINMENT_MARGIN_RESULT: ProfitMarginResult = {
  ...SABRE_MARGIN_RESULT,
  materialCost: 96_000,
  jobInstallCost: 9_000,
  inventionCost: 45_000,
  totalCost: 150_000,
  costPerUnit: 3_750,
  invention: {
    ...present(SABRE_MARGIN_RESULT.invention),
    selectedDecryptorTypeId: ATTAINMENT_DECRYPTOR_TYPE_ID,
    selectedDecryptorName: 'Attainment Decryptor',
  },
}

const PARITY_MARGIN_RESULT: ProfitMarginResult = {
  ...SABRE_MARGIN_RESULT,
  materialCost: null,
  jobInstallCost: 7_000,
  inventionCost: 33_000,
  totalCost: null,
  costPerUnit: null,
  invention: {
    ...present(SABRE_MARGIN_RESULT.invention),
    selectedDecryptorTypeId: PARITY_DECRYPTOR_TYPE_ID,
    selectedDecryptorName: 'Parity Decryptor',
  },
  margins: { structureSell: null, structureBuy: null },
}

const costLabels = {
  materials: en.industry.costEstimation.materials,
  jobInstall: en.industry.costEstimation.jobInstall,
  invention: en.industry.margins.inventionCostLabel,
}
const UNKNOWN_LABEL = en.industry.inventionUnknown.label

function respondLikeTheServer(path: string): Promise<unknown> {
  if (path === `/industry/types/${SABRE_TYPE_ID}`) return Promise.resolve(SABRE)
  if (path.startsWith(PROFIT_MARGIN_ENDPOINT)) {
    if (path.includes(`decryptorTypeId=${ATTAINMENT_DECRYPTOR_TYPE_ID}`)) return Promise.resolve(ATTAINMENT_MARGIN_RESULT)
    if (path.includes(`decryptorTypeId=${PARITY_DECRYPTOR_TYPE_ID}`)) return Promise.resolve(PARITY_MARGIN_RESULT)
    return Promise.resolve(SABRE_MARGIN_RESULT)
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

async function chooseDecryptor(wrapper: VueWrapper, rowLabel: string): Promise<void> {
  await decryptorRow(wrapper, rowLabel).trigger('click')
  await flushPromises()
}

function decryptorRow(wrapper: VueWrapper, rowLabel: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('td').length === 14 && candidate.text().includes(rowLabel))
  expect(row, `decryptor row ${rowLabel}`).toBeDefined()
  return present(row)
}

function isMarkedSelected(row: DOMWrapper<Element>): boolean {
  return row.find('div.bg-cyan-400').exists()
}

async function refreshPrices(wrapper: VueWrapper): Promise<void> {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === en.industry.margins.refreshPrices)
  expect(button, 'refresh prices button').toBeDefined()
  await present(button).trigger('click')
  await flushPromises()
}

function costCard(wrapper: VueWrapper, label: string): { amount: string, percent: string } {
  const card = wrapper.findAll('div.grid-cols-4 > div').find(candidate => candidate.find('div').text() === label)
  expect(card, `cost card ${label}`).toBeDefined()
  const [, amount, percent] = present(card).findAll('div')
  return { amount: present(amount).text(), percent: present(percent).text() }
}

function breakdownBarWidths(wrapper: VueWrapper): number[] | null {
  const bar = wrapper.find('div.h-3.rounded-full')
  if (!bar.exists()) return null
  return bar.findAll('div').map(segment => parseFloat((segment.element as HTMLElement).style.width))
}

function marginRequests(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([path]) => path).filter(path => path.startsWith(PROFIT_MARGIN_ENDPOINT))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ProfitMarginTab cost breakdown with a selected decryptor', () => {
  it('shows the material cost, the job install cost and the invention cost of the selected option', async () => {
    const wrapper = await mountSabreAnalysis()

    await chooseDecryptor(wrapper, 'Attainment Decryptor')

    expect(costCard(wrapper, costLabels.materials).amount).toBe('96.00 K')
    expect(costCard(wrapper, costLabels.jobInstall).amount).toBe('9.00 K')
    expect(costCard(wrapper, costLabels.invention).amount).toBe('45.00 K')
  })

  it('shares the total production cost of the selected option between the three costs', async () => {
    const wrapper = await mountSabreAnalysis()

    await chooseDecryptor(wrapper, 'Attainment Decryptor')

    expect(costCard(wrapper, costLabels.materials).percent).toBe('64.0%')
    expect(costCard(wrapper, costLabels.jobInstall).percent).toBe('6.0%')
    expect(costCard(wrapper, costLabels.invention).percent).toBe('30.0%')
  })

  it('draws the breakdown bar of the analysis over 100 % of the total cost', async () => {
    const wrapper = await mountSabreAnalysis()

    const widths = breakdownBarWidths(wrapper)

    expect(widths).toHaveLength(3)
    expect(present(widths).reduce((sum, width) => sum + width, 0)).toBeCloseTo(100, 0)
  })

  it('draws the breakdown bar of the selected option over 100 % of its total production cost', async () => {
    const wrapper = await mountSabreAnalysis()

    await chooseDecryptor(wrapper, 'Attainment Decryptor')

    const widths = breakdownBarWidths(wrapper)
    expect(widths).toHaveLength(3)
    expect(present(widths).reduce((sum, width) => sum + width, 0)).toBeCloseTo(100, 0)
    expect(present(widths)[0]).toBeCloseTo(64, 1)
    expect(present(widths)[1]).toBeCloseTo(6, 1)
    expect(present(widths)[2]).toBeCloseTo(30, 1)
  })

  it('shows an unknown material cost and no breakdown bar when the selected option has no material cost', async () => {
    const wrapper = await mountSabreAnalysis()

    await chooseDecryptor(wrapper, 'Parity Decryptor')

    expect(costCard(wrapper, costLabels.materials)).toEqual({ amount: UNKNOWN_LABEL, percent: UNKNOWN_LABEL })
    expect(breakdownBarWidths(wrapper)).toBeNull()
  })
})

describe('ProfitMarginTab refreshing the prices', () => {
  it('analyzes the product again with the chosen decryptor and keeps it selected', async () => {
    const wrapper = await mountSabreAnalysis()
    await chooseDecryptor(wrapper, 'Attainment Decryptor')

    await refreshPrices(wrapper)

    expect(marginRequests()).toHaveLength(3)
    expect(marginRequests()[2]).toContain(`decryptorTypeId=${ATTAINMENT_DECRYPTOR_TYPE_ID}`)
    expect(isMarkedSelected(decryptorRow(wrapper, 'Attainment Decryptor'))).toBe(true)
    expect(isMarkedSelected(decryptorRow(wrapper, en.industry.bpcKitTab.none))).toBe(false)
  })

  it('sends no decryptor when none was chosen', async () => {
    const wrapper = await mountSabreAnalysis()

    await refreshPrices(wrapper)

    expect(marginRequests()).toHaveLength(2)
    expect(marginRequests()[1]).not.toContain('decryptorTypeId')
  })

  it('sends no decryptor once No Decryptor was chosen after another one', async () => {
    const wrapper = await mountSabreAnalysis()
    await chooseDecryptor(wrapper, 'Attainment Decryptor')
    await chooseDecryptor(wrapper, en.industry.bpcKitTab.none)

    await refreshPrices(wrapper)

    expect(marginRequests()).toHaveLength(4)
    expect(marginRequests()[3]).not.toContain('decryptorTypeId')
  })
})

import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
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
const TRITANIUM_TYPE_ID = 34
const STRUCTURE_NAME = 'Tatara Alpha'

const JITA_SELL_COVERAGE_PERCENT = 30
const TRITANIUM_COVERAGE_PERCENT = 62

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  materialCost: 3_000,
  jobInstallCost: 1_000,
  inventionCost: 1_000,
  totalProductionCost: 5_000,
  bestMargin: 41.36,
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
  materialCost: 2_700,
  jobInstallCost: 1_300,
  inventionCost: 1_800,
  totalProductionCost: 5_800,
  bestMargin: 31.1,
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

const SABRE_WITH_PARTIAL_JITA_SELL: ProfitMarginResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 4,
  outputQuantity: 40,
  outputPerRun: 10,
  materialCost: 3_000,
  materials: [
    { typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 500, unitPrice: 6, totalPrice: 3_000 },
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
    jitaSellCoveragePercent: JITA_SELL_COVERAGE_PERCENT,
    structureSell: 200,
    structureBuy: 180,
    contractSell: 185,
    contractCount: 3,
    structureId: 1_035_466_617_946,
    structureName: STRUCTURE_NAME,
  },
  brokerFeeRate: 0.05,
  salesTaxRate: 0.02,
  margins: {
    jitaSell: { revenue: 7_600, fees: 532, profit: 2_068, margin: 41.36 },
    structureSell: { revenue: 8_000, fees: 560, profit: 2_440, margin: 48.8 },
    structureBuy: { revenue: 7_200, fees: 144, profit: 2_056, margin: 41.12 },
    contractSell: { revenue: 7_400, fees: 74, profit: 2_326, margin: 46.52 },
  },
  dailyVolume: 420,
  missingData: [],
  warnings: [],
}

const SABRE_WITH_FULL_JITA_SELL: ProfitMarginResult = {
  ...SABRE_WITH_PARTIAL_JITA_SELL,
  sellPrices: { ...SABRE_WITH_PARTIAL_JITA_SELL.sellPrices, jitaSellCoveragePercent: null },
}

const SABRE_WITH_PARTIAL_JITA_SELL_AND_PARTIAL_MATERIAL: ProfitMarginResult = {
  ...SABRE_WITH_PARTIAL_JITA_SELL,
  warnings: [{ reason: 'partial_market_depth', typeId: TRITANIUM_TYPE_ID, coveragePercent: TRITANIUM_COVERAGE_PERCENT }],
}

function mountWithAnalysis(analysis: ProfitMarginResult, locale: 'en' | 'fr') {
  vi.mocked(apiRequest).mockResolvedValue(analysis)
  useProjectsStore().marginResult = analysis
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  return { wrapper, t: i18n.global.t }
}

function venueRowTagged(wrapper: VueWrapper, tag: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('span').some(span => span.text() === tag))
  expect(row, `venue row ${tag}`).toBeDefined()
  return row!
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProfitMarginTab with a partial Jita sell price', () => {
  it.each([
    ['en'],
    ['fr'],
  ] as const)('marks the Jita row with the order book coverage and keeps its revenue displayed (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_JITA_SELL, locale)

    const jitaRow = venueRowTagged(wrapper, t('industry.margins.sellTag'))
    expect(jitaRow.text()).toContain(t('industry.partialPrice.marker', { percent: JITA_SELL_COVERAGE_PERCENT }))
    expect(jitaRow.findAll('td')[2]!.text()).toContain('7.60 K'.replace('.', locale === 'fr' ? ',' : '.'))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('never marks the structure and contract rows (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_JITA_SELL, locale)

    for (const tag of ['undercutTag', 'instantSellTag', 'contractTag']) {
      expect(venueRowTagged(wrapper, t(`industry.margins.${tag}`)).text()).not.toContain(t('industry.partialPrice.marker', { percent: '' }).trim())
    }
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('lists the product with its Jita sell coverage in the notice (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_JITA_SELL, locale)

    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeTitle'))
    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: 'Sabre', percent: JITA_SELL_COVERAGE_PERCENT }))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('lists the product beside a partially priced material in the notice (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_JITA_SELL_AND_PARTIAL_MATERIAL, locale)

    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: 'Sabre', percent: JITA_SELL_COVERAGE_PERCENT }))
    expect(wrapper.text()).toContain(t('industry.partialPrice.noticeLine', { type: 'Tritanium', percent: TRITANIUM_COVERAGE_PERCENT }))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('shows neither notice nor marker on the Jita row when the sell price is fully covered (%s)', locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_FULL_JITA_SELL, locale)

    expect(wrapper.text()).not.toContain(t('industry.partialPrice.noticeTitle'))
    expect(venueRowTagged(wrapper, t('industry.margins.sellTag')).text()).not.toContain(t('industry.partialPrice.marker', { percent: '' }).trim())
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('keeps the marker on the Jita row when a decryptor option is selected (%s)', async locale => {
    const { wrapper, t } = mountWithAnalysis(SABRE_WITH_PARTIAL_JITA_SELL, locale)

    const attainmentRow = wrapper.findAll('tbody tr').find(row => row.text().includes('Attainment Decryptor'))
    expect(attainmentRow, 'Attainment Decryptor row').toBeDefined()
    await attainmentRow!.trigger('click')
    await flushPromises()

    const jitaRow = venueRowTagged(wrapper, t('industry.margins.sellTag'))
    expect(jitaRow.text()).toContain(t('industry.partialPrice.marker', { percent: JITA_SELL_COVERAGE_PERCENT }))
    expect(jitaRow.findAll('td')[2]!.text()).toContain('7.60 K'.replace('.', locale === 'fr' ? ',' : '.'))
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('explains on the Jita marker that the revenue covers only the covered part of the order book (%s)', locale => {
    const { wrapper } = mountWithAnalysis(SABRE_WITH_PARTIAL_JITA_SELL, locale)

    const sellTooltips: Record<string, Record<string, string>> = { en: en.industry.partialPrice, fr: fr.industry.partialPrice }
    const sellTooltip = sellTooltips[locale]!['sellTooltip']
    expect(sellTooltip).toBeTypeOf('string')
    const jitaRow = venueRowTagged(wrapper, locale === 'fr' ? fr.industry.margins.sellTag : en.industry.margins.sellTag)
    expect(jitaRow.find('span[title]').attributes('title')).toBe(sellTooltip)
  })
})

import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import { apiRequest } from '@/services/api'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BatchScanItem } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const CONTRACTS_VENUE = 'contracts'
const JITA_VENUE = 'jita'
const DAYS_PER_MONTH = 30

const CAPITAL_BY_CONTRACT: BatchScanItem = {
  typeId: 19720,
  typeName: 'Revelation',
  groupName: 'Dreadnought',
  categoryLabel: 'Capitals',
  marginPercent: 18,
  profitPerUnit: 450_000_000,
  dailyVolume: 0.2,
  iskPerDay: 90_000_000,
  materialCost: 2_500_000_000,
  importCost: 1_000_000,
  exportCost: 0,
  sellPrice: 3_000_000_000,
  meUsed: 10,
  activityType: 'manufacturing',
  isFactionBlueprint: false,
  bpcCostPerRun: null,
  hasAllSkills: true,
  missingSkillCount: 0,
  unknownReason: null,
  missingPriceTypeIds: [],
}

const CAPITAL_WITH_INDICATORS: BatchScanItem = {
  ...CAPITAL_BY_CONTRACT,
  contractSales: { estimatedSales: 12, sellThroughRate: 0.75, medianDaysToSell: 3.5, confidence: 'low' },
}

const CAPITAL_WITH_PARTIAL_INDICATORS: BatchScanItem = {
  ...CAPITAL_BY_CONTRACT,
  contractSales: { estimatedSales: 2, sellThroughRate: null, medianDaysToSell: null, confidence: 'medium' },
}

const CAPITAL_WITHOUT_CONTRACT_SALES: BatchScanItem = {
  ...CAPITAL_BY_CONTRACT,
  typeId: 19724,
  typeName: 'Moros',
  dailyVolume: null,
  iskPerDay: null,
  sellPrice: null,
  profitPerUnit: null,
  marginPercent: null,
  unknownReason: 'missing_sale_price',
}

const batchLabels = en.industry.scanner.batch

async function mountBatchScanOn(venue: string, items: BatchScanItem[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) =>
    endpoint === '/me/settings'
      ? { preferredMarketStructureId: 1_046_664_001_931, preferredMarketStructureName: 'Capital Hub', marketStructures: [{ id: 1_046_664_001_931, name: 'Capital Hub' }] }
      : [],
  )
  useScannerStore().scanResults = items
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BatchScanTab, {
    global: { plugins: [i18n], stubs: { ProductionCalculator: true } },
  })
  await flushPromises()
  Object.assign(wrapper.vm, { minMarginFilter: null, minDailyVolFilter: null, sellVenueFilter: venue })
  await flushPromises()
  return wrapper
}

function cellOf(wrapper: VueWrapper, typeName: string, headerLabel: string): DOMWrapper<Element> {
  const index = wrapper.findAll('thead th').findIndex(header => header.text() === headerLabel)
  expect(index, `column ${headerLabel}`).toBeGreaterThanOrEqual(0)
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName))
  expect(row, `row ${typeName}`).toBeDefined()
  return row!.findAll('td')[index]
}

function hasLabel(wrapper: VueWrapper, label: string): boolean {
  return wrapper.findAll('label').some(candidate => candidate.text() === label)
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BatchScanTab on the contracts sell venue', () => {
  it('names the contract sales in English and in French', () => {
    const frenchLabels = fr.industry.scanner.batch as Record<string, string>
    const englishLabels = batchLabels as Record<string, string>
    for (const key of ['contractMonthlySales', 'contractSalesNotice', 'contractSellThrough', 'contractMedianDays', 'contractConfidenceLow', 'contractConfidenceMedium']) {
      expect(englishLabels[key], key).toEqual(expect.any(String))
      expect(frenchLabels[key], key).toEqual(expect.any(String))
      expect(englishLabels[key]).not.toBe(frenchLabels[key])
    }
  })

  it('asks for the structure whose region is read, like the structure venue does', async () => {
    const wrapper = await mountBatchScanOn(CONTRACTS_VENUE, [CAPITAL_BY_CONTRACT])

    expect(hasLabel(wrapper, batchLabels.structure)).toBe(true)
  })

  it('shows the contract sales per month in place of the daily volume', async () => {
    const wrapper = await mountBatchScanOn(CONTRACTS_VENUE, [CAPITAL_BY_CONTRACT])

    const volumeCell = cellOf(wrapper, 'Revelation', batchLabels.dailyVol)
    const expectedMonthlySales = Math.round((CAPITAL_BY_CONTRACT.dailyVolume as number) * DAYS_PER_MONTH)
    expect(volumeCell.text()).toBe(`${expectedMonthlySales} / mo`)
  })

  it('warns that the sales are estimated from contracts that disappeared', async () => {
    const wrapper = await mountBatchScanOn(CONTRACTS_VENUE, [CAPITAL_BY_CONTRACT])

    expect(wrapper.text()).toContain(batchLabels.contractSalesNotice)
  })

  it('keeps an unknown contract sales rate as unknown, never as zero per month', async () => {
    const wrapper = await mountBatchScanOn(CONTRACTS_VENUE, [CAPITAL_WITHOUT_CONTRACT_SALES])

    const volumeCell = cellOf(wrapper, 'Moros', batchLabels.dailyVol)
    expect(volumeCell.text()).toBe(en.industry.inventionUnknown.label)
  })

  it('details the sell-through rate, the median days to sell and the confidence under the monthly sales', async () => {
    const wrapper = await mountBatchScanOn(CONTRACTS_VENUE, [CAPITAL_WITH_INDICATORS])

    const volumeText = cellOf(wrapper, 'Revelation', batchLabels.dailyVol).text()
    expect(volumeText).toContain('75')
    expect(volumeText).toMatch(/3[.,]5/)
    expect(volumeText).toContain(batchLabels.contractConfidenceLow)
  })

  it('shows the medium confidence of a type with a longer history', async () => {
    const wrapper = await mountBatchScanOn(CONTRACTS_VENUE, [CAPITAL_WITH_PARTIAL_INDICATORS])

    expect(cellOf(wrapper, 'Revelation', batchLabels.dailyVol).text()).toContain(batchLabels.contractConfidenceMedium)
  })

  it('keeps an unknown sell-through rate and median delay as unknown, never as zero', async () => {
    const wrapper = await mountBatchScanOn(CONTRACTS_VENUE, [CAPITAL_WITH_PARTIAL_INDICATORS])

    const volumeText = cellOf(wrapper, 'Revelation', batchLabels.dailyVol).text()
    expect(volumeText.split(en.industry.inventionUnknown.label)).toHaveLength(3)
    expect(volumeText).not.toContain('0 %')
  })

  it('shows no indicator detail for a type without indicators', async () => {
    const wrapper = await mountBatchScanOn(CONTRACTS_VENUE, [CAPITAL_BY_CONTRACT])

    expect(cellOf(wrapper, 'Revelation', batchLabels.dailyVol).text()).toBe('6 / mo')
  })

  it('shows no indicator detail outside the contracts venue', async () => {
    const wrapper = await mountBatchScanOn(JITA_VENUE, [{ ...CAPITAL_WITH_INDICATORS, dailyVolume: 75 }])

    expect(cellOf(wrapper, 'Revelation', batchLabels.dailyVol).text()).toBe('75')
  })

  it('keeps the daily volume and shows no contract notice on the Jita venue', async () => {
    const wrapper = await mountBatchScanOn(JITA_VENUE, [{ ...CAPITAL_BY_CONTRACT, dailyVolume: 75 }])

    expect(cellOf(wrapper, 'Revelation', batchLabels.dailyVol).text()).toBe('75')
    expect(wrapper.text()).not.toContain(batchLabels.contractSalesNotice)
    expect(hasLabel(wrapper, batchLabels.structure)).toBe(false)
  })
})

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import { apiRequest } from '@/services/api'
import { useStructuresStore } from '@/stores/industry/structures'
import type { BatchScanItem, StructureConfig } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const C_J6MT_STRUCTURE_ID = 1_049_588_174_021
const MIN_MARGIN_PERCENT = 5
const MIN_DAILY_VOLUME = 50
const SLASHER_TYPE_ID = 585
const RIFTER_TYPE_ID = 587
const BREACHER_TYPE_ID = 598
const DIAGNOSIS_SELECTOR = '[data-test="scan-diagnosis"]'
const RAITARU_HOME_STRUCTURE_ID = '0192f3a4-7b1c-7d2e-8f3a-4b5c6d7e8f91'
const TATARA_REACTIONS_STRUCTURE_ID = '0192f3a4-7b1c-7d2e-8f3a-4b5c6d7e8f92'
const UNLISTED_STRUCTURE_ID = '0192f3a4-7b1c-7d2e-8f3a-4b5c6d7e8f99'
const INDUSTRY_SETTINGS_PATH = '/settings/industry'

function scannedProduct(typeId: number, overrides: Partial<BatchScanItem> = {}): BatchScanItem {
  return {
    typeId,
    typeName: `Product ${typeId}`,
    groupName: 'Frigate',
    categoryLabel: 'T1 Ships',
    marginPercent: 12.5,
    profitPerUnit: 600_000,
    dailyVolume: 120,
    iskPerDay: 72_000_000,
    materialCost: 4_000_000,
    importCost: 2_450,
    exportCost: 0,
    sellPrice: 5_000_000,
    meUsed: 10,
    teUsed: 20,
    activityType: 'manufacturing',
    isFactionBlueprint: false,
    bpcCostPerRun: null,
    hasAllSkills: true,
    missingSkillCount: 0,
    unknownReason: null,
    unknownReasonStructureId: null,
    missingPriceTypeIds: [],
    ...overrides,
  }
}

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
const diagnosisKey = (name: string): string => `industry.scanner.batchDiagnosis.${name}`
const diagnosisText = (name: string, params: Record<string, number | string> = {}): string => i18n.global.t(diagnosisKey(name), params)

function configuredStructure(id: string, name: string): StructureConfig {
  return {
    id,
    name,
    locationId: null,
    solarSystemId: 30_002_510,
    securityType: 'nullsec',
    structureType: 'raitaru',
    rigs: [],
    isDefault: false,
    isCorporationStructure: false,
    manufacturingMaterialBonus: 1,
    reactionMaterialBonus: 0,
    manufacturingTimeBonus: 15,
    reactionTimeBonus: 0,
    facilityTaxRate: null,
    createdAt: '2026-11-01T10:00:00+00:00',
  }
}

async function mountScannerAnswering(filteredScan: unknown, unfilteredScan: unknown, structures: StructureConfig[] = []): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/me/settings') {
      return { preferredMarketStructureId: C_J6MT_STRUCTURE_ID, preferredMarketStructureName: 'C-J6MT', marketStructures: [{ id: C_J6MT_STRUCTURE_ID, name: 'C-J6MT' }] }
    }
    if (endpoint === '/industry/structures') {
      return { structures, rigOptions: null }
    }
    if (endpoint.startsWith('/industry/profit-scan')) {
      return endpoint.includes('minMargin') ? filteredScan : unfilteredScan
    }
    return []
  })
  useStructuresStore().structures = structures
  const EmptyView = { render: () => null }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: EmptyView },
      { path: '/settings/:section', name: 'settings', component: EmptyView },
    ],
  })
  const wrapper = mount(BatchScanTab, { global: { plugins: [i18n, router], stubs: { ProductionCalculator: true } } })
  await flushPromises()
  return wrapper
}

function diagnosisLines(wrapper: VueWrapper): string[] {
  return wrapper.find(DIAGNOSIS_SELECTOR).findAll('li').map(line => line.text())
}

function diagnosisLinesLinkingToTheIndustrySettings(wrapper: VueWrapper): string[] {
  return wrapper.find(DIAGNOSIS_SELECTOR).findAll('li')
    .filter(line => line.findAll('a').some(link => link.attributes('href') === INDUSTRY_SETTINGS_PATH))
    .map(line => line.text())
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('BatchScanTab empty result diagnosis (issue 884)', () => {
  it('says that no product has a daily volume for the venue region when the volumes are missing, without blaming the prices', async () => {
    const withoutVolume = { dailyVolume: null, iskPerDay: null, unknownReason: 'missing_daily_volume' }
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(SLASHER_TYPE_ID, withoutVolume),
      scannedProduct(RIFTER_TYPE_ID, withoutVolume),
      scannedProduct(BREACHER_TYPE_ID, withoutVolume),
    ])

    const text = wrapper.find(DIAGNOSIS_SELECTOR).text()
    expect(text).toContain(diagnosisText('scanned', { count: 3 }))
    expect(text).toContain(diagnosisText('noDailyVolume', { count: 3 }))
    expect(text).not.toContain(diagnosisText('noSalePrice', { count: 3 }))
    expect(text).not.toContain(diagnosisText('noMaterialPrice', { count: 3 }))
  })

  it('says that no product has a sell price at the venue when the structure market is empty', async () => {
    const withoutSalePrice = { sellPrice: null, marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_sale_price' }
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(SLASHER_TYPE_ID, withoutSalePrice),
      scannedProduct(RIFTER_TYPE_ID, withoutSalePrice),
    ])

    const text = wrapper.find(DIAGNOSIS_SELECTOR).text()
    expect(text).toContain(diagnosisText('noSalePrice', { count: 2 }))
    expect(text).not.toContain(diagnosisText('noDailyVolume', { count: 2 }))
  })

  it('says how many products fall under the minimum margin and the minimum daily volume, with those minimums', async () => {
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(SLASHER_TYPE_ID, { marginPercent: 2 }),
      scannedProduct(RIFTER_TYPE_ID, { dailyVolume: 10 }),
    ])

    const text = wrapper.find(DIAGNOSIS_SELECTOR).text()
    expect(text).toContain(diagnosisText('belowMargin', { count: 1, min: MIN_MARGIN_PERCENT }))
    expect(text).toContain(diagnosisText('belowVolume', { count: 1, min: MIN_DAILY_VOLUME }))
  })

  it('says that the scan returned no product at all when the scan without minimums is empty too', async () => {
    const wrapper = await mountScannerAnswering([], [])

    expect(wrapper.find(DIAGNOSIS_SELECTOR).text()).toContain(diagnosisText('nothingScanned'))
  })

  it('says that the diagnosis could not be loaded when the scan without minimums fails', async () => {
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === '/me/settings') {
        return { preferredMarketStructureId: C_J6MT_STRUCTURE_ID, preferredMarketStructureName: 'C-J6MT', marketStructures: [{ id: C_J6MT_STRUCTURE_ID, name: 'C-J6MT' }] }
      }
      if (endpoint.startsWith('/industry/profit-scan')) {
        if (endpoint.includes('minMargin')) return []
        throw new Error('Gateway timeout (fictif)')
      }
      return []
    })
    const wrapper = mount(BatchScanTab, { global: { plugins: [i18n], stubs: { ProductionCalculator: true } } })
    await flushPromises()

    expect(wrapper.find(DIAGNOSIS_SELECTOR).text()).toContain(diagnosisText('failed'))
  })

  it('shows no diagnosis when the scan returns products', async () => {
    const wrapper = await mountScannerAnswering([scannedProduct(SLASHER_TYPE_ID)], [])

    expect(wrapper.find(DIAGNOSIS_SELECTOR).exists()).toBe(false)
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
  })
})

describe('BatchScanTab empty result diagnosis of unknown margins (issue 884)', () => {
  it('names the structure without facility tax with its product count and links to the Industry settings, next to the other lines', async () => {
    const withoutFacilityTax = { marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_facility_tax', unknownReasonStructureId: RAITARU_HOME_STRUCTURE_ID }
    const withoutSalePrice = { sellPrice: null, marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_sale_price' }
    const withoutAdjustedPrice = { marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_adjusted_price' }
    const wrapper = await mountScannerAnswering([], [
      ...Array.from({ length: 12 }, (_, index) => scannedProduct(1000 + index, withoutFacilityTax)),
      ...Array.from({ length: 3 }, (_, index) => scannedProduct(2000 + index, withoutSalePrice)),
      ...Array.from({ length: 2 }, (_, index) => scannedProduct(3000 + index, withoutAdjustedPrice)),
    ], [configuredStructure(RAITARU_HOME_STRUCTURE_ID, 'Raitaru Home')])

    const text = wrapper.find(DIAGNOSIS_SELECTOR).text()
    expect(text).toContain(diagnosisText('scanned', { count: 17 }))
    expect(text).toContain(diagnosisText('noSalePrice', { count: 3 }))
    expect(text).toContain(diagnosisText('unknownAdjustedPrice', { count: 2 }))
    expect(text).toContain(diagnosisText('unknownFacilityTax', { count: 12, structure: 'Raitaru Home' }))
    const linkingLines = diagnosisLinesLinkingToTheIndustrySettings(wrapper)
    expect(linkingLines).toEqual([diagnosisText('unknownFacilityTax', { count: 12, structure: 'Raitaru Home' })])
    expect(linkingLines[0]).toContain('Raitaru Home')
    expect(linkingLines[0]).toContain('12')
  })

  it('gives one line per structure without facility tax', async () => {
    const onStructure = (structureId: string) => ({ marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_facility_tax', unknownReasonStructureId: structureId })
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(1001, onStructure(RAITARU_HOME_STRUCTURE_ID)),
      scannedProduct(1002, onStructure(RAITARU_HOME_STRUCTURE_ID)),
      scannedProduct(1003, onStructure(TATARA_REACTIONS_STRUCTURE_ID)),
      scannedProduct(1004, onStructure(TATARA_REACTIONS_STRUCTURE_ID)),
      scannedProduct(1005, onStructure(TATARA_REACTIONS_STRUCTURE_ID)),
    ], [
      configuredStructure(RAITARU_HOME_STRUCTURE_ID, 'Raitaru Home'),
      configuredStructure(TATARA_REACTIONS_STRUCTURE_ID, 'Tatara Reactions'),
    ])

    expect(diagnosisLinesLinkingToTheIndustrySettings(wrapper).sort()).toEqual([
      diagnosisText('unknownFacilityTax', { count: 2, structure: 'Raitaru Home' }),
      diagnosisText('unknownFacilityTax', { count: 3, structure: 'Tatara Reactions' }),
    ].sort())
  })

  it('gives a line without structure name but with the settings link when the structure is not in the configured structures', async () => {
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(1001, { marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_facility_tax', unknownReasonStructureId: UNLISTED_STRUCTURE_ID }),
      scannedProduct(1002, { marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_facility_tax', unknownReasonStructureId: UNLISTED_STRUCTURE_ID }),
    ], [configuredStructure(RAITARU_HOME_STRUCTURE_ID, 'Raitaru Home')])

    const linkingLines = diagnosisLinesLinkingToTheIndustrySettings(wrapper)
    expect(linkingLines).toEqual([diagnosisText('unknownFacilityTaxUnnamed', { count: 2 })])
    expect(linkingLines[0]).not.toContain('Raitaru Home')
    expect(linkingLines[0]).not.toContain(UNLISTED_STRUCTURE_ID)
  })

  it('says that the seller sales fees are unknown for every product when the standings or skills are not synchronized', async () => {
    const withoutSalesFees = { marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_sales_fees' }
    const wrapper = await mountScannerAnswering([], Array.from({ length: 10 }, (_, index) => scannedProduct(1000 + index, withoutSalesFees)))

    const text = wrapper.find(DIAGNOSIS_SELECTOR).text()
    expect(text).toContain(diagnosisText('scanned', { count: 10 }))
    expect(text).toContain(diagnosisText('unknownSalesFees', { count: 10 }))
  })

  it('gives its own line to the missing cost index and to the missing adjusted price', async () => {
    const unknown = { marginPercent: null, profitPerUnit: null, iskPerDay: null }
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(1001, { ...unknown, unknownReason: 'missing_cost_index' }),
      scannedProduct(1002, { ...unknown, unknownReason: 'missing_cost_index' }),
      scannedProduct(1003, { ...unknown, unknownReason: 'missing_adjusted_price' }),
      scannedProduct(1004, { ...unknown, unknownReason: 'missing_adjusted_price' }),
      scannedProduct(1005, { ...unknown, unknownReason: 'missing_adjusted_price' }),
    ])

    const lines = diagnosisLines(wrapper)
    expect(lines).toContain(diagnosisText('unknownCostIndex', { count: 2 }))
    expect(lines).toContain(diagnosisText('unknownAdjustedPrice', { count: 3 }))
  })

  it('gives its own line to the missing invention probability, market price and transport volume', async () => {
    const unknown = { marginPercent: null, profitPerUnit: null, iskPerDay: null }
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(1001, { ...unknown, unknownReason: 'missing_invention_probability' }),
      scannedProduct(1002, { ...unknown, unknownReason: 'missing_market_price' }),
      scannedProduct(1003, { ...unknown, unknownReason: 'missing_market_price' }),
      scannedProduct(1004, { ...unknown, unknownReason: 'missing_volume' }),
      scannedProduct(1005, { ...unknown, unknownReason: 'missing_volume' }),
      scannedProduct(1006, { ...unknown, unknownReason: 'missing_volume' }),
    ])

    const lines = diagnosisLines(wrapper)
    expect(lines).toContain(diagnosisText('unknownInventionProbability', { count: 1 }))
    expect(lines).toContain(diagnosisText('unknownMarketPrice', { count: 2 }))
    expect(lines).toContain(diagnosisText('unknownTransportVolume', { count: 3 }))
  })

  it('gives a generic line to a reason the front does not know', async () => {
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(1001, { marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_xyz' }),
    ])

    expect(diagnosisLines(wrapper)).toContain(diagnosisText('unknownOther', { count: 1 }))
  })

  it('adds no unknown margin line for the reasons the sale price, material price and daily volume lines already carry', async () => {
    const wrapper = await mountScannerAnswering([], [
      scannedProduct(1001, { sellPrice: null, marginPercent: null, profitPerUnit: null, iskPerDay: null, unknownReason: 'missing_sale_price' }),
      scannedProduct(1002, { materialCost: null, marginPercent: null, profitPerUnit: null, iskPerDay: null, missingPriceTypeIds: [34], unknownReason: 'missing_material_price' }),
      scannedProduct(1003, { dailyVolume: null, iskPerDay: null, unknownReason: 'missing_daily_volume' }),
    ])

    expect(diagnosisLines(wrapper)).toHaveLength(4)
    expect(diagnosisLinesLinkingToTheIndustrySettings(wrapper)).toEqual([])
  })
})

describe('scan diagnosis wording', () => {
  it('is written in English and in French for every line', () => {
    const englishLines: Record<string, string> = en.industry.scanner.batchDiagnosis
    const frenchLines: Record<string, string> = fr.industry.scanner.batchDiagnosis

    for (const [name, english] of Object.entries(englishLines)) {
      expect(frenchLines[name], name).toEqual(expect.any(String))
      expect(frenchLines[name], name).not.toBe(english)
    }
    expect(Object.keys(englishLines).sort()).toEqual(
      [
        'belowMargin', 'belowVolume', 'failed', 'nothingScanned', 'noDailyVolume', 'noMaterialPrice', 'noSalePrice', 'scanned', 'title',
        'unknownAdjustedPrice', 'unknownCostIndex', 'unknownFacilityTax', 'unknownFacilityTaxUnnamed', 'unknownInventionProbability',
        'unknownMarketPrice', 'unknownOther', 'unknownSalesFees', 'unknownTransportVolume',
      ].sort(),
    )
  })
})

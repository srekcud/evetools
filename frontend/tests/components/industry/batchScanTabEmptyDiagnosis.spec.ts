import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import { apiRequest } from '@/services/api'
import type { BatchScanItem } from '@/stores/industry/types'

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
    activityType: 'manufacturing',
    isFactionBlueprint: false,
    bpcCostPerRun: null,
    hasAllSkills: true,
    missingSkillCount: 0,
    unknownReason: null,
    missingPriceTypeIds: [],
    ...overrides,
  }
}

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
const diagnosisKey = (name: string): string => `industry.scanner.batchDiagnosis.${name}`
const diagnosisText = (name: string, params: Record<string, number> = {}): string => i18n.global.t(diagnosisKey(name), params)

async function mountScannerAnswering(filteredScan: unknown, unfilteredScan: unknown): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/me/settings') {
      return { preferredMarketStructureId: C_J6MT_STRUCTURE_ID, preferredMarketStructureName: 'C-J6MT', marketStructures: [{ id: C_J6MT_STRUCTURE_ID, name: 'C-J6MT' }] }
    }
    if (endpoint.startsWith('/industry/profit-scan')) {
      return endpoint.includes('minMargin') ? filteredScan : unfilteredScan
    }
    return []
  })
  const wrapper = mount(BatchScanTab, { global: { plugins: [i18n], stubs: { ProductionCalculator: true } } })
  await flushPromises()
  return wrapper
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

describe('scan diagnosis wording', () => {
  it('is written in English and in French for every line', () => {
    const englishLines: Record<string, string> = en.industry.scanner.batchDiagnosis
    const frenchLines: Record<string, string> = fr.industry.scanner.batchDiagnosis

    for (const [name, english] of Object.entries(englishLines)) {
      expect(frenchLines[name], name).toEqual(expect.any(String))
      expect(frenchLines[name], name).not.toBe(english)
    }
    expect(Object.keys(englishLines).sort()).toEqual(
      ['belowMargin', 'belowVolume', 'failed', 'nothingScanned', 'noDailyVolume', 'noMaterialPrice', 'noSalePrice', 'scanned', 'title'].sort(),
    )
  })
})

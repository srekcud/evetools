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
const SLASHER_TYPE_ID = 585
const DIAGNOSIS_SELECTOR = '[data-test="scan-diagnosis"]'
const STALE_FILTERS_SELECTOR = '[data-test="stale-filters"]'
const RESCAN_SELECTOR = '[data-test="rescan-stale-filters"]'
const LOWERED_MIN_MARGIN = 1
const LOWERED_MIN_DAILY_VOLUME = 1

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
    missingPriceTypeIds: [],
    ...overrides,
  }
}

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
const diagnosisKey = (name: string): string => `industry.scanner.batchDiagnosis.${name}`
const diagnosisText = (name: string, params: Record<string, number> = {}): string => i18n.global.t(diagnosisKey(name), params)

function scanRequests(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).filter(endpoint => endpoint.startsWith('/industry/profit-scan'))
}

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

describe('BatchScanTab results under filters changed since the last scan (issue 992)', () => {
  it('shows no stale filters notice right after a scan, whatever the diagnosis', async () => {
    const wrapper = await mountScannerAnswering([], [scannedProduct(SLASHER_TYPE_ID, { marginPercent: 0.5 })])

    expect(wrapper.find(STALE_FILTERS_SELECTOR).exists()).toBe(false)
    expect(wrapper.find(DIAGNOSIS_SELECTOR).exists()).toBe(true)
  })

  it('says the results come from the previous filters and hides the diagnosis once a minimum is lowered', async () => {
    const wrapper = await mountScannerAnswering([], [scannedProduct(SLASHER_TYPE_ID, { marginPercent: 0.5 })])

    await wrapper.find('[id$="-min-margin"]').setValue(LOWERED_MIN_MARGIN)

    expect(wrapper.find(STALE_FILTERS_SELECTOR).text()).toContain(i18n.global.t('industry.scanner.batch.staleFilters'))
    expect(wrapper.find(DIAGNOSIS_SELECTOR).exists()).toBe(false)
  })

  it('says the results come from the previous filters when the category changes', async () => {
    const wrapper = await mountScannerAnswering([scannedProduct(SLASHER_TYPE_ID)], [])

    await wrapper.find('[id$="-category"]').setValue('drones')

    expect(wrapper.find(STALE_FILTERS_SELECTOR).exists()).toBe(true)
  })

  it('says the results come from the previous filters when the minimum daily volume changes', async () => {
    const wrapper = await mountScannerAnswering([scannedProduct(SLASHER_TYPE_ID)], [])

    await wrapper.find('[id$="-min-daily-vol"]').setValue(LOWERED_MIN_DAILY_VOLUME)

    expect(wrapper.find(STALE_FILTERS_SELECTOR).exists()).toBe(true)
  })

  it('hides the notice when the filters come back to the ones of the scan', async () => {
    const wrapper = await mountScannerAnswering([scannedProduct(SLASHER_TYPE_ID)], [])

    await wrapper.find('[id$="-min-margin"]').setValue(LOWERED_MIN_MARGIN)
    await wrapper.find('[id$="-min-margin"]').setValue(MIN_MARGIN_PERCENT)

    expect(wrapper.find(STALE_FILTERS_SELECTOR).exists()).toBe(false)
  })

  it('scans again with the current filters from the notice and then shows no notice and the diagnosis of those filters', async () => {
    const wrapper = await mountScannerAnswering([], [scannedProduct(SLASHER_TYPE_ID, { marginPercent: 0.5 })])
    await wrapper.find('[id$="-min-margin"]').setValue(LOWERED_MIN_MARGIN)

    await wrapper.find(RESCAN_SELECTOR).trigger('click')
    await flushPromises()

    expect(scanRequests().at(-2)).toContain(`minMargin=${LOWERED_MIN_MARGIN}`)
    expect(wrapper.find(STALE_FILTERS_SELECTOR).exists()).toBe(false)
    expect(wrapper.find(DIAGNOSIS_SELECTOR).text()).toContain(diagnosisText('belowMargin', { count: 1, min: LOWERED_MIN_MARGIN }))
  })
})

describe('stale filters wording', () => {
  it('is written in English and in French', () => {
    for (const name of ['staleFilters', 'rescanStaleFilters']) {
      const english: string = (en.industry.scanner.batch as Record<string, string>)[name]
      const french: string = (fr.industry.scanner.batch as Record<string, string>)[name]
      expect(english, name).toEqual(expect.any(String))
      expect(french, name).toEqual(expect.any(String))
      expect(french, name).not.toBe(english)
    }
  })
})

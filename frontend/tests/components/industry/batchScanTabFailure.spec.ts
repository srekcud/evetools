import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import { apiRequest } from '@/services/api'
import type { BatchScanItem } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROFIT_SCAN_PATH = '/industry/profit-scan'
const SCAN_TIMEOUT = 'Scan timeout (fictif)'
const SCANNED_NAMES = ['Rifter', 'Merlin', 'Tristan']

function scanItem(typeId: number, typeName: string, marginPercent: number, dailyVolume: number, sellPrice: number): BatchScanItem {
  return {
    typeId,
    typeName,
    groupName: 'Frigate',
    categoryLabel: 'T1 Ships',
    marginPercent,
    profitPerUnit: Math.round(sellPrice * marginPercent / 100),
    dailyVolume,
    iskPerDay: Math.round(sellPrice * marginPercent / 100) * dailyVolume,
    materialCost: Math.round(sellPrice * (1 - marginPercent / 100)),
    importCost: 2_450,
    exportCost: 25_000,
    sellPrice,
    meUsed: 10,
    activityType: 'manufacturing',
    isFactionBlueprint: false,
    bpcCostPerRun: null,
    hasAllSkills: true,
    missingSkillCount: 0,
    unknownReason: null,
    missingPriceTypeIds: [],
  }
}

const RIFTER = scanItem(587, 'Rifter', 21.5, 75, 520_000)
const MERLIN = scanItem(603, 'Merlin', 18.4, 64, 610_000)
const TRISTAN = scanItem(593, 'Tristan', 16.2, 91, 705_000)

const outcomes = apiOutcomesByPath()

function shownScannedNames(wrapper: VueWrapper): string[] {
  return SCANNED_NAMES.filter(name => wrapper.findAll('tbody tr').some(row => row.text().includes(name)))
}

function redErrorBoxes(wrapper: VueWrapper): string[] {
  return wrapper.findAll('div').filter(box => box.classes('bg-red-500/20')).map(box => box.text())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/me/settings') return { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] }
    if (endpoint === '/industry/bpc-prices' || endpoint === '/industry/scanner-favorites') return []
    const answer = outcomes.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

describe('BatchScanTab scan failure (issue 189)', () => {
  it('shows the failure of the scan with new filters without the products of the previous scan', async () => {
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([RIFTER, MERLIN, TRISTAN]))
    outcomes.respondTo(PROFIT_SCAN_PATH, rejectWith(new Error(SCAN_TIMEOUT)))
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(BatchScanTab, {
      global: { plugins: [i18n], stubs: { ProductionCalculator: true } },
    })
    await flushPromises()
    const shownAfterFirstScan = shownScannedNames(wrapper)
    Object.assign(wrapper.vm, { minMarginFilter: 15 })
    await flushPromises()

    const scanButton = wrapper.findAll('button').find(button => button.text() === en.industry.scanner.batch.scanNow)
    expect(scanButton, 'scan button').toBeDefined()
    await scanButton!.trigger('click')
    await flushPromises()

    expect({ shownAfterFirstScan, errorBoxes: redErrorBoxes(wrapper), shownAfterFailure: shownScannedNames(wrapper) }).toEqual({
      shownAfterFirstScan: SCANNED_NAMES,
      errorBoxes: [SCAN_TIMEOUT],
      shownAfterFailure: [],
    })
  })
})

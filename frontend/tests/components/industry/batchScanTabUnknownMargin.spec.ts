import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import { apiRequest } from '@/services/api'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BatchScanItem } from '@/stores/industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROFIT_SCAN_PATH = '/industry/profit-scan'
const MIN_MARGIN_PARAMETER = 'minMargin'

function scanItem(typeId: number, typeName: string, marginPercent: number | null): BatchScanItem {
  return {
    typeId,
    typeName,
    groupName: 'Frigate',
    categoryLabel: 'T1 Ships',
    marginPercent,
    profitPerUnit: marginPercent === null ? null : 96_000,
    dailyVolume: 80,
    iskPerDay: marginPercent === null ? null : marginPercent * 1_000_000,
    materialCost: 380_000,
    importCost: marginPercent === null ? null : 2_450,
    exportCost: 25_000,
    sellPrice: 520_000,
    meUsed: 10,
    activityType: 'manufacturing',
    isFactionBlueprint: false,
    bpcCostPerRun: null,
    hasAllSkills: true,
    missingSkillCount: 0,
    unknownReason: marginPercent === null ? 'missing_volume' : null,
    missingPriceTypeIds: [],
  }
}

const RIFTER = scanItem(587, 'Rifter', 21.5)
const BURST = scanItem(599, 'Burst', 2.5)
const SLASHER_WITH_UNKNOWN_MARGIN = scanItem(585, 'Slasher', null)
const SCANNED_NAMES = ['Rifter', 'Burst', 'Slasher']

const batchLabels = en.industry.scanner.batch

function shownNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('tbody tr')
    .map(row => SCANNED_NAMES.find(name => row.text().includes(name)) ?? '')
}

async function mountBatchScan(): Promise<VueWrapper> {
  const scannedProducts = [BURST, SLASHER_WITH_UNKNOWN_MARGIN, RIFTER]
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) =>
    endpoint === '/me/settings' ? { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] } : scannedProducts,
  )
  useScannerStore().scanResults = scannedProducts
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(BatchScanTab, {
    global: { plugins: [i18n], stubs: { ProductionCalculator: true } },
  })
  await flushPromises()
  return wrapper
}

async function clearMinMargin(wrapper: VueWrapper): Promise<void> {
  const minMarginInput = wrapper.findAll('label')
    .find(label => label.text() === batchLabels.minMargin)
  expect(minMarginInput, 'min margin label').toBeDefined()
  await wrapper.find(`#${present(minMarginInput).attributes('for')}`).setValue('')
  await flushPromises()
}

async function clickScan(wrapper: VueWrapper): Promise<void> {
  const scanButton = wrapper.findAll('button').find(button => button.text() === batchLabels.scanNow)
  expect(scanButton, 'scan button').toBeDefined()
  await present(scanButton).trigger('click')
  await flushPromises()
}

function scanUrls(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([endpoint]) => String(endpoint))
    .filter(endpoint => endpoint.startsWith(PROFIT_SCAN_PATH))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('BatchScanTab minimum margin filter with an unknown margin (issue 256)', () => {
  it('shows the products with an unknown margin, last in the margin sort, once the minimum margin field is emptied', async () => {
    const wrapper = await mountBatchScan()

    await clearMinMargin(wrapper)

    expect(shownNames(wrapper)).toEqual(['Rifter', 'Burst', 'Slasher'])
  })

  it('does not send a minimum margin to the scan once the minimum margin field is emptied', async () => {
    const wrapper = await mountBatchScan()
    await clearMinMargin(wrapper)

    await clickScan(wrapper)

    const urls = scanUrls()
    expect(urls).toHaveLength(1)
    expect(urls[0]).not.toContain(MIN_MARGIN_PARAMETER)
  })

  it('keeps hiding the products under the default minimum margin of 5 and the products with an unknown margin', async () => {
    const wrapper = await mountBatchScan()

    expect(shownNames(wrapper)).toEqual(['Rifter'])
  })

  it('sends the default minimum margin of 5 to the scan', async () => {
    const wrapper = await mountBatchScan()

    await clickScan(wrapper)

    const urls = scanUrls()
    expect(urls).toHaveLength(1)
    expect(urls[0]).toContain(`${MIN_MARGIN_PARAMETER}=5`)
  })
})

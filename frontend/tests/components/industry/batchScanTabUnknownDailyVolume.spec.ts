import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import { apiRequest } from '@/services/api'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BatchScanItem } from '@/stores/industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SLASHER_TYPE_ID = 585
const RIFTER_TYPE_ID = 587
const BREACHER_TYPE_ID = 598

const SLASHER_WITHOUT_DAILY_VOLUME: BatchScanItem = {
  typeId: SLASHER_TYPE_ID,
  typeName: 'Slasher',
  groupName: 'Frigate',
  categoryLabel: 'T1 Ships',
  marginPercent: 12.5,
  profitPerUnit: 600_000,
  dailyVolume: null,
  iskPerDay: null,
  materialCost: 4_000_000,
  importCost: 2_450,
  exportCost: 25_000,
  sellPrice: 5_000_000,
  meUsed: 10,
  activityType: 'manufacturing',
  isFactionBlueprint: false,
  bpcCostPerRun: null,
  hasAllSkills: true,
  missingSkillCount: 0,
  unknownReason: 'missing_daily_volume',
  missingPriceTypeIds: [],
}

const RIFTER_WITH_DAILY_VOLUME: BatchScanItem = {
  typeId: RIFTER_TYPE_ID,
  typeName: 'Rifter',
  groupName: 'Frigate',
  categoryLabel: 'T1 Ships',
  marginPercent: 21.5,
  profitPerUnit: 96_000,
  dailyVolume: 75,
  iskPerDay: 7_200_000,
  materialCost: 380_000,
  importCost: 2_450,
  exportCost: 25_000,
  sellPrice: 520_000,
  meUsed: 10,
  activityType: 'manufacturing',
  isFactionBlueprint: false,
  bpcCostPerRun: null,
  hasAllSkills: true,
  missingSkillCount: 0,
  unknownReason: null,
  missingPriceTypeIds: [],
}

const BREACHER_WITH_ZERO_DAILY_VOLUME: BatchScanItem = {
  ...RIFTER_WITH_DAILY_VOLUME,
  typeId: BREACHER_TYPE_ID,
  typeName: 'Breacher',
  dailyVolume: 0,
  iskPerDay: 0,
}

const batchLabels = en.industry.scanner.batch
const unknownLabels: Record<string, string> = en.industry.inventionUnknown
const frenchUnknownLabels: Record<string, string> = fr.industry.inventionUnknown

async function mountBatchScanShowing(items: BatchScanItem[], minDailyVolFilter: number | null = null): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) =>
    endpoint === '/me/settings' ? { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] } : [],
  )
  useScannerStore().scanResults = items
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BatchScanTab, {
    global: { plugins: [i18n], stubs: { ProductionCalculator: true } },
  })
  await flushPromises()
  Object.assign(wrapper.vm, { minMarginFilter: null, minDailyVolFilter })
  await flushPromises()
  return wrapper
}

function cellOf(wrapper: VueWrapper, typeName: string, headerLabel: string): DOMWrapper<Element> {
  const index = wrapper.findAll('thead th').findIndex(header => header.text() === headerLabel)
  expect(index, `column ${headerLabel}`).toBeGreaterThanOrEqual(0)
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName))
  expect(row, `row ${typeName}`).toBeDefined()
  return present(row).findAll('td')[index]
}

function rowNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('tbody tr').map(row => [SLASHER_WITHOUT_DAILY_VOLUME, RIFTER_WITH_DAILY_VOLUME, BREACHER_WITH_ZERO_DAILY_VOLUME]
    .map(item => item.typeName as string)
    .find(name => row.text().includes(name)) ?? '')
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BatchScanTab with a product without daily volume', () => {
  it('names the missing daily volume reason in English and in French', () => {
    expect(unknownLabels.missing_daily_volume).toEqual(expect.any(String))
    expect(frenchUnknownLabels.missing_daily_volume).toEqual(expect.any(String))
    expect(unknownLabels.missing_daily_volume).not.toBe(frenchUnknownLabels.missing_daily_volume)
  })

  it('shows an unknown daily volume as unknown with its reason, never as zero', async () => {
    const wrapper = await mountBatchScanShowing([SLASHER_WITHOUT_DAILY_VOLUME])

    const volumeCell = cellOf(wrapper, 'Slasher', batchLabels.dailyVol)
    expect(volumeCell.text()).toBe(unknownLabels.label)
    expect(volumeCell.classes()).toContain('text-amber-400')
    expect(volumeCell.attributes('title')).toBe(unknownLabels.missing_daily_volume)
  })

  it('shows the ISK per day of that product as unknown with the same reason next to its known profit', async () => {
    const wrapper = await mountBatchScanShowing([SLASHER_WITHOUT_DAILY_VOLUME])

    const iskPerDayCell = cellOf(wrapper, 'Slasher', batchLabels.estIskDay)
    expect(iskPerDayCell.text()).toBe(unknownLabels.label)
    expect(iskPerDayCell.attributes('title')).toBe(unknownLabels.missing_daily_volume)
    expect(cellOf(wrapper, 'Slasher', batchLabels.profitUnit).text()).toBe('600.00 K')
  })

  it('keeps a known daily volume and a known zero daily volume as numbers', async () => {
    const wrapper = await mountBatchScanShowing([RIFTER_WITH_DAILY_VOLUME, BREACHER_WITH_ZERO_DAILY_VOLUME])

    expect(cellOf(wrapper, 'Rifter', batchLabels.dailyVol).text()).toBe('75')
    expect(cellOf(wrapper, 'Breacher', batchLabels.dailyVol).text()).toBe('0')
  })

  it('excludes the product with an unknown daily volume from a minimum daily volume filter', async () => {
    const wrapper = await mountBatchScanShowing([SLASHER_WITHOUT_DAILY_VOLUME, RIFTER_WITH_DAILY_VOLUME], 50)

    expect(rowNames(wrapper)).toEqual(['Rifter'])
  })

  it('keeps the product with an unknown daily volume last whatever the sort direction of the daily volume column', async () => {
    const wrapper = await mountBatchScanShowing([SLASHER_WITHOUT_DAILY_VOLUME, RIFTER_WITH_DAILY_VOLUME])
    const volumeHeader = present(wrapper.findAll('thead th').find(header => header.text() === batchLabels.dailyVol))

    await volumeHeader.trigger('click')
    const descending = rowNames(wrapper)
    await volumeHeader.trigger('click')
    const ascending = rowNames(wrapper)

    expect([descending, ascending]).toEqual([['Rifter', 'Slasher'], ['Rifter', 'Slasher']])
  })
})

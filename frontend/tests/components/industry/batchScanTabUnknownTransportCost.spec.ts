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

const SLASHER_TYPE_ID = 585
const ROBOTICS_TYPE_ID = 9848
const RIFTER_TYPE_ID = 587

const SLASHER_WITH_UNKNOWN_IMPORT_COST: BatchScanItem = {
  typeId: SLASHER_TYPE_ID,
  typeName: 'Slasher',
  groupName: 'Frigate',
  categoryLabel: 'T1 Ships',
  marginPercent: null,
  profitPerUnit: null,
  dailyVolume: 42,
  iskPerDay: null,
  materialCost: 412_000,
  importCost: null,
  exportCost: 3_000_000,
  sellPrice: 4_800_000,
  meUsed: 10,
  activityType: 'manufacturing',
  isFactionBlueprint: false,
  bpcCostPerRun: null,
  hasAllSkills: true,
  missingSkillCount: 0,
  unknownReason: 'missing_volume',
  missingPriceTypeIds: [],
}

const ROBOTICS_WITH_UNKNOWN_EXPORT_COST: BatchScanItem = {
  typeId: ROBOTICS_TYPE_ID,
  typeName: 'Robotics',
  groupName: 'Construction Components',
  categoryLabel: 'Components',
  marginPercent: null,
  profitPerUnit: null,
  dailyVolume: 310,
  iskPerDay: null,
  materialCost: 31_750,
  importCost: 7_380,
  exportCost: null,
  sellPrice: 39_500,
  meUsed: 10,
  activityType: 'manufacturing',
  isFactionBlueprint: false,
  bpcCostPerRun: null,
  hasAllSkills: true,
  missingSkillCount: 0,
  unknownReason: 'missing_volume',
  missingPriceTypeIds: [],
}

const RIFTER_WITH_EVERY_VOLUME_KNOWN: BatchScanItem = {
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

const batchLabels = en.industry.scanner.batch
const unknownLabels: Record<string, string> = en.industry.inventionUnknown
const frenchUnknownLabels: Record<string, string> = fr.industry.inventionUnknown

async function mountBatchScanShowing(items: BatchScanItem[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) =>
    endpoint === '/me/settings' ? { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] } : [],
  )
  useScannerStore().scanResults = items
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BatchScanTab, {
    global: { plugins: [i18n], stubs: { ProductionCalculator: true } },
  })
  await flushPromises()
  Object.assign(wrapper.vm, { minMarginFilter: null, minDailyVolFilter: null })
  await flushPromises()
  return wrapper
}

function columnIndex(wrapper: VueWrapper, headerLabel: string): number {
  const index = wrapper.findAll('thead th').findIndex(header => header.text() === headerLabel)
  expect(index, `column ${headerLabel}`).toBeGreaterThanOrEqual(0)
  return index
}

function cellOf(wrapper: VueWrapper, typeName: string, headerLabel: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName))
  expect(row, `row ${typeName}`).toBeDefined()
  return row!.findAll('td')[columnIndex(wrapper, headerLabel)]
}

function rowNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('tbody tr').map(row => [SLASHER_WITH_UNKNOWN_IMPORT_COST, ROBOTICS_WITH_UNKNOWN_EXPORT_COST, RIFTER_WITH_EVERY_VOLUME_KNOWN]
    .map(item => item.typeName as string)
    .find(name => row.text().includes(name)) ?? '')
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BatchScanTab with a type without SDE volume', () => {
  it('names the missing volume reason in English and in French', () => {
    expect(unknownLabels.missing_volume).toEqual(expect.any(String))
    expect(frenchUnknownLabels.missing_volume).toEqual(expect.any(String))
    expect(unknownLabels.missing_volume).not.toBe(frenchUnknownLabels.missing_volume)
  })

  it('shows an unknown import cost as unknown with the missing volume reason, never as zero, next to the known export cost', async () => {
    const wrapper = await mountBatchScanShowing([SLASHER_WITH_UNKNOWN_IMPORT_COST])

    const importCell = cellOf(wrapper, 'Slasher', batchLabels.importCost)
    expect(importCell.text()).toBe(unknownLabels.label)
    expect(importCell.classes()).toContain('text-amber-400')
    expect(importCell.attributes('title')).toBe(unknownLabels.missing_volume)
    expect(cellOf(wrapper, 'Slasher', batchLabels.exportCost).text()).toBe('3.00 M')
    expect(cellOf(wrapper, 'Slasher', batchLabels.matCost).text()).toBe('412.00 K')
  })

  it('shows an unknown export cost as unknown with the missing volume reason, never as zero, next to the known import cost', async () => {
    const wrapper = await mountBatchScanShowing([ROBOTICS_WITH_UNKNOWN_EXPORT_COST])

    const exportCell = cellOf(wrapper, 'Robotics', batchLabels.exportCost)
    expect(exportCell.text()).toBe(unknownLabels.label)
    expect(exportCell.classes()).toContain('text-amber-400')
    expect(exportCell.attributes('title')).toBe(unknownLabels.missing_volume)
    expect(cellOf(wrapper, 'Robotics', batchLabels.importCost).text()).toBe('7.38 K')
  })

  it('keeps the products with an unknown transport cost last whatever the sort direction of the transport cost columns', async () => {
    const wrapper = await mountBatchScanShowing([SLASHER_WITH_UNKNOWN_IMPORT_COST, RIFTER_WITH_EVERY_VOLUME_KNOWN])
    const importHeader = wrapper.findAll('thead th')[columnIndex(wrapper, batchLabels.importCost)]

    await importHeader.trigger('click')
    const descending = rowNames(wrapper)
    await importHeader.trigger('click')
    const ascending = rowNames(wrapper)

    expect([descending, ascending]).toEqual([['Rifter', 'Slasher'], ['Rifter', 'Slasher']])
  })
})

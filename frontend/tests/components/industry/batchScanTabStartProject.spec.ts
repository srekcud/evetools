import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import { apiRequest } from '@/services/api'
import { useIndustryStore } from '@/stores/industry'
import { useBlueprintsStore } from '@/stores/industry/blueprints'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BatchScanItem, BlueprintLibraryEntry } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type ScannedProduct = BatchScanItem & { teUsed: number }

const DRAKE_TYPE_ID = 24698
const HAMMERHEAD_TYPE_ID = 2185
const CARBONIDE_TYPE_ID = 16670
const VEXOR_TYPE_ID = 626
const SLASHER_TYPE_ID = 585
const LIBRARY_ERROR = 'Blueprint library unavailable'

function scannedProduct(typeId: number, overrides: Partial<ScannedProduct>): ScannedProduct {
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

const DRAKE = scannedProduct(DRAKE_TYPE_ID, { typeName: 'Drake', marginPercent: 10, iskPerDay: 90_000_000, meUsed: 10, teUsed: 20 })
const HAMMERHEAD = scannedProduct(HAMMERHEAD_TYPE_ID, { typeName: 'Hammerhead II', categoryLabel: 'T2 Ships', marginPercent: 30, iskPerDay: 70_000_000, meUsed: 2, teUsed: 4 })
const CARBONIDE = scannedProduct(CARBONIDE_TYPE_ID, { typeName: 'Crystalline Carbonide', categoryLabel: 'Reactions', activityType: 'reaction', marginPercent: 20, iskPerDay: 50_000_000, meUsed: 0, teUsed: 0 })
const VEXOR = scannedProduct(VEXOR_TYPE_ID, { typeName: 'Vexor', marginPercent: 15, iskPerDay: 30_000_000, meUsed: 10, teUsed: 20 })
const SLASHER_BELOW_MINIMUM_MARGIN = scannedProduct(SLASHER_TYPE_ID, { typeName: 'Slasher', marginPercent: 1, iskPerDay: 10_000_000 })
const SCANNER_ROWS = [DRAKE, HAMMERHEAD, CARBONIDE, VEXOR, SLASHER_BELOW_MINIMUM_MARGIN]

function blueprint(productTypeId: number, overrides: Partial<BlueprintLibraryEntry>): BlueprintLibraryEntry {
  return {
    typeId: productTypeId + 1_000_000, name: 'Blueprint', kind: 'bpo', me: 10, te: 20, runs: null,
    scope: 'personal', ownerName: 'Pilot Four', locationId: 60003760, divisionNumber: null, divisionName: null, count: 1,
    usedByRunningJob: false, productTypeId, productName: null, ...overrides,
  }
}

const rowCheckbox = (typeId: number): string => `[data-testid="scan-select-row-${typeId}"]`
const SELECT_ALL = '[data-testid="scan-select-all"]'
const START_PROJECT = '[data-testid="scan-start-project"]'
const START_PROJECT_ERROR = '[data-testid="scan-start-project-error"]'

function library(blueprints: BlueprintLibraryEntry[]): { corporationState: 'known'; blueprints: BlueprintLibraryEntry[] } {
  return { corporationState: 'known', blueprints }
}

async function mountScanner(options: { rows?: ScannedProduct[]; storedLibrary?: BlueprintLibraryEntry[] | null; fetchedLibrary?: BlueprintLibraryEntry[]; libraryFailure?: string } = {}): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/me/settings') return { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] }
    if (endpoint === '/industry/blueprints') {
      if (options.libraryFailure !== undefined) throw new Error(options.libraryFailure)
      return library(options.fetchedLibrary ?? [])
    }
    return []
  })
  useScannerStore().scanResults = options.rows ?? SCANNER_ROWS
  useIndustryStore().navigationIntent = null
  if (options.storedLibrary) useBlueprintsStore().library = library(options.storedLibrary)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(BatchScanTab, { global: { plugins: [i18n], stubs: { ProductionCalculator: true } } })
  await flushPromises()
  return wrapper
}

async function tick(wrapper: VueWrapper, typeId: number): Promise<void> {
  const checkbox = wrapper.find(rowCheckbox(typeId))
  expect(checkbox.exists()).toBe(true)
  await checkbox.trigger('click')
}

async function startProject(wrapper: VueWrapper): Promise<void> {
  const button = wrapper.find(START_PROJECT)
  expect(button.exists()).toBe(true)
  await button.trigger('click')
  await flushPromises()
}

async function startProjectFor(typeId: number, options: Parameters<typeof mountScanner>[0]): Promise<unknown> {
  const wrapper = await mountScanner(options)
  await tick(wrapper, typeId)
  await startProject(wrapper)
  return useIndustryStore().navigationIntent
}

function projectIntent(...prefillItems: unknown[]): unknown {
  return { target: 'projects', prefillItems }
}

enableAutoUnmount(afterEach)

describe('BatchScanTab selection to a draft project (issue 1003)', () => {
  it('fetches the library on click when absent and pre-fills the owned original with its efficiencies', async () => {
    const intent = await startProjectFor(DRAKE_TYPE_ID, { fetchedLibrary: [blueprint(DRAKE_TYPE_ID, { kind: 'bpo', me: 10, te: 20 })] })

    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === '/industry/blueprints')).toHaveLength(1)
    expect(intent).toEqual(projectIntent({ typeId: DRAKE_TYPE_ID, typeName: 'Drake', meLevel: 10, teLevel: 20, runs: 1, blueprintSource: 'owned' }))
  })

  it('falls back to the scanner assumption of a tech two product without owned blueprint', async () => {
    const intent = await startProjectFor(HAMMERHEAD_TYPE_ID, { storedLibrary: [] })

    expect(intent).toEqual(projectIntent({ typeId: HAMMERHEAD_TYPE_ID, typeName: 'Hammerhead II', meLevel: 2, teLevel: 4, runs: 1, blueprintSource: 'assumed' }))
  })

  it('falls back to no efficiency for a reaction without owned formula', async () => {
    const intent = await startProjectFor(CARBONIDE_TYPE_ID, { storedLibrary: [] })

    expect(intent).toEqual(projectIntent({ typeId: CARBONIDE_TYPE_ID, typeName: 'Crystalline Carbonide', meLevel: 0, teLevel: 0, runs: 1, blueprintSource: 'assumed' }))
  })

  it('prefers the original over a copy with better efficiencies and keeps one run', async () => {
    const intent = await startProjectFor(DRAKE_TYPE_ID, {
      storedLibrary: [
        blueprint(DRAKE_TYPE_ID, { kind: 'bpc', me: 10, te: 20, runs: 30 }),
        blueprint(DRAKE_TYPE_ID, { kind: 'bpo', me: 5, te: 10 }),
      ],
    })

    expect(intent).toEqual(projectIntent({ typeId: DRAKE_TYPE_ID, typeName: 'Drake', meLevel: 5, teLevel: 10, runs: 1, blueprintSource: 'owned' }))
  })

  it('takes the copy with the best material efficiency and its runs', async () => {
    const intent = await startProjectFor(HAMMERHEAD_TYPE_ID, {
      storedLibrary: [
        blueprint(HAMMERHEAD_TYPE_ID, { kind: 'bpc', me: 8, te: 16, runs: 20 }),
        blueprint(HAMMERHEAD_TYPE_ID, { kind: 'bpc', me: 10, te: 20, runs: 5 }),
      ],
    })

    expect(intent).toEqual(projectIntent({ typeId: HAMMERHEAD_TYPE_ID, typeName: 'Hammerhead II', meLevel: 10, teLevel: 20, runs: 5, blueprintSource: 'owned' }))
  })

  it('uses one run for a copy whose runs are unknown', async () => {
    const intent = await startProjectFor(DRAKE_TYPE_ID, { storedLibrary: [blueprint(DRAKE_TYPE_ID, { kind: 'bpc', me: 10, te: 20, runs: null })] })

    expect(intent).toEqual(projectIntent({ typeId: DRAKE_TYPE_ID, typeName: 'Drake', meLevel: 10, teLevel: 20, runs: 1, blueprintSource: 'owned' }))
  })

  it('creates a single line for the same original owned by the pilot and by the corporation', async () => {
    const intent = await startProjectFor(DRAKE_TYPE_ID, {
      storedLibrary: [
        blueprint(DRAKE_TYPE_ID, { kind: 'bpo', me: 10, te: 20, scope: 'personal' }),
        blueprint(DRAKE_TYPE_ID, { kind: 'bpo', me: 10, te: 20, scope: 'corporation', ownerName: 'Corp Four' }),
      ],
    })

    expect(intent).toEqual(projectIntent({ typeId: DRAKE_TYPE_ID, typeName: 'Drake', meLevel: 10, teLevel: 20, runs: 1, blueprintSource: 'owned' }))
  })

  it('sends one item per ticked row in the displayed order and nothing else', async () => {
    const wrapper = await mountScanner({ storedLibrary: [] })
    expect(wrapper.findAll('tbody tr')).toHaveLength(4)

    await tick(wrapper, VEXOR_TYPE_ID)
    await tick(wrapper, DRAKE_TYPE_ID)
    await tick(wrapper, CARBONIDE_TYPE_ID)
    expect(useIndustryStore().navigationIntent).toBeNull()
    expect(wrapper.find(START_PROJECT).text()).toMatch(/\b3\b/)
    await startProject(wrapper)

    expect(useIndustryStore().navigationIntent).toEqual(projectIntent(
      { typeId: DRAKE_TYPE_ID, typeName: 'Drake', meLevel: 10, teLevel: 20, runs: 1, blueprintSource: 'assumed' },
      { typeId: CARBONIDE_TYPE_ID, typeName: 'Crystalline Carbonide', meLevel: 0, teLevel: 0, runs: 1, blueprintSource: 'assumed' },
      { typeId: VEXOR_TYPE_ID, typeName: 'Vexor', meLevel: 10, teLevel: 20, runs: 1, blueprintSource: 'assumed' },
    ))
  })

  it('disables the start button while no row is ticked', async () => {
    const wrapper = await mountScanner({ storedLibrary: [] })

    expect(wrapper.find(START_PROJECT).exists()).toBe(true)
    expect(wrapper.find(START_PROJECT).attributes('disabled')).toBeDefined()
  })

  it('keeps the ticked rows when the results are sorted by margin', async () => {
    const wrapper = await mountScanner({ storedLibrary: [] })
    await tick(wrapper, DRAKE_TYPE_ID)

    const marginHeader = wrapper.findAll('th').find(header => header.text().includes(en.industry.scanner.batch.marginPercent))
    await marginHeader?.trigger('click')

    expect((wrapper.find(rowCheckbox(DRAKE_TYPE_ID)).element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.find(START_PROJECT).text()).toMatch(/\b1\b/)
    await startProject(wrapper)
    expect(useIndustryStore().navigationIntent).toEqual(projectIntent(
      { typeId: DRAKE_TYPE_ID, typeName: 'Drake', meLevel: 10, teLevel: 20, runs: 1, blueprintSource: 'assumed' },
    ))
  })

  it('empties the selection when a new scan replaces the results', async () => {
    const wrapper = await mountScanner({ storedLibrary: [] })
    await tick(wrapper, DRAKE_TYPE_ID)
    await tick(wrapper, VEXOR_TYPE_ID)

    useScannerStore().scanResults = [{ ...DRAKE }, { ...VEXOR }]
    await nextTick()

    expect((wrapper.find(rowCheckbox(DRAKE_TYPE_ID)).element as HTMLInputElement).checked).toBe(false)
    expect(wrapper.find(START_PROJECT).attributes('disabled')).toBeDefined()
  })

  it('ticks every row passing the filters from the header checkbox and unticks them on the second click', async () => {
    const wrapper = await mountScanner({ storedLibrary: [] })
    const selectAll = wrapper.find(SELECT_ALL)
    expect(selectAll.exists()).toBe(true)

    await selectAll.trigger('click')
    expect(wrapper.find(START_PROJECT).text()).toMatch(/\b4\b/)
    expect((selectAll.element as HTMLInputElement).checked).toBe(true)

    await selectAll.trigger('click')
    expect(wrapper.find(START_PROJECT).attributes('disabled')).toBeDefined()
    expect((wrapper.find(rowCheckbox(DRAKE_TYPE_ID)).element as HTMLInputElement).checked).toBe(false)
  })

  it('stops with the library error next to the button and never falls back to the assumption', async () => {
    const wrapper = await mountScanner({ libraryFailure: LIBRARY_ERROR })
    await tick(wrapper, HAMMERHEAD_TYPE_ID)

    await startProject(wrapper)

    expect(useIndustryStore().navigationIntent).toBeNull()
    expect(wrapper.find(START_PROJECT_ERROR).exists()).toBe(true)
    expect(wrapper.find(START_PROJECT_ERROR).text()).toContain(LIBRARY_ERROR)
  })
})

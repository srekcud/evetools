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
const FAVORITES_OPTION_VALUE = 'favorites'
const ALL_OPTION_VALUE = 'all'
const CATEGORY_SELECTOR = '[id$="-category"]'
const RESULT_ROWS_SELECTOR = 'tbody tr'
const STALE_FILTERS_SELECTOR = '[data-test="stale-filters"]'
const FAVORITE_SHIP_TYPE_ID = 585
const FAVORITE_DRONE_TYPE_ID = 2_454
const FAVORITE_BELOW_MARGIN_TYPE_ID = 2_456
const OTHER_SHIP_TYPE_ID = 587
const OTHER_DRONE_TYPE_ID = 2_446
const BELOW_MIN_MARGIN_PERCENT = 1

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

const scanAcrossCategories = [
  scannedProduct(FAVORITE_SHIP_TYPE_ID),
  scannedProduct(OTHER_SHIP_TYPE_ID),
  scannedProduct(FAVORITE_DRONE_TYPE_ID, { categoryLabel: 'Drones' }),
  scannedProduct(OTHER_DRONE_TYPE_ID, { categoryLabel: 'Drones' }),
  scannedProduct(FAVORITE_BELOW_MARGIN_TYPE_ID, { marginPercent: BELOW_MIN_MARGIN_PERCENT }),
]

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function favoriteRows(typeIds: number[]): Array<{ typeId: number; createdAt: string }> {
  return typeIds.map(typeId => ({ typeId, createdAt: '2026-11-01T10:00:00+00:00' }))
}

function profitScanUrls(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).filter(endpoint => endpoint.startsWith('/industry/profit-scan'))
}

async function mountScannerWithFavorites(favoriteTypeIds: number[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/me/settings') {
      return { preferredMarketStructureId: C_J6MT_STRUCTURE_ID, preferredMarketStructureName: 'C-J6MT', marketStructures: [{ id: C_J6MT_STRUCTURE_ID, name: 'C-J6MT' }] }
    }
    if (endpoint.startsWith('/industry/profit-scan')) return scanAcrossCategories
    if (endpoint === '/industry/scanner-favorites') return favoriteRows(favoriteTypeIds)
    return []
  })
  const wrapper = mount(BatchScanTab, { global: { plugins: [i18n], stubs: { ProductionCalculator: true } } })
  await flushPromises()
  return wrapper
}

function listedProductNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll(RESULT_ROWS_SELECTOR).map(row => row.text()).map(text => text.match(/Product \d+/)?.[0] ?? '')
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('BatchScanTab favorites entry of the category dropdown (issue 1001)', () => {
  it('lists a Favorites option right after All Categories', async () => {
    const wrapper = await mountScannerWithFavorites([FAVORITE_SHIP_TYPE_ID])

    const options = wrapper.find(CATEGORY_SELECTOR).findAll('option')

    expect(options[0].element.value).toBe(ALL_OPTION_VALUE)
    expect(options[1].element.value).toBe(FAVORITES_OPTION_VALUE)
    expect(options[1].text()).toBe(i18n.global.t('industry.scanner.batch.favorites'))
  })

  it('keeps only the favorite types across categories, margin and volume filters still applying', async () => {
    const wrapper = await mountScannerWithFavorites([FAVORITE_SHIP_TYPE_ID, FAVORITE_DRONE_TYPE_ID, FAVORITE_BELOW_MARGIN_TYPE_ID])

    await wrapper.find(CATEGORY_SELECTOR).setValue(FAVORITES_OPTION_VALUE)

    expect(listedProductNames(wrapper).sort()).toEqual([`Product ${FAVORITE_SHIP_TYPE_ID}`, `Product ${FAVORITE_DRONE_TYPE_ID}`].sort())
  })

  it('never sends the favorites value to the backend and scans all categories', async () => {
    const wrapper = await mountScannerWithFavorites([FAVORITE_SHIP_TYPE_ID])

    await wrapper.find(CATEGORY_SELECTOR).setValue(FAVORITES_OPTION_VALUE)
    await wrapper.find('button.bg-cyan-600, button[data-test="scan-button"]').trigger('click')
    await flushPromises()

    const urls = profitScanUrls()
    expect(urls.length).toBeGreaterThan(1)
    expect(urls.every(url => !url.includes('category='))).toBe(true)
  })

  it('does not call the results stale when only switching to favorites, which filters client-side', async () => {
    const wrapper = await mountScannerWithFavorites([FAVORITE_SHIP_TYPE_ID])

    await wrapper.find(CATEGORY_SELECTOR).setValue(FAVORITES_OPTION_VALUE)

    expect(wrapper.find(STALE_FILTERS_SELECTOR).exists()).toBe(false)
  })

  it('disables the Favorites option while no favorite is starred', async () => {
    const wrapper = await mountScannerWithFavorites([])

    const favoritesOption = wrapper.find(CATEGORY_SELECTOR).findAll('option')[1]

    expect(favoritesOption.element.value).toBe(FAVORITES_OPTION_VALUE)
    expect(favoritesOption.attributes('disabled')).toBeDefined()
  })

  it('enables the Favorites option once a favorite is starred', async () => {
    const wrapper = await mountScannerWithFavorites([FAVORITE_SHIP_TYPE_ID])

    const favoritesOption = wrapper.find(CATEGORY_SELECTOR).findAll('option')[1]

    expect(favoritesOption.attributes('disabled')).toBeUndefined()
  })

  it('still narrows to the chosen category by label when another category is selected', async () => {
    const wrapper = await mountScannerWithFavorites([FAVORITE_SHIP_TYPE_ID])

    await wrapper.find(CATEGORY_SELECTOR).setValue('drones')

    expect(listedProductNames(wrapper).sort()).toEqual([`Product ${FAVORITE_DRONE_TYPE_ID}`, `Product ${OTHER_DRONE_TYPE_ID}`].sort())
  })
})

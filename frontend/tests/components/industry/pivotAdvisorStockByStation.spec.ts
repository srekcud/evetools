import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import type { PivotAnalysisResult } from '@/stores/industry/types'

const SOURCE_PRODUCT_TYPE_ID = 11379
const CANDIDATE_TYPE_ID = 22456
const ROBOTICS_TYPE_ID = 9848
const CAPACITOR_BATTERY_TYPE_ID = 11400
const CONSTRUCTION_BLOCK_TYPE_ID = 11401

const JITA_STATION = {
  locationId: 60003760,
  locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
  systemName: 'Jita',
  quantity: 1234,
}
const ISHTAR_STATION = {
  locationId: 60011866,
  locationName: 'Ishtar VII - Moon 2 - Sisters of EVE Bureau',
  systemName: 'Ishtar',
  quantity: 15,
}
const RENS_STATION_WITHOUT_SYSTEM = {
  locationId: 60004588,
  locationName: 'Rens VI - Moon 8 - Brutor Tribe Treasury',
  systemName: null,
  quantity: 7,
}

const COMPONENTS = [
  { typeId: ROBOTICS_TYPE_ID, typeName: 'Robotics', inStock: 1249, stockByStation: [JITA_STATION, ISHTAR_STATION] },
  { typeId: CAPACITOR_BATTERY_TYPE_ID, typeName: 'Capacitor Battery', inStock: 0, stockByStation: [] },
  { typeId: CONSTRUCTION_BLOCK_TYPE_ID, typeName: 'Construction Block', inStock: 7, stockByStation: [RENS_STATION_WITHOUT_SYSTEM] },
]

function pivotResult(): PivotAnalysisResult {
  return {
    typeId: SOURCE_PRODUCT_TYPE_ID,
    sourceProduct: {
      typeId: SOURCE_PRODUCT_TYPE_ID,
      typeName: 'Source Ship',
      groupName: 'Destroyer',
      marginPercent: 823.38,
      sellPrice: 4000,
      dailyVolume: 25,
      keyComponents: COMPONENTS.map(component => ({ ...component })),
      unknownReason: null,
      missingPriceTypeIds: [],
    },
    matrix: COMPONENTS.map(component => ({
      ...component,
      candidates: {
        [SOURCE_PRODUCT_TYPE_ID]: { needed: 54, status: 'covered' },
        [CANDIDATE_TYPE_ID]: { needed: 63, status: 'partial' },
      },
    })),
    candidates: [{
      typeId: CANDIDATE_TYPE_ID,
      typeName: 'Candidate X',
      groupName: 'Cruiser',
      marginPercent: 261.09,
      coveragePercent: 25,
      estimatedProfit: 2013,
      score: 13054.47,
      dailyVolume: 99,
      profitPerUnit: 671,
      missingComponents: [],
      additionalCost: 0,
      unknownReason: null,
      missingPriceTypeIds: [],
    }],
    matrixProductIds: [SOURCE_PRODUCT_TYPE_ID, CANDIDATE_TYPE_ID],
  } as unknown as PivotAnalysisResult
}

function mountPivotAdvisor(): VueWrapper {
  useScannerStore().pivotResult = pivotResult()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(PivotAdvisorTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

function toggleOf(wrapper: VueWrapper, scope: 'matrix' | 'key', typeId: number) {
  return wrapper.find(`[data-testid="pivot-stock-toggle-${scope}-${typeId}"]`)
}

function stationsOf(wrapper: VueWrapper, scope: 'matrix' | 'key', typeId: number) {
  return wrapper.find(`[data-testid="pivot-stock-stations-${scope}-${typeId}"]`)
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe.each(['matrix', 'key'] as const)('PivotAdvisorTab stock by station in the %s', (scope) => {
  it('keeps the total in stock and folds the stations by default behind a toggle', () => {
    const wrapper = mountPivotAdvisor()

    expect(wrapper.text()).toContain('1,249')
    expect(toggleOf(wrapper, scope, ROBOTICS_TYPE_ID).exists()).toBe(true)
    expect(toggleOf(wrapper, scope, ROBOTICS_TYPE_ID).attributes('aria-expanded')).toBe('false')
    expect(stationsOf(wrapper, scope, ROBOTICS_TYPE_ID).exists()).toBe(false)
  })

  it('unfolds one line per station with its name, system and formatted quantity, then folds again on a second click', async () => {
    const wrapper = mountPivotAdvisor()

    await toggleOf(wrapper, scope, ROBOTICS_TYPE_ID).trigger('click')

    expect(toggleOf(wrapper, scope, ROBOTICS_TYPE_ID).attributes('aria-expanded')).toBe('true')
    const stationsText = stationsOf(wrapper, scope, ROBOTICS_TYPE_ID).text()
    expect(stationsText).toContain(JITA_STATION.locationName)
    expect(stationsText).toContain('Jita')
    expect(stationsText).toContain('1,234')
    expect(stationsText).toContain(ISHTAR_STATION.locationName)
    expect(stationsText).toContain('Ishtar')
    expect(stationsText).toContain('15')
    expect(stationsText.indexOf(JITA_STATION.locationName)).toBeLessThan(stationsText.indexOf(ISHTAR_STATION.locationName))

    await toggleOf(wrapper, scope, ROBOTICS_TYPE_ID).trigger('click')

    expect(toggleOf(wrapper, scope, ROBOTICS_TYPE_ID).attributes('aria-expanded')).toBe('false')
    expect(stationsOf(wrapper, scope, ROBOTICS_TYPE_ID).exists()).toBe(false)
  })

  it('shows neither a toggle nor an unfolded line for a component without stock', () => {
    const wrapper = mountPivotAdvisor()

    expect(toggleOf(wrapper, scope, CAPACITOR_BATTERY_TYPE_ID).exists()).toBe(false)
    expect(stationsOf(wrapper, scope, CAPACITOR_BATTERY_TYPE_ID).exists()).toBe(false)
  })

  it('omits the system of a station without system name and keeps its name and quantity', async () => {
    const wrapper = mountPivotAdvisor()

    await toggleOf(wrapper, scope, CONSTRUCTION_BLOCK_TYPE_ID).trigger('click')

    const stationsText = stationsOf(wrapper, scope, CONSTRUCTION_BLOCK_TYPE_ID).text()
    expect(stationsText).toContain(RENS_STATION_WITHOUT_SYSTEM.locationName)
    expect(stationsText).toContain('7')
    expect(stationsText).not.toContain('null')
    expect(stationsText).not.toContain('undefined')
  })
})

describe('PivotAdvisorTab stock by station independence', () => {
  it('unfolds the matrix without touching the key components', async () => {
    const wrapper = mountPivotAdvisor()

    await toggleOf(wrapper, 'matrix', ROBOTICS_TYPE_ID).trigger('click')

    expect(stationsOf(wrapper, 'matrix', ROBOTICS_TYPE_ID).exists()).toBe(true)
    expect(toggleOf(wrapper, 'key', ROBOTICS_TYPE_ID).attributes('aria-expanded')).toBe('false')
    expect(stationsOf(wrapper, 'key', ROBOTICS_TYPE_ID).exists()).toBe(false)
  })

  it('unfolds the key components without touching the matrix', async () => {
    const wrapper = mountPivotAdvisor()

    await toggleOf(wrapper, 'key', ROBOTICS_TYPE_ID).trigger('click')

    expect(stationsOf(wrapper, 'key', ROBOTICS_TYPE_ID).exists()).toBe(true)
    expect(toggleOf(wrapper, 'matrix', ROBOTICS_TYPE_ID).attributes('aria-expanded')).toBe('false')
    expect(stationsOf(wrapper, 'matrix', ROBOTICS_TYPE_ID).exists()).toBe(false)
  })

  it('folds every unfolded component when a new analysis replaces the result', async () => {
    const wrapper = mountPivotAdvisor()
    await toggleOf(wrapper, 'matrix', ROBOTICS_TYPE_ID).trigger('click')
    await toggleOf(wrapper, 'key', ROBOTICS_TYPE_ID).trigger('click')
    await toggleOf(wrapper, 'key', CONSTRUCTION_BLOCK_TYPE_ID).trigger('click')
    expect(toggleOf(wrapper, 'matrix', ROBOTICS_TYPE_ID).attributes('aria-expanded')).toBe('true')

    useScannerStore().pivotResult = pivotResult()
    await nextTick()

    expect(toggleOf(wrapper, 'matrix', ROBOTICS_TYPE_ID).attributes('aria-expanded')).toBe('false')
    expect(toggleOf(wrapper, 'key', ROBOTICS_TYPE_ID).attributes('aria-expanded')).toBe('false')
    expect(toggleOf(wrapper, 'key', CONSTRUCTION_BLOCK_TYPE_ID).attributes('aria-expanded')).toBe('false')
    expect(stationsOf(wrapper, 'matrix', ROBOTICS_TYPE_ID).exists()).toBe(false)
    expect(stationsOf(wrapper, 'key', ROBOTICS_TYPE_ID).exists()).toBe(false)
    expect(stationsOf(wrapper, 'key', CONSTRUCTION_BLOCK_TYPE_ID).exists()).toBe(false)
  })
})

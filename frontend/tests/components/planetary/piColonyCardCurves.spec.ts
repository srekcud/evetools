import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiColonyCard from '@/components/planetary/PiColonyCard.vue'
import type { Colony, ExtractorYieldCurve, PlanetaryInputNeedsLaunchpad, Pin } from '@/stores/planetary'

const YIELD_CURVE = '[data-testid="yield-curve"]'
const YIELD_CURVE_LINE = '[data-testid="yield-curve-line"]'
const YIELD_CURVE_UNKNOWN = '[data-testid="yield-curve-unknown"]'
const LAUNCHPAD_CURVE = '[data-testid="launchpad-curve"]'
const COMMAND_CENTER_BLOCK = '[data-testid="command-center-block"]'

const NOW = new Date('2026-11-26T09:00:00+00:00')
const BACTERIA_TYPE_ID = 2_393
const REACTIVE_METALS_TYPE_ID = 2_398

const BACTERIA_CURVE: ExtractorYieldCurve = {
  cycleSeconds: 1_800,
  yields: [8_000, 6_000, 4_000, 2_000],
  totalQuantity: 20_000,
  averagePerHour: 10_000,
  suggestedDays: 2,
}

function extractorPin(pinId: number, productTypeId: number, productName: string, yieldCurve: ExtractorYieldCurve | null): Pin {
  return {
    pinId,
    typeId: 2_848,
    typeName: 'Barren Extractor Control Unit',
    pinCategory: 'extractor',
    capacity: null,
    schematicId: null,
    schematicName: null,
    schematicCycleTime: null,
    schematicInputs: [],
    schematicOutput: null,
    installTime: '2026-11-26T08:00:00+00:00',
    expiryTime: '2026-11-26T10:00:00+00:00',
    extractorProductTypeId: productTypeId,
    extractorProductName: productName,
    extractorCycleTime: 1_800,
    extractorQtyPerCycle: 4_400,
    extractorNumHeads: 8,
    contents: [],
    dailyOutput: null,
    outputTier: 'P0',
    yieldCurve,
  }
}

function launchpadOf(pinId: number, usedVolumeM3: number): PlanetaryInputNeedsLaunchpad {
  return {
    colonyId: 'colony-barren-elite',
    pinId,
    capacityM3: 10_000,
    usedVolumeM3,
    dailyVolumeM3: 960,
    lastViewAt: '2026-11-26T08:00:00+00:00',
    dataAgeSeconds: 3_600,
    dataStale: false,
    feeds: [],
  }
}

const COLONY: Colony = {
  id: 'colony-barren-elite',
  characterId: 2_119_000_011,
  characterName: 'Ilyra Castell-Vanterwyck of the Deep Core Verhoeven Syndicate',
  planetId: 40_009_077,
  planetName: 'Jita IV',
  planetType: 'barren',
  solarSystemId: 30_000_142,
  solarSystemName: 'Jita',
  solarSystemSecurity: 0.9,
  upgradeLevel: 3,
  tier: null,
  storageFull: null,
  numPins: 2,
  lastUpdate: '2026-11-26T09:00:00+00:00',
  cachedAt: '2026-11-26T09:05:00+00:00',
  extractorCount: 2,
  factoryCount: 0,
  activeExtractors: 2,
  nearestExpiry: '2026-11-26T10:00:00+00:00',
  factoryProgress: null,
  status: 'active',
  pins: [
    extractorPin(1, BACTERIA_TYPE_ID, 'Bacteria', BACTERIA_CURVE),
    extractorPin(2, REACTIVE_METALS_TYPE_ID, 'Reactive Metals', null),
  ],
  routes: [],
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW)
})

afterEach(() => {
  vi.useRealTimers()
})

function mountCard(expanded: boolean, launchpads?: PlanetaryInputNeedsLaunchpad[]) {
  setActivePinia(createPinia())
  return mount(PiColonyCard, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { colony: COLONY, expanded, ...(launchpads === undefined ? {} : { launchpads }) },
  })
}

describe('PiColonyCard extractor and launchpad curves', () => {
  it('shows one yield curve under each extractor of the detail, an unknown one for the extractor without a curve, and keeps the existing blocks', () => {
    const wrapper = mountCard(true)

    expect(wrapper.findAll(YIELD_CURVE)).toHaveLength(2)
    expect(wrapper.findAll(YIELD_CURVE_LINE)).toHaveLength(1)
    expect(wrapper.findAll(YIELD_CURVE_UNKNOWN)).toHaveLength(1)
    expect(wrapper.find(COMMAND_CENTER_BLOCK).exists()).toBe(true)
    expect(wrapper.text()).toContain('Bacteria')
    expect(wrapper.text()).toContain('Reactive Metals')
  })

  it('shows one launchpad curve for each launchpad passed to the expanded card, and none when collapsed or when none is passed', () => {
    const launchpads = [launchpadOf(1_038_000_000_101, 4_000), launchpadOf(1_038_000_000_102, 2_500)]

    expect(mountCard(true, launchpads).findAll(LAUNCHPAD_CURVE)).toHaveLength(2)
    expect(mountCard(false, launchpads).find(LAUNCHPAD_CURVE).exists()).toBe(false)
    expect(mountCard(true).find(LAUNCHPAD_CURVE).exists()).toBe(false)
  })
})

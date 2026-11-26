import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiColonyCard from '@/components/planetary/PiColonyCard.vue'
import type { Colony, ExtractionBalanceRow } from '@/stores/planetary'

const BLOCK = '[data-testid="extraction-balance"]'
const ROW = '[data-testid="extraction-balance-row"]'
const COMMAND_CENTER_BLOCK = '[data-testid="command-center-block"]'

const NOW = new Date('2026-11-26T09:00:00+00:00')

const BACTERIA_DEFICIT: ExtractionBalanceRow = {
  typeId: 2_393,
  typeName: 'Bacteria',
  extractedPerDay: 100_000,
  demandedPerDay: 288_000,
  balancePerDay: -188_000,
  runRate: 0.35,
  factoryCount: 2,
  headCount: 2,
  advice: { kind: 'deficit', heads: 4, factories: 2 },
}

const PROTEINS_SURPLUS: ExtractionBalanceRow = {
  typeId: 2_395,
  typeName: 'Proteins',
  extractedPerDay: 576_000,
  demandedPerDay: 288_000,
  balancePerDay: 288_000,
  runRate: 1,
  factoryCount: 2,
  headCount: 6,
  advice: { kind: 'surplus', heads: null, factories: 2 },
}

const COLONY: Colony = {
  id: 'colony-barren-extraction',
  characterId: 2_119_000_011,
  characterName: 'Ilyra Castell-Vanterwyck of the Deep Core Verhoeven Syndicate',
  planetId: 40_009_077,
  planetName: 'Jita IV',
  planetType: 'barren',
  solarSystemId: 30_000_142,
  solarSystemName: 'Jita',
  solarSystemSecurity: 0.9,
  upgradeLevel: 3,
  tier: 'P1',
  storageFull: null,
  numPins: 4,
  lastUpdate: '2026-11-26T09:00:00+00:00',
  cachedAt: '2026-11-26T09:05:00+00:00',
  extractorCount: 2,
  factoryCount: 4,
  activeExtractors: 2,
  nearestExpiry: '2026-11-26T10:00:00+00:00',
  factoryProgress: null,
  status: 'active',
  pins: [],
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

function mountCard(colony: Colony, expanded: boolean) {
  setActivePinia(createPinia())
  return mount(PiColonyCard, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { colony, expanded },
  })
}

describe('PiColonyCard extraction balance', () => {
  it('shows the balance block with one row per type in the expanded detail, keeps the existing blocks, and shows nothing when collapsed', () => {
    const colony = { ...COLONY, extractionBalance: [BACTERIA_DEFICIT, PROTEINS_SURPLUS] }

    const expanded = mountCard(colony, true)

    expect(expanded.find(BLOCK).exists()).toBe(true)
    expect(expanded.findAll(ROW)).toHaveLength(2)
    expect(expanded.find(COMMAND_CENTER_BLOCK).exists()).toBe(true)
    expect(mountCard(colony, false).find(BLOCK).exists()).toBe(false)
  })

  it('shows no balance block for a colony without balance, whether the field is missing or empty', () => {
    expect(mountCard(COLONY, true).find(BLOCK).exists()).toBe(false)
    expect(mountCard({ ...COLONY, extractionBalance: [] }, true).find(BLOCK).exists()).toBe(false)
  })
})

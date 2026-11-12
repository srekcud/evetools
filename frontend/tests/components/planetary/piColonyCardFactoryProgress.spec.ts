import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiColonyCard from '@/components/planetary/PiColonyCard.vue'
import type { Colony } from '@/stores/planetary'

const FACTORY_PROGRESS = '[data-testid="pi-factory-progress"]'
const FACTORY_TIMER = '[data-testid="pi-factory-timer"]'
const COOLANT_TYPE_ID = 9_832
const NOW = new Date('2026-11-12T10:00:00+00:00')

const FACTORY_COLONY: Colony = {
  id: 'colony-factory',
  characterId: 2_119_000_003,
  characterName: 'Erin Planeteer',
  planetId: 40_009_082,
  planetName: 'Tama VII',
  planetType: 'barren',
  solarSystemId: 30_002_813,
  solarSystemName: 'Tama',
  solarSystemSecurity: 0.3,
  upgradeLevel: 5,
  numPins: 2,
  lastUpdate: '2026-11-12T09:00:00+00:00',
  cachedAt: '2026-11-12T09:05:00+00:00',
  extractorCount: 0,
  factoryCount: 2,
  activeExtractors: 0,
  nearestExpiry: null,
  factoryProgress: {
    finalProductTypeId: COOLANT_TYPE_ID,
    finalProductName: 'Coolant',
    factoryCount: 2,
    stockedFinalProduct: 5,
    maxProducts: 50,
    remainingSeconds: 7_200,
    endsAt: '2026-11-12T12:00:00+00:00',
  },
  status: 'idle',
  pins: [],
  routes: [],
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
})

afterEach(() => {
  vi.useRealTimers()
})

function mountCard(colony: Colony) {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(PiColonyCard, {
    global: { plugins: [i18n] },
    props: { colony, expanded: false },
  })
}

describe('PiColonyCard factory colony', () => {
  it('shows the countdown to the last final product in place of the extractor timer', () => {
    const wrapper = mountCard(FACTORY_COLONY)

    expect(wrapper.find(FACTORY_TIMER).text()).toBe('02:00:00')
    expect(wrapper.text()).not.toContain('No extractors')
  })

  it('names the final product with its stock and the products still to make', () => {
    const wrapper = mountCard(FACTORY_COLONY)

    expect(wrapper.find(FACTORY_PROGRESS).text()).toContain('Coolant: 5 in stock, 50 left to make')
  })

  it('keeps the no-extractor label on a colony without factory progress', () => {
    const wrapper = mountCard({ ...FACTORY_COLONY, factoryCount: 0, factoryProgress: null })

    expect(wrapper.find(FACTORY_PROGRESS).exists()).toBe(false)
    expect(wrapper.text()).toContain('No extractors')
  })

  it('shows an expired timer once the stock is used up', () => {
    const wrapper = mountCard({
      ...FACTORY_COLONY,
      factoryProgress: { ...FACTORY_COLONY.factoryProgress!, maxProducts: 0, remainingSeconds: 0, endsAt: NOW.toISOString() },
    })

    expect(wrapper.find(FACTORY_TIMER).text()).toBe('EXPIRED')
  })
})

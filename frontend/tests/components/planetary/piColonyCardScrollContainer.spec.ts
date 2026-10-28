import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiColonyCard from '@/components/planetary/PiColonyCard.vue'
import type { Colony, Pin } from '@/stores/planetary'
import { tablesInScrollContainer } from '../tableScrollContainer'

const COOLANT_FACTORY: Pin = {
  pinId: 1_020_000_001,
  typeId: 2_474,
  typeName: 'Barren Advanced Industry Facility',
  pinCategory: 'factory',
  capacity: null,
  schematicId: 66,
  schematicName: 'Coolant',
  schematicCycleTime: 3_600,
  schematicInputs: [
    { typeId: 2_390, typeName: 'Electrolytes', quantity: 40 },
    { typeId: 3_645, typeName: 'Water', quantity: 40 },
  ],
  schematicOutput: { typeId: 9_832, typeName: 'Coolant', quantity: 5 },
  installTime: '2026-10-20T08:00:00+00:00',
  expiryTime: null,
  extractorProductTypeId: null,
  extractorProductName: null,
  extractorCycleTime: null,
  extractorQtyPerCycle: null,
  extractorNumHeads: null,
  contents: [],
  dailyOutput: 120,
  outputTier: 'p2',
}

const BARREN_COLONY: Colony = {
  id: 'colony-barren',
  characterId: 2_119_000_003,
  characterName: 'Erin Planeteer',
  planetId: 40_009_082,
  planetName: 'Tama VII',
  planetType: 'barren',
  solarSystemId: 30_002_813,
  solarSystemName: 'Tama',
  solarSystemSecurity: 0.3,
  upgradeLevel: 5,
  numPins: 1,
  lastUpdate: '2026-10-26T10:00:00+00:00',
  cachedAt: '2026-10-26T10:05:00+00:00',
  extractorCount: 0,
  factoryCount: 1,
  activeExtractors: 0,
  nearestExpiry: null,
  status: 'idle',
  pins: [COOLANT_FACTORY],
  routes: [],
}

function mountExpandedColony() {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(PiColonyCard, {
    global: { plugins: [i18n] },
    props: { colony: BARREN_COLONY, expanded: true },
  })
}

describe('PiColonyCard on a narrow screen', () => {
  it('puts the factories table in a horizontally scrollable container', () => {
    const wrapper = mountExpandedColony()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})

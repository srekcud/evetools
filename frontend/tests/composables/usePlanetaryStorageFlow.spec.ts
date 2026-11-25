import { createPinia, setActivePinia } from 'pinia'
import { usePlanetaryHelpers } from '@/composables/usePlanetaryHelpers'
import * as planetaryHelpers from '@/composables/usePlanetaryHelpers'
import type { Colony, Pin, Route } from '@/stores/planetary'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

const LAUNCHPAD_PIN_ID = 100
const FIRST_FACTORY_PIN_ID = 201
const FACTORY_CYCLE_SECONDS = 3_600
const FACTORY_QUANTITY_PER_CYCLE = 120
const DAILY_QUANTITY_PER_ROUTE = 2_880
const SDE_P1_VOLUME = 0.19
const P1_TYPE_IDS = [2_389, 2_390, 2_392, 2_393]
const LAUNCHPAD_CAPACITY = 10_000

function pin(overrides: Partial<Pin>): Pin {
  return {
    pinId: 1,
    typeId: 1,
    typeName: null,
    pinCategory: 'other',
    capacity: null,
    schematicId: null,
    schematicName: null,
    schematicCycleTime: null,
    schematicInputs: [],
    schematicOutput: null,
    installTime: null,
    expiryTime: null,
    extractorProductTypeId: null,
    extractorProductName: null,
    extractorCycleTime: null,
    extractorQtyPerCycle: null,
    extractorNumHeads: null,
    contents: [],
    dailyOutput: null,
    outputTier: null,
    ...overrides,
  }
}

function launchpad(): Pin {
  return pin({ pinId: LAUNCHPAD_PIN_ID, pinCategory: 'launchpad', capacity: LAUNCHPAD_CAPACITY })
}

function factory(pinId: number): Pin {
  return pin({ pinId, pinCategory: 'factory', schematicCycleTime: FACTORY_CYCLE_SECONDS })
}

function routeFeedingFactory(index: number, contentTypeId: number, contentVolume: number | null): Route & { contentVolume: number | null } {
  return {
    routeId: index + 1,
    sourcePinId: LAUNCHPAD_PIN_ID,
    destinationPinId: FIRST_FACTORY_PIN_ID + index,
    contentTypeId,
    contentTypeName: null,
    quantity: FACTORY_QUANTITY_PER_CYCLE,
    waypoints: [],
    contentVolume,
  }
}

function colonyWith(routes: Route[]): Colony {
  const factories = routes.map((_, index) => factory(FIRST_FACTORY_PIN_ID + index))
  return {
    id: 'colony-flow',
    pins: [launchpad(), ...factories],
    routes,
  } as Colony
}

function storageFlow(routes: Route[]) {
  setActivePinia(createPinia())
  const colony = colonyWith(routes)
  const { getStorageFlowData } = usePlanetaryHelpers()
  return getStorageFlowData(colony, colony.pins[0])
}

describe('storage net daily volume', () => {
  it('uses the SDE volume of each route content, whatever the production of the user', () => {
    const routes = P1_TYPE_IDS.map((typeId, index) => routeFeedingFactory(index, typeId, SDE_P1_VOLUME))

    const flow = storageFlow(routes)

    expect(flow.netDailyVolume).toBeCloseTo(-(P1_TYPE_IDS.length * DAILY_QUANTITY_PER_ROUTE * SDE_P1_VOLUME), 2)
  })

  it('weights every content by its own volume', () => {
    const routes = [
      routeFeedingFactory(0, P1_TYPE_IDS[0], 0.19),
      routeFeedingFactory(1, P1_TYPE_IDS[1], 3),
    ]

    const flow = storageFlow(routes)

    expect(flow.netDailyVolume).toBeCloseTo(-(DAILY_QUANTITY_PER_ROUTE * 0.19 + DAILY_QUANTITY_PER_ROUTE * 3), 2)
  })

  it('is unknown, never zero, when a route content has no known volume', () => {
    const routes = [
      routeFeedingFactory(0, P1_TYPE_IDS[0], SDE_P1_VOLUME),
      routeFeedingFactory(1, P1_TYPE_IDS[1], null),
    ]

    const flow = storageFlow(routes)

    expect(flow.netDailyVolume).toBeNull()
    expect(flow.fillDays).toBeNull()
  })

  it('still derives the fill time from the net volume when a launchpad fills up', () => {
    const incomingRoute: Route & { contentVolume: number | null } = {
      routeId: 9,
      sourcePinId: FIRST_FACTORY_PIN_ID,
      destinationPinId: LAUNCHPAD_PIN_ID,
      contentTypeId: P1_TYPE_IDS[0],
      contentTypeName: null,
      quantity: FACTORY_QUANTITY_PER_CYCLE,
      waypoints: [],
      contentVolume: SDE_P1_VOLUME,
    }

    const flow = storageFlow([incomingRoute])

    const netDailyVolume = DAILY_QUANTITY_PER_ROUTE * SDE_P1_VOLUME
    expect(flow.fillDays).toBe(Math.round((LAUNCHPAD_CAPACITY / netDailyVolume) * 10) / 10)
  })
})

describe('volume constants', () => {
  it('no longer ship a hard-coded volume per tier', () => {
    expect(planetaryHelpers).not.toHaveProperty('VOLUME_BY_TIER')
  })
})

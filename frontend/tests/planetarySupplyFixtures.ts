import type { SupplyFeed, SupplyLaunchpad } from '@/utils/planetarySupply'

export const COOLANT = 92_001
export const MECHANICAL_PARTS = 92_002
export const CONSUMER_ELECTRONICS = 92_003
export const FERTILIZER = 92_004
export const WATER = 92_005
export const PLASMOIDS = 92_009

export const TYPE_NAMES: Record<number, string> = {
  [COOLANT]: 'Coolant',
  [MECHANICAL_PARTS]: 'Mechanical Parts',
  [CONSUMER_ELECTRONICS]: 'Consumer Electronics',
  [FERTILIZER]: 'Fertilizer',
  [WATER]: 'Water',
  [PLASMOIDS]: 'Plasmoids',
}

export const REFERENCE_DAILY_RATE = 2880
export const REFERENCE_VOLUME_PER_UNIT = 0.19
export const REFERENCE_CAPACITY_M3 = 10_000

export function feedOf(typeId: number, overrides: Partial<SupplyFeed> = {}): SupplyFeed {
  return {
    typeId,
    typeName: TYPE_NAMES[typeId],
    dailyRate: REFERENCE_DAILY_RATE,
    stock: 0,
    projectedStock: 0,
    emptyAt: null,
    volumePerUnit: REFERENCE_VOLUME_PER_UNIT,
    ...overrides,
  }
}

export function launchpadOf(overrides: Partial<SupplyLaunchpad> = {}): SupplyLaunchpad {
  return {
    colonyId: 'colony-jita',
    characterId: 2_119_000_001,
    characterName: 'Jita Pilot',
    planetId: 40009077,
    planetName: 'Jita IV',
    pinId: 1_001,
    typeId: 2_562,
    typeName: 'Launchpad',
    capacityM3: REFERENCE_CAPACITY_M3,
    usedVolumeM3: 0,
    dailyVolumeM3: 2188.8,
    lastViewAt: '2026-11-26T08:00:00+00:00',
    dataAgeSeconds: 600,
    dataStale: false,
    feeds: [],
    ...overrides,
  }
}

export const REFERENCE_FEED_TYPE_IDS = [COOLANT, MECHANICAL_PARTS, CONSUMER_ELECTRONICS, FERTILIZER]

export function referenceLaunchpad(overrides: Partial<SupplyLaunchpad> = {}): SupplyLaunchpad {
  return launchpadOf({ feeds: REFERENCE_FEED_TYPE_IDS.map(typeId => feedOf(typeId)), ...overrides })
}

export function secondLaunchpad(overrides: Partial<SupplyLaunchpad> = {}): SupplyLaunchpad {
  return launchpadOf({
    colonyId: 'colony-perimeter',
    planetId: 40009100,
    planetName: 'Perimeter II',
    pinId: 2_002,
    feeds: [
      feedOf(COOLANT, { projectedStock: 2000, stock: 2400 }),
      feedOf(WATER, { dailyRate: 1440, volumePerUnit: 0.38 }),
    ],
    ...overrides,
  })
}

export function unknownCapacityLaunchpad(overrides: Partial<SupplyLaunchpad> = {}): SupplyLaunchpad {
  return launchpadOf({
    colonyId: 'colony-amarr',
    planetId: 40009200,
    planetName: 'Amarr VIII',
    pinId: 3_003,
    capacityM3: null,
    feeds: [feedOf(COOLANT), feedOf(PLASMOIDS, { dailyRate: 720, volumePerUnit: 0.57 })],
    ...overrides,
  })
}

export function supplyResponse(launchpads: SupplyLaunchpad[], complete = true) {
  return { id: 'input-needs', colonies: [], launchpads, extractions: [], weekly: [], complete }
}

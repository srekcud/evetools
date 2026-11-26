import type { CustomsOfficeStock, PendingOutput } from '@/utils/planetaryPickup'
import {
  COOLANT,
  FERTILIZER,
  MECHANICAL_PARTS,
  PLASMOIDS,
  TYPE_NAMES,
  WATER,
} from './planetarySupplyFixtures'

export const JITA_CONSUMER = { colonyId: 'colony-jita', planetName: 'Jita IV' }
export const PERIMETER_CONSUMER = { colonyId: 'colony-perimeter', planetName: 'Perimeter II' }

export function pendingOutputOf(overrides: Partial<PendingOutput> = {}): PendingOutput {
  return {
    colonyId: 'colony-amarr-iii',
    planetId: 40009300,
    planetName: 'Amarr III',
    solarSystemName: 'Amarr',
    characterId: 9_001,
    characterName: 'Pilot One',
    typeId: COOLANT,
    typeName: TYPE_NAMES[COOLANT],
    quantity: 4000,
    volumePerUnit: 0.19,
    volumeM3: 760,
    pinIds: [5_001],
    consumers: [JITA_CONSUMER],
    destination: 'colony',
    lastViewAt: '2026-11-26T08:00:00+00:00',
    dataAgeSeconds: 3600,
    dataStale: false,
    ...overrides,
  }
}

export function coolantFromAmarr(overrides: Partial<PendingOutput> = {}): PendingOutput {
  return pendingOutputOf(overrides)
}

export function coolantFromPerimeter(overrides: Partial<PendingOutput> = {}): PendingOutput {
  return pendingOutputOf({
    colonyId: 'colony-perimeter-ii',
    planetId: 40009100,
    planetName: 'Perimeter II',
    solarSystemName: 'Perimeter',
    quantity: 1500,
    volumeM3: 285,
    pinIds: [6_001],
    ...overrides,
  })
}

export function mechanicalPartsFromAmarr(overrides: Partial<PendingOutput> = {}): PendingOutput {
  return pendingOutputOf({
    typeId: MECHANICAL_PARTS,
    typeName: TYPE_NAMES[MECHANICAL_PARTS],
    quantity: 3000,
    volumePerUnit: 0.38,
    volumeM3: 1140,
    pinIds: [5_002],
    ...overrides,
  })
}

export function waterFromAmarr(overrides: Partial<PendingOutput> = {}): PendingOutput {
  return pendingOutputOf({
    typeId: WATER,
    typeName: TYPE_NAMES[WATER],
    quantity: 2200,
    volumePerUnit: 0.28,
    volumeM3: 616,
    pinIds: [5_003],
    ...overrides,
  })
}

export function unknownFertilizerFromAmarrVIII(overrides: Partial<PendingOutput> = {}): PendingOutput {
  return pendingOutputOf({
    colonyId: 'colony-amarr-viii',
    planetId: 40009200,
    planetName: 'Amarr VIII',
    typeId: FERTILIZER,
    typeName: TYPE_NAMES[FERTILIZER],
    quantity: null,
    volumePerUnit: 0.76,
    volumeM3: null,
    pinIds: [7_001],
    ...overrides,
  })
}

export function homePlasmoidsFromJitaVII(overrides: Partial<PendingOutput> = {}): PendingOutput {
  return pendingOutputOf({
    colonyId: 'colony-jita-vii',
    planetId: 40009400,
    planetName: 'Jita VII',
    solarSystemName: 'Jita',
    typeId: PLASMOIDS,
    typeName: TYPE_NAMES[PLASMOIDS],
    quantity: 250,
    volumePerUnit: 0.57,
    volumeM3: 142.5,
    pinIds: [8_001],
    consumers: [],
    destination: 'home',
    ...overrides,
  })
}

export function customsOfficeStockOf(overrides: Partial<CustomsOfficeStock> = {}): CustomsOfficeStock {
  return {
    typeId: COOLANT,
    typeName: TYPE_NAMES[COOLANT],
    quantity: 900,
    volumePerUnit: 0.19,
    volumeM3: 171,
    probablePlanet: { colonyId: 'colony-amarr-iii', planetName: 'Amarr III' },
    ...overrides,
  }
}

export function unlocatedWaterInCustomsOffice(overrides: Partial<CustomsOfficeStock> = {}): CustomsOfficeStock {
  return customsOfficeStockOf({
    typeId: WATER,
    typeName: TYPE_NAMES[WATER],
    quantity: 350,
    volumePerUnit: 0.28,
    volumeM3: 98,
    probablePlanet: null,
    ...overrides,
  })
}

export function pendingStockResponse(pendingOutputs: PendingOutput[], complete = true, customsOfficeStocks: CustomsOfficeStock[] = []) {
  return { id: 'pending-stock', storages: [], pendingOutputs, customsOfficeStocks, complete }
}

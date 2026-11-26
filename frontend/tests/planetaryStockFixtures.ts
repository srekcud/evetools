import type { PlanetaryStock, PlanetaryStockRow } from '@/stores/planetaryStock'
import { COOLANT, MECHANICAL_PARTS, ROBOTICS, WATER } from './planetaryPlannerFixtures'
import { AMARR_STATION_ID, AMARR_STATION_NAME, FAVORITE_HUB_ID, FAVORITE_HUB_NAME } from './planetarySettingsFixtures'

export const PLASMA = 91_051
export const BACTERIA = 91_061
export const OXYGEN = 91_062
export const SILICON = 91_071

export const COVERAGE_TARGET_WEEKS = 3

export function stockRow(typeId: number, typeName: string | null, overrides: Partial<PlanetaryStockRow>): PlanetaryStockRow {
  return {
    typeId,
    typeName,
    volumePerUnit: 1,
    weeklyConsumption: 100,
    home: 0,
    hub: 0,
    customsOffice: 0,
    corp: 0,
    openOrders: 0,
    coverageWeeks: 0,
    coverageWithOrdersWeeks: 0,
    toBuy: 300,
    ...overrides,
  }
}

export const ROBOTICS_ROW = stockRow(ROBOTICS, 'Robotics', {
  volumePerUnit: 0.5,
  weeklyConsumption: 333.4,
  home: 0,
  hub: 0,
  corp: 0,
  openOrders: 0,
  coverageWeeks: 0,
  coverageWithOrdersWeeks: 0,
  toBuy: 1001,
})

export const PLASMA_ROW = stockRow(PLASMA, 'Plasma', {
  volumePerUnit: 0.25,
  weeklyConsumption: 400,
  home: 300,
  hub: 100,
  corp: 0,
  openOrders: 200,
  coverageWeeks: 1,
  coverageWithOrdersWeeks: 1.5,
  toBuy: 600,
})

export const COOLANT_ROW = stockRow(COOLANT, 'Coolant', {
  volumePerUnit: 1.5,
  weeklyConsumption: 1000,
  home: 2400,
  hub: 500,
  corp: 100,
  openOrders: 0,
  coverageWeeks: 3,
  coverageWithOrdersWeeks: 3,
  toBuy: 0,
})

export const SILICON_ROW = stockRow(SILICON, 'Silicon', {
  volumePerUnit: 0.01,
  weeklyConsumption: 1000,
  home: 1500,
  hub: 500,
  customsOffice: 400,
  corp: 0,
  openOrders: 0,
  coverageWeeks: 2.4,
  coverageWithOrdersWeeks: 2.4,
  toBuy: 600,
})

export const WATER_ROW = stockRow(WATER, 'Water', {
  volumePerUnit: 0.1,
  weeklyConsumption: 800,
  home: 3600,
  hub: 0,
  corp: 0,
  openOrders: 0,
  coverageWeeks: 4.5,
  coverageWithOrdersWeeks: 4.5,
  toBuy: 0,
})

export const MECHANICAL_PARTS_ROW = stockRow(MECHANICAL_PARTS, 'Mechanical Parts', {
  volumePerUnit: 0.8,
  weeklyConsumption: 1000,
  home: 2900,
  hub: 0,
  corp: 0,
  openOrders: 0,
  coverageWeeks: 2.9,
  coverageWithOrdersWeeks: 2.9,
  toBuy: 100,
})

export const UNKNOWN_EXTRACTION_ROW = stockRow(BACTERIA, 'Bacteria', {
  volumePerUnit: 0.38,
  weeklyConsumption: null,
  home: 1200,
  hub: 0,
  corp: 0,
  openOrders: 0,
  coverageWeeks: null,
  coverageWithOrdersWeeks: null,
  toBuy: null,
})

export const NO_HOME_ROW = stockRow(OXYGEN, 'Oxygen', {
  volumePerUnit: 1.5,
  weeklyConsumption: 500,
  home: null,
  hub: 80,
  corp: null,
  openOrders: 0,
  coverageWeeks: null,
  coverageWithOrdersWeeks: null,
  toBuy: null,
})

export function stockOf(rows: PlanetaryStockRow[], overrides: Partial<PlanetaryStock> = {}): PlanetaryStock {
  return {
    homeLocationId: AMARR_STATION_ID,
    homeLocationName: AMARR_STATION_NAME,
    hubStructureId: FAVORITE_HUB_ID,
    hubStructureName: FAVORITE_HUB_NAME,
    coverageWeeks: COVERAGE_TARGET_WEEKS,
    corpUnknownReason: null,
    complete: true,
    rows,
    ...overrides,
  }
}

export const STOCK_WITH_PURCHASES = stockOf([ROBOTICS_ROW, PLASMA_ROW, COOLANT_ROW])

export const STOCK_OF_ALL_KINDS = stockOf([ROBOTICS_ROW, PLASMA_ROW, COOLANT_ROW, WATER_ROW, MECHANICAL_PARTS_ROW])

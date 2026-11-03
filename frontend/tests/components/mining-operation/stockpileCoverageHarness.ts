import type { StockpileCoverage, StockpileCoverageMineral } from '@/composables/useStockpileCoverage'

export const COVERED_OPERATION_ID = '4d2f8a61-3b7c-4e95-a0d8-6c1e9f2b7a53'
export const COVERAGE_PATH = `/mining-operations/${COVERED_OPERATION_ID}/stockpile-coverage`
export const TRITANIUM_TYPE_ID = 34
export const PYERITE_TYPE_ID = 35
export const MEXALLON_TYPE_ID = 36
export const ISOGEN_TYPE_ID = 37

export function coverageMineral(overrides: Partial<StockpileCoverageMineral>): StockpileCoverageMineral {
  return {
    typeId: TRITANIUM_TYPE_ID,
    typeName: 'Tritanium',
    producedQuantity: null,
    deficit: 0,
    hasDeficit: false,
    coveragePercent: null,
    coversWholeDeficit: null,
    coverageUnknownReason: null,
    ...overrides,
  }
}

export const TRITANIUM_PARTLY_COVERED = coverageMineral({
  typeId: TRITANIUM_TYPE_ID,
  typeName: 'Tritanium',
  producedQuantity: 963.036,
  deficit: 1700,
  hasDeficit: true,
  coveragePercent: 56.65,
  coversWholeDeficit: false,
})

export const PYERITE_WHOLLY_COVERED = coverageMineral({
  typeId: PYERITE_TYPE_ID,
  typeName: 'Pyerite',
  producedQuantity: 3600,
  deficit: 3000,
  hasDeficit: true,
  coveragePercent: 100,
  coversWholeDeficit: true,
})

export const MEXALLON_WITHOUT_DEFICIT = coverageMineral({
  typeId: MEXALLON_TYPE_ID,
  typeName: 'Mexallon',
  producedQuantity: 2603.14704,
  deficit: 0,
  hasDeficit: false,
  coveragePercent: null,
  coversWholeDeficit: false,
})

export const ISOGEN_WITH_UNKNOWN_PORTION_SIZE = coverageMineral({
  typeId: ISOGEN_TYPE_ID,
  typeName: 'Isogen',
  producedQuantity: null,
  deficit: 1200,
  hasDeficit: true,
  coveragePercent: null,
  coversWholeDeficit: null,
  coverageUnknownReason: 'portion_size_unknown',
})

export function stockpileCoverage(overrides: Partial<StockpileCoverage> = {}): StockpileCoverage {
  return {
    operationId: COVERED_OPERATION_ID,
    state: 'known',
    unknownReason: null,
    yieldKnown: true,
    reprocessYield: 0.723,
    minerals: [TRITANIUM_PARTLY_COVERED, PYERITE_WHOLLY_COVERED, MEXALLON_WITHOUT_DEFICIT, ISOGEN_WITH_UNKNOWN_PORTION_SIZE],
    ...overrides,
  }
}

export const COVERAGE_WITHOUT_YIELD = stockpileCoverage({
  yieldKnown: false,
  reprocessYield: null,
  minerals: [
    coverageMineral({ typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', deficit: 100000, hasDeficit: true }),
    coverageMineral({ typeId: PYERITE_TYPE_ID, typeName: 'Pyerite' }),
  ],
})

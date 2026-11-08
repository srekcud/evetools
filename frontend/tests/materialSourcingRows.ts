import type { MaterialSourcingRow, ProjectMaterialSourcing } from '@/stores/group-industry/types'

export const TRITANIUM_TYPE_ID = 34
export const NOCXIUM_TYPE_ID = 38
export const MEXALLON_TYPE_ID = 36
export const MORPHITE_TYPE_ID = 11399

export const STRUCTURE_ID = 1035466543993
export const STRUCTURE_NAME = 'Perimeter - Tranquility Trading Tower'
export const STRUCTURE_SYNCED_AT = '2026-11-08T10:00:00+00:00'

export const TRITANIUM_AT_JITA: MaterialSourcingRow = {
  typeId: TRITANIUM_TYPE_ID,
  typeName: 'Tritanium',
  quantity: 12000,
  volumePerUnit: 0.01,
  jumps: 2,
  jitaTotal: 60000,
  importTotal: 36000,
  jitaWithImportTotal: 96000,
  structureTotal: 120000,
  bestTotal: 96000,
  side: 'jita',
  structureFallback: false,
  saving: 24000,
  missingData: [],
  warnings: [],
}

export const MORPHITE_AT_STRUCTURE: MaterialSourcingRow = {
  typeId: MORPHITE_TYPE_ID,
  typeName: 'Morphite',
  quantity: 147,
  volumePerUnit: 0.1,
  jumps: 2,
  jitaTotal: 2646000,
  importTotal: 4410,
  jitaWithImportTotal: 2650410,
  structureTotal: 2499000,
  bestTotal: 2499000,
  side: 'structure',
  structureFallback: false,
  saving: 151410,
  missingData: [],
  warnings: [],
}

export const NOCXIUM_UNKNOWN: MaterialSourcingRow = {
  typeId: NOCXIUM_TYPE_ID,
  typeName: 'Nocxium',
  quantity: 600,
  volumePerUnit: null,
  jumps: 2,
  jitaTotal: null,
  importTotal: null,
  jitaWithImportTotal: null,
  structureTotal: null,
  bestTotal: null,
  side: 'unknown',
  structureFallback: false,
  saving: null,
  missingData: [{ reason: 'market_price', typeId: NOCXIUM_TYPE_ID }],
  warnings: [],
}

export const MEXALLON_FALLBACK_TO_JITA: MaterialSourcingRow = {
  typeId: MEXALLON_TYPE_ID,
  typeName: 'Mexallon',
  quantity: 100,
  volumePerUnit: 0.01,
  jumps: 2,
  jitaTotal: 10000,
  importTotal: 300,
  jitaWithImportTotal: 10300,
  structureTotal: null,
  bestTotal: 10300,
  side: 'jita',
  structureFallback: true,
  saving: null,
  missingData: [],
  warnings: [],
}

export function projectMaterialSourcing(
  projectId: string,
  rows: MaterialSourcingRow[],
  overrides: Partial<ProjectMaterialSourcing> = {},
): ProjectMaterialSourcing {
  return {
    id: projectId,
    importIskPerCubicMeterJump: 150,
    structureId: STRUCTURE_ID,
    structureName: STRUCTURE_NAME,
    structureSynced: true,
    structureLastSync: STRUCTURE_SYNCED_AT,
    totals: { jitaWithImport: 2746710, structure: 2619000, best: 2595300 },
    rows,
    ...overrides,
  }
}

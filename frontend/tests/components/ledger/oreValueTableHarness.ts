import type { OreValueRow } from '@/composables/useOreValueTable'

export const VELDSPAR_TYPE_ID = 1230
export const CLEAR_ICICLE_TYPE_ID = 16262
export const FULLERITE_C50_TYPE_ID = 30370
export const BITUMENS_TYPE_ID = 45492
export const CONCENTRATED_VELDSPAR_TYPE_ID = 17470
export const TRITANIUM_TYPE_ID = 34

export function oreValueRow(overrides: Partial<OreValueRow>): OreValueRow {
  return {
    typeId: VELDSPAR_TYPE_ID,
    typeName: 'Veldspar',
    kind: 'ore',
    groupName: 'Veldspar',
    volume: 0.1,
    rawIskPerM3: null,
    compressedIskPerM3: null,
    reprocessedIskPerM3: null,
    bestOption: null,
    bestIskPerM3: null,
    gapToBestPercent: null,
    reprocessable: true,
    minerals: [],
    ...overrides,
  }
}

export const VELDSPAR_ROW = oreValueRow({
  rawIskPerM3: 183.7,
  compressedIskPerM3: 196.1,
  reprocessedIskPerM3: 133.224,
  bestOption: 'compressed',
  bestIskPerM3: 196.1,
  gapToBestPercent: 92.85,
  minerals: [{ typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantityPerUnit: 4 }],
})

export const CLEAR_ICICLE_ROW = oreValueRow({
  typeId: CLEAR_ICICLE_TYPE_ID,
  typeName: 'Clear Icicle',
  kind: 'ice',
  groupName: 'Ice',
  volume: 1000,
  rawIskPerM3: 152.3,
  compressedIskPerM3: 158.7,
  reprocessedIskPerM3: null,
  bestOption: 'compressed',
  bestIskPerM3: 158.7,
  gapToBestPercent: 94.21,
})

export const FULLERITE_C50_ROW = oreValueRow({
  typeId: FULLERITE_C50_TYPE_ID,
  typeName: 'Fullerite-C50',
  kind: 'gas',
  groupName: 'Harvestable Cloud',
  volume: 1,
  rawIskPerM3: 2741,
  bestOption: 'raw',
  bestIskPerM3: 2741,
  gapToBestPercent: 0,
  reprocessable: false,
})

export const BITUMENS_ROW = oreValueRow({
  typeId: BITUMENS_TYPE_ID,
  typeName: 'Bitumens',
  groupName: 'Ubiquitous Moon Asteroids',
  volume: 10,
  rawIskPerM3: 21.39,
  compressedIskPerM3: 22.145,
  reprocessedIskPerM3: 0,
  bestOption: 'compressed',
  bestIskPerM3: 22.145,
  gapToBestPercent: 99.19,
})

export const UNPRICED_CONCENTRATED_VELDSPAR_ROW = oreValueRow({
  typeId: CONCENTRATED_VELDSPAR_TYPE_ID,
  typeName: 'Concentrated Veldspar',
})

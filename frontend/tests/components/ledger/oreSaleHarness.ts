import type { OreSaleResponse, OreSaleRow } from '@/composables/useOreSale'

export const COMPRESSED_VELDSPAR_TYPE_ID = 62516
export const COMPRESSED_BITUMENS_TYPE_ID = 62454
export const COMPRESSED_CLEAR_ICICLE_TYPE_ID = 28434
export const COMPRESSED_FULLERITE_C50_TYPE_ID = 62399
export const SALE_STRUCTURE_ID = 1000000000001

export function oreSaleRow(overrides: Partial<OreSaleRow>): OreSaleRow {
  return {
    typeId: COMPRESSED_VELDSPAR_TYPE_ID,
    typeName: 'Compressed Veldspar',
    quantity: 1,
    unitVolume: 0.001,
    totalVolume: 0.001,
    jitaBuyPrice: null,
    jitaSellPrice: null,
    structureBuyPrice: null,
    structureSellPrice: null,
    bestPrice: null,
    bestSource: null,
    totalValue: null,
    ...overrides,
  }
}

export const CLEAR_ICICLE_SALE_ROW = oreSaleRow({
  typeId: COMPRESSED_CLEAR_ICICLE_TYPE_ID,
  typeName: 'Compressed Clear Icicle',
  quantity: 5,
  unitVolume: 100,
  totalVolume: 500,
  jitaBuyPrice: 175000,
  jitaSellPrice: 180000,
  bestPrice: 180000,
  bestSource: 'jita_sell',
  totalValue: 900000,
})

export const VELDSPAR_SALE_ROW = oreSaleRow({
  quantity: 20000,
  totalVolume: 20,
  jitaBuyPrice: 17,
  jitaSellPrice: 19,
  bestPrice: 19,
  bestSource: 'jita_sell',
  totalValue: 380000,
})

export const UNPRICED_BITUMENS_SALE_ROW = oreSaleRow({
  typeId: COMPRESSED_BITUMENS_TYPE_ID,
  typeName: 'Compressed Bitumens',
  quantity: 350,
  unitVolume: 0.1,
  totalVolume: 35,
})

export const PRICED_SALE: OreSaleResponse = {
  structureId: null,
  rows: [CLEAR_ICICLE_SALE_ROW, VELDSPAR_SALE_ROW],
  totals: {
    totalQuantity: 20005,
    totalVolume: 520,
    totalValue: 1280000,
    missingPriceTypeIds: [],
    missingVolumeTypeIds: [],
  },
}

export const SALE_WITH_UNPRICED_BITUMENS: OreSaleResponse = {
  structureId: null,
  rows: [VELDSPAR_SALE_ROW, UNPRICED_BITUMENS_SALE_ROW],
  totals: {
    totalQuantity: 20350,
    totalVolume: 55,
    totalValue: null,
    missingPriceTypeIds: [COMPRESSED_BITUMENS_TYPE_ID],
    missingVolumeTypeIds: [],
  },
}

export const STRUCTURE_SALE: OreSaleResponse = {
  structureId: SALE_STRUCTURE_ID,
  rows: [
    { ...CLEAR_ICICLE_SALE_ROW, structureBuyPrice: 176500, structureSellPrice: 182000, bestPrice: 182000, bestSource: 'structure_sell', totalValue: 910000 },
    { ...VELDSPAR_SALE_ROW, structureBuyPrice: 21, structureSellPrice: 20.5, bestPrice: 21, bestSource: 'structure_buy', totalValue: 420000 },
  ],
  totals: {
    totalQuantity: 20005,
    totalVolume: 520,
    totalValue: 1330000,
    missingPriceTypeIds: [],
    missingVolumeTypeIds: [],
  },
}

export const EMPTY_SALE: OreSaleResponse = {
  structureId: null,
  rows: [],
  totals: {
    totalQuantity: 0,
    totalVolume: 0,
    totalValue: 0,
    missingPriceTypeIds: [],
    missingVolumeTypeIds: [],
  },
}

export const PRICED_SALE_SUMMARY = [
  'Compressed Clear Icicle\t5',
  'Compressed Veldspar\t20000',
  'Total volume: 520.00',
  'Reference value: 1280000.00',
].join('\n')

import { ref } from 'vue'
import { readStoredReprocessYield, useOreValueTable } from '@/composables/useOreValueTable'
import type { OreValueRow } from '@/composables/useOreValueTable'
import {
  BITUMENS_ROW,
  BITUMENS_TYPE_ID,
  CLEAR_ICICLE_ROW,
  CLEAR_ICICLE_TYPE_ID,
  CONCENTRATED_VELDSPAR_TYPE_ID,
  FULLERITE_C50_ROW,
  FULLERITE_C50_TYPE_ID,
  UNPRICED_CONCENTRATED_VELDSPAR_ROW,
  VELDSPAR_ROW,
  VELDSPAR_TYPE_ID,
} from '../components/ledger/oreValueTableHarness'

const SERVER_ORDER: OreValueRow[] = [FULLERITE_C50_ROW, VELDSPAR_ROW, CLEAR_ICICLE_ROW, BITUMENS_ROW, UNPRICED_CONCENTRATED_VELDSPAR_ROW]
const UNSORTED: OreValueRow[] = [UNPRICED_CONCENTRATED_VELDSPAR_ROW, BITUMENS_ROW, VELDSPAR_ROW, CLEAR_ICICLE_ROW, FULLERITE_C50_ROW]

function typeIdsOf(rows: OreValueRow[]): number[] {
  return rows.map(row => row.typeId)
}

describe('useOreValueTable sorting by column', () => {
  it('keeps the order of the API response until a column is chosen', () => {
    const { visibleRows } = useOreValueTable(ref(SERVER_ORDER))

    expect(typeIdsOf(visibleRows.value)).toEqual([FULLERITE_C50_TYPE_ID, VELDSPAR_TYPE_ID, CLEAR_ICICLE_TYPE_ID, BITUMENS_TYPE_ID, CONCENTRATED_VELDSPAR_TYPE_ID])
  })

  it('sorts the raw value per mined m³ in descending order with unknown values after the known ones', () => {
    const { visibleRows, sortColumn, sortDirection } = useOreValueTable(ref(UNSORTED))

    sortColumn.value = 'rawIskPerM3'
    sortDirection.value = 'desc'

    expect(typeIdsOf(visibleRows.value)).toEqual([FULLERITE_C50_TYPE_ID, VELDSPAR_TYPE_ID, CLEAR_ICICLE_TYPE_ID, BITUMENS_TYPE_ID, CONCENTRATED_VELDSPAR_TYPE_ID])
  })

  it('keeps unknown values after the known ones when the reprocessed value is sorted in ascending order', () => {
    const { visibleRows, sortColumn, sortDirection } = useOreValueTable(ref(UNSORTED))

    sortColumn.value = 'reprocessedIskPerM3'
    sortDirection.value = 'asc'

    const sorted = typeIdsOf(visibleRows.value)
    expect(sorted.slice(0, 2)).toEqual([BITUMENS_TYPE_ID, VELDSPAR_TYPE_ID])
    expect([...sorted.slice(2)].sort((a, b) => a - b)).toEqual([CLEAR_ICICLE_TYPE_ID, CONCENTRATED_VELDSPAR_TYPE_ID, FULLERITE_C50_TYPE_ID].sort((a, b) => a - b))
  })

  it('sorts the compressed value per mined m³ in ascending order with unknown values last', () => {
    const { visibleRows, sortColumn, sortDirection } = useOreValueTable(ref(UNSORTED))

    sortColumn.value = 'compressedIskPerM3'
    sortDirection.value = 'asc'

    const sorted = typeIdsOf(visibleRows.value)
    expect(sorted.slice(0, 3)).toEqual([BITUMENS_TYPE_ID, CLEAR_ICICLE_TYPE_ID, VELDSPAR_TYPE_ID])
    expect([...sorted.slice(3)].sort((a, b) => a - b)).toEqual([CONCENTRATED_VELDSPAR_TYPE_ID, FULLERITE_C50_TYPE_ID])
  })

  it('sorts by ore name in ascending order', () => {
    const { visibleRows, sortColumn, sortDirection } = useOreValueTable(ref(UNSORTED))

    sortColumn.value = 'typeName'
    sortDirection.value = 'asc'

    expect(typeIdsOf(visibleRows.value)).toEqual([BITUMENS_TYPE_ID, CLEAR_ICICLE_TYPE_ID, CONCENTRATED_VELDSPAR_TYPE_ID, FULLERITE_C50_TYPE_ID, VELDSPAR_TYPE_ID])
  })
})

describe('useOreValueTable family filter', () => {
  it('shows only the ice rows when the ice family is chosen', () => {
    const { visibleRows, kindFilter } = useOreValueTable(ref(UNSORTED))

    kindFilter.value = 'ice'

    expect(typeIdsOf(visibleRows.value)).toEqual([CLEAR_ICICLE_TYPE_ID])
  })

  it('shows the ore rows, moon ores included, when the ore family is chosen', () => {
    const { visibleRows, kindFilter } = useOreValueTable(ref(UNSORTED))

    kindFilter.value = 'ore'

    expect(typeIdsOf(visibleRows.value)).toEqual([CONCENTRATED_VELDSPAR_TYPE_ID, BITUMENS_TYPE_ID, VELDSPAR_TYPE_ID])
  })
})

describe('readStoredReprocessYield', () => {
  afterEach(() => {
    localStorage.clear()
  })

  it('converts the stored percentage into a yield between 0 and 1', () => {
    localStorage.setItem('miningReprocessYield', '78')

    expect(readStoredReprocessYield()).toBeCloseTo(0.78, 12)
  })

  it('keeps the decimals of a stored percentage', () => {
    localStorage.setItem('miningReprocessYield', '82.5')

    expect(readStoredReprocessYield()).toBeCloseTo(0.825, 12)
  })

  it('leaves the yield unknown when the stored yield is 0 %', () => {
    localStorage.setItem('miningReprocessYield', '0')

    expect(readStoredReprocessYield()).toBeNull()
  })

  it('leaves the yield unknown when nothing is stored', () => {
    expect(readStoredReprocessYield()).toBeNull()
  })

  it('leaves the yield unknown when the stored value is not a number', () => {
    localStorage.setItem('miningReprocessYield', 'abc')

    expect(readStoredReprocessYield()).toBeNull()
  })
})

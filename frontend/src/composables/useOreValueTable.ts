import { computed, ref, type Ref } from 'vue'

export type OreKind = 'ore' | 'ice' | 'gas'

export type OreValueOption = 'raw' | 'compressed' | 'reprocessed'

export interface OreValueMineral {
  typeId: number
  typeName: string | null
  quantityPerUnit: number
}

export interface OreValueRow {
  typeId: number
  typeName: string
  kind: OreKind
  groupName: string
  volume: number | null
  rawIskPerM3: number | null
  compressedIskPerM3: number | null
  reprocessedIskPerM3: number | null
  bestOption: OreValueOption | null
  bestIskPerM3: number | null
  gapToBestPercent: number | null
  reprocessable: boolean
  minerals: OreValueMineral[]
}

export type OreValueSortColumn =
  | 'typeName'
  | 'volume'
  | 'rawIskPerM3'
  | 'compressedIskPerM3'
  | 'reprocessedIskPerM3'
  | 'bestIskPerM3'
  | 'gapToBestPercent'

export type SortDirection = 'asc' | 'desc'

export function readStoredReprocessYield(): number | null {
  return -1
}

export function useOreValueTable(rows: Ref<OreValueRow[]>) {
  const sortColumn = ref<OreValueSortColumn | null>(null)
  const sortDirection = ref<SortDirection>('desc')
  const kindFilter = ref<OreKind | null>(null)
  const visibleRows = computed(() => rows.value)

  return { sortColumn, sortDirection, kindFilter, visibleRows }
}

import { computed, ref, type Ref } from 'vue'

export type OreKind = 'ore' | 'ice' | 'gas'

export type OreValueOption = 'raw' | 'compressed' | 'reprocessed'

export interface OreValueMineral {
  typeId: number
  typeName: string | null
  quantityPerUnit: number | null
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

export type OreValueMarket = 'jita' | 'structure'

export type OreValueSide = 'buy' | 'sell'

export interface OreValueTableResponse {
  market: OreValueMarket
  side: OreValueSide
  structureId: number | null
  reprocessYieldKnown: boolean
  rows: OreValueRow[]
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

function compareKnownFirst(a: number | null, b: number | null, direction: SortDirection): number {
  if (a === null || b === null) return Number(a === null) - Number(b === null)
  return direction === 'asc' ? a - b : b - a
}

export function useOreValueTable(rows: Ref<OreValueRow[]>) {
  const sortColumn = ref<OreValueSortColumn | null>(null)
  const sortDirection = ref<SortDirection>('desc')
  const kindFilter = ref<OreKind | null>(null)

  const visibleRows = computed(() => {
    const filtered = kindFilter.value === null
      ? rows.value
      : rows.value.filter(row => row.kind === kindFilter.value)
    const column = sortColumn.value
    if (column === null) return filtered
    const direction = sortDirection.value
    return [...filtered].sort((a, b) => {
      if (column === 'typeName') {
        const order = a.typeName.localeCompare(b.typeName)
        return direction === 'asc' ? order : -order
      }
      return compareKnownFirst(a[column], b[column], direction)
    })
  })

  return { sortColumn, sortDirection, kindFilter, visibleRows }
}

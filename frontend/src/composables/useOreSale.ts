import { computed, ref } from 'vue'

export type OreSaleBestSource = 'jita_buy' | 'jita_sell' | 'structure_buy' | 'structure_sell'

export interface OreSaleRow {
  typeId: number
  typeName: string | null
  quantity: number
  unitVolume: number | null
  totalVolume: number | null
  jitaBuyPrice: number | null
  jitaSellPrice: number | null
  structureBuyPrice: number | null
  structureSellPrice: number | null
  bestPrice: number | null
  bestSource: OreSaleBestSource | null
  totalValue: number | null
}

export interface OreSaleTotals {
  totalQuantity: number
  totalVolume: number | null
  totalValue: number | null
  missingPriceTypeIds: number[]
  missingVolumeTypeIds: number[]
}

export interface OreSaleResponse {
  structureId: number | null
  rows: OreSaleRow[]
  totals: OreSaleTotals
}

export function buildSaleSummary(_response: OreSaleResponse): string {
  return ''
}

export function useOreSale() {
  const sale = ref<OreSaleResponse | null>(null)
  const isLoading = ref(false)
  const hasError = ref(false)
  const isEmpty = computed(() => false)
  const summary = computed(() => '')

  async function load(): Promise<void> {}

  return { sale, isLoading, hasError, isEmpty, summary, load }
}

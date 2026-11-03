import { computed, ref } from 'vue'
import { apiRequest } from '@/services/api'

const SELECTED_STRUCTURE_STORAGE_KEY = 'miningSelectedStructure'
const SUMMARY_DECIMALS = 2
const UNKNOWN_SUMMARY_VALUE = 'unknown'

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

function summaryTypeLabel(typeId: number, typeName: string | null): string {
  return typeName ?? String(typeId)
}

function summaryAmount(amount: number | null): string {
  return amount === null ? UNKNOWN_SUMMARY_VALUE : amount.toFixed(SUMMARY_DECIMALS)
}

export function buildSaleSummary(response: OreSaleResponse): string {
  const { rows, totals } = response
  const lines = rows.map(row => `${summaryTypeLabel(row.typeId, row.typeName)}\t${row.quantity}`)
  lines.push(`Total volume: ${summaryAmount(totals.totalVolume)}`)
  lines.push(`Reference value: ${summaryAmount(totals.totalValue)}`)
  if (totals.missingPriceTypeIds.length > 0) {
    const namesByTypeId = new Map(rows.map(row => [row.typeId, row.typeName]))
    const missingPrices = totals.missingPriceTypeIds.map(typeId => summaryTypeLabel(typeId, namesByTypeId.get(typeId) ?? null))
    lines.push(`Missing prices: ${missingPrices.join(', ')}`)
  }
  return lines.join('\n')
}

function selectedStructureId(): number | null {
  const stored = localStorage.getItem(SELECTED_STRUCTURE_STORAGE_KEY)
  if (stored === null) return null
  const structure = JSON.parse(stored) as { id: number | null }
  return structure.id
}

export function useOreSale() {
  const sale = ref<OreSaleResponse | null>(null)
  const isLoading = ref(false)
  const hasError = ref(false)
  const isEmpty = computed(() => sale.value !== null && sale.value.rows.length === 0)
  const summary = computed(() => sale.value === null ? '' : buildSaleSummary(sale.value))

  async function load(): Promise<void> {
    isLoading.value = true
    hasError.value = false
    try {
      const structureId = selectedStructureId()
      const query = structureId === null ? '' : `?structureId=${structureId}`
      sale.value = await apiRequest<OreSaleResponse>(`/mining/ore-sale${query}`)
    } catch (e) {
      console.error('Failed to load the ore sale:', e)
      sale.value = null
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  return { sale, isLoading, hasError, isEmpty, summary, load }
}

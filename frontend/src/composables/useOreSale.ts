import { computed, ref } from 'vue'
import { ApiError, apiRequest } from '@/services/api'
import { useMiningValuationProfileStore } from '@/stores/miningValuationProfile'

const SUMMARY_DECIMALS = 2
const UNKNOWN_SUMMARY_VALUE = 'unknown'
const CONFLICT_STATUS = 409

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

export interface OreSaleUnconvertedType {
  typeId: number
  typeName: string | null
}

export interface OreSaleResponse {
  operationId?: string | null
  structureId: number | null
  rows: OreSaleRow[]
  totals: OreSaleTotals
  unconvertedTypes?: OreSaleUnconvertedType[]
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

export function useOreSale(operationId?: string) {
  const profileStore = useMiningValuationProfileStore()
  const sale = ref<OreSaleResponse | null>(null)
  const isLoading = ref(false)
  const hasError = ref(false)
  const notKnownYet = ref(false)
  const isEmpty = computed(() => sale.value !== null && sale.value.rows.length === 0)
  const summary = computed(() => sale.value === null ? '' : buildSaleSummary(sale.value))

  async function load(): Promise<void> {
    isLoading.value = true
    hasError.value = false
    notKnownYet.value = false
    try {
      if (profileStore.profile === null) await profileStore.load()
      const structureId = profileStore.profile?.structureId ?? null
      const query = structureId === null ? '' : `?structureId=${structureId}`
      const endpoint = operationId === undefined ? '/mining/ore-sale' : `/mining-operations/${operationId}/ore-sale`
      const response = await apiRequest<OreSaleResponse | undefined>(`${endpoint}${query}`)
      if (!response || !Array.isArray(response.rows)) throw new Error('Unexpected ore sale payload')
      sale.value = response
    } catch (e) {
      sale.value = null
      if (e instanceof ApiError && e.status === CONFLICT_STATUS) {
        notKnownYet.value = true
      } else {
        console.error('Failed to load the ore sale:', e)
        hasError.value = true
      }
    } finally {
      isLoading.value = false
    }
  }

  return { sale, isLoading, hasError, notKnownYet, isEmpty, summary, load }
}

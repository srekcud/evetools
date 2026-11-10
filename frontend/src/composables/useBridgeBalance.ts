import { computed, ref } from 'vue'
import { useLedgerStore, type LedgerDashboard } from '@/stores/ledger'
import type { BridgeCardStatus } from '@/composables/bridgeCardStatus'

export const BRIDGE_BALANCE_DAYS = 30

export type BridgeBalanceRowId = 'pve' | 'mining' | 'industry' | 'total'

export interface BridgeBalanceRow {
  id: BridgeBalanceRowId
  amount: number | null
}

const ROW_IDS: BridgeBalanceRowId[] = ['pve', 'mining', 'industry', 'total']

function amountOf(totals: LedgerDashboard['totals'], id: BridgeBalanceRowId): number | null {
  const amount: unknown = totals[id]
  return typeof amount === 'number' ? amount : null
}

export function useBridgeBalance() {
  const store = useLedgerStore()
  const isSettled = ref(false)

  const totals = computed<LedgerDashboard['totals'] | null>(() => {
    const totalsOfPeriod: unknown = store.dashboard?.totals
    return isSettled.value && store.error === null && typeof totalsOfPeriod === 'object' && totalsOfPeriod !== null
      ? totalsOfPeriod as LedgerDashboard['totals']
      : null
  })

  const status = computed<BridgeCardStatus>(() => {
    if (!isSettled.value) return 'loading'
    return totals.value === null ? 'unknown' : 'loaded'
  })

  const rows = computed<BridgeBalanceRow[]>(() => {
    const current = totals.value
    return current === null ? [] : ROW_IDS.map(id => ({ id, amount: amountOf(current, id) }))
  })

  async function load(): Promise<void> {
    await store.fetchDashboard(BRIDGE_BALANCE_DAYS)
    isSettled.value = true
  }

  void load()

  return { status, rows }
}

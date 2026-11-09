import type { ClosingChecksData } from '@/stores/group-industry/types'

export type ClosingCheck =
  | { key: 'pendingContributions'; ok: boolean; count: number }
  | { key: 'unsoldProducts'; ok: boolean; products: ClosingChecksData['unsoldProducts'] }
  | { key: 'finalMargin'; ok: boolean; marginPercent: number | null }
  | { key: 'transfers'; ok: boolean; received: number | null; total: number | null }

export function buildClosingChecks(checks: ClosingChecksData): ClosingCheck[] {
  const transfersKnown = checks.transfersTotal !== null && checks.transfersReceived !== null
  return [
    { key: 'pendingContributions', ok: checks.pendingContributionCount === 0, count: checks.pendingContributionCount },
    { key: 'unsoldProducts', ok: checks.unsoldProducts.length === 0, products: checks.unsoldProducts },
    { key: 'finalMargin', ok: checks.marginPercent !== null, marginPercent: checks.marginPercent },
    {
      key: 'transfers',
      ok: transfersKnown && checks.transfersReceived === checks.transfersTotal,
      received: checks.transfersReceived,
      total: checks.transfersTotal,
    },
  ]
}

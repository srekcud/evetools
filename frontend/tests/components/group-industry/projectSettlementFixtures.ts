import type { ClosingChecksData, ProjectSettlement, ProjectTransfer } from '@/stores/group-industry/types'

export const SETTLEMENT_REFERENCE = 'IP-4C81D2'

export function projectTransfer(overrides: Partial<ProjectTransfer> = {}): ProjectTransfer {
  return {
    fromParticipantId: 'member-aria',
    fromName: 'Aria Solberg',
    toParticipantId: 'member-brann',
    toName: 'Brann Holt',
    amount: 277_500,
    received: false,
    journalVerified: false,
    receivedAt: null,
    canConfirm: false,
    ...overrides,
  }
}

export function closingChecks(overrides: Partial<ClosingChecksData> = {}): ClosingChecksData {
  return {
    pendingContributionCount: 0,
    unsoldProducts: [],
    marginPercent: 55,
    transfersTotal: 2,
    transfersReceived: 2,
    ...overrides,
  }
}

export function projectSettlement(overrides: Partial<ProjectSettlement> = {}): ProjectSettlement {
  return {
    reference: SETTLEMENT_REFERENCE,
    transfers: [projectTransfer()],
    transfersUnavailableReason: null,
    roundingGap: 0,
    checks: closingChecks({ transfersTotal: 1, transfersReceived: 0 }),
    ...overrides,
  }
}

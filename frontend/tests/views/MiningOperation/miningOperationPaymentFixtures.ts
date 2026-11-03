import type { MiningArchiveSummary, MiningOperation, MiningOperationValuation, MiningPayment } from '@/stores/miningOperation'
import { ENDED_AT, OPERATION_O, OPERATION_P2, startedSpodumainOperation, valuationSettings } from './miningOperationLedgerFixtures'
import { FROZEN_AT, e1Participants, e1Valuation, eachForThemselvesE1Valuation } from './miningOperationValuationFixtures'

export const PAYMENTS_PATH = `/mining-operations/${OPERATION_O}/payments`
export const ARCHIVE_PATH = '/mining-operations/archive'
export const PAID_AT = '2026-11-05T22:24:00+00:00'
export const SETTLED_AT = '2026-11-05T23:35:00+00:00'
export const COMPLETED_AT = '2026-11-05T21:58:00+00:00'
export const CORPORATION_NAME = 'Corpo Exemple'
export const P2_LABEL = 'Ceinture Veldspar — mardi'

export const G1_SETTINGS = valuationSettings({ valuationSide: 'sell', boosterCutPercent: 10, corporationCutPercent: 5, boosterMemberId: 'member-cyra', boosterCharacterName: 'Cyra Lune' })

export function payment(position: number, overrides: Partial<MiningPayment> = {}): MiningPayment {
  return {
    id: `payment-${position}`,
    position,
    kind: 'booster_cut',
    payerMemberId: null,
    payerName: null,
    beneficiaryMemberId: 'member-cyra',
    beneficiaryName: 'Cyra Lune',
    amount: 0,
    paid: false,
    paidAt: null,
    paidByName: null,
    ...overrides,
  }
}

export function g1Payments(paidPositions: number[] = []): MiningPayment[] {
  const paidByAria = { paid: true, paidAt: PAID_AT, paidByName: 'Aria Solberg' }
  return [
    payment(0, { payerMemberId: 'member-aria', payerName: 'Aria Solberg', amount: 404_864, ...(paidPositions.includes(0) ? paidByAria : {}) }),
    payment(1, { payerMemberId: 'member-brann', payerName: 'Brann Holt', amount: 430_269, ...(paidPositions.includes(1) ? paidByAria : {}) }),
    payment(2, {
      kind: 'collected_corporation_cut',
      payerMemberId: 'member-cyra',
      payerName: 'Cyra Lune',
      beneficiaryMemberId: null,
      beneficiaryName: CORPORATION_NAME,
      amount: 278_386,
      ...(paidPositions.includes(2) ? paidByAria : {}),
    }),
  ]
}

export function g1Valuation(overrides: Partial<MiningOperationValuation> = {}): MiningOperationValuation {
  const shares = [2_294_229, 2_438_191, 150]
  return e1Valuation({
    status: 'shares',
    frozen: true,
    sharesFrozenAt: FROZEN_AT,
    pricesReadAt: FROZEN_AT,
    settings: G1_SETTINGS,
    corporationCut: 278_386,
    participants: e1Participants().map((participant, index) => ({ ...participant, share: shares[index] ?? null })),
    canFreeze: false,
    freezeBlockers: ['not_closing'],
    ...overrides,
  })
}

export function g1Operation(overrides: Partial<MiningOperation> = {}): MiningOperation {
  return startedSpodumainOperation({
    status: 'shares',
    endedAt: ENDED_AT,
    participantsCount: 3,
    charactersCount: 5,
    valuationSettings: G1_SETTINGS,
    sharesFrozenAt: FROZEN_AT,
    ...overrides,
  })
}

export function settledArchiveSummary(overrides: Partial<MiningArchiveSummary> = {}): MiningArchiveSummary {
  return {
    archivedAt: SETTLED_AT,
    archivedByName: 'Aria Solberg',
    totalValue: 5_567_729,
    myMinedValue: 2_699_093,
    myShare: 2_294_229,
    paymentsCount: 3,
    paidPaymentsCount: 3,
    ...overrides,
  }
}

export function settledG1Operation(overrides: Partial<MiningOperation> = {}): MiningOperation {
  return g1Operation({ status: 'settled', archivedAt: SETTLED_AT, archivedByName: 'Aria Solberg', archiveSummary: settledArchiveSummary(), ...overrides })
}

export function completedE1Operation(overrides: Partial<MiningOperation> = {}): MiningOperation {
  return startedSpodumainOperation({
    status: 'completed',
    splitMode: 'each_for_themselves',
    endedAt: ENDED_AT,
    participantsCount: 3,
    charactersCount: 5,
    valuationSettings: valuationSettings({ valuationSide: 'sell' }),
    sharesFrozenAt: COMPLETED_AT,
    archivedAt: COMPLETED_AT,
    archivedByName: 'Aria Solberg',
    archiveSummary: {
      archivedAt: COMPLETED_AT,
      archivedByName: 'Aria Solberg',
      totalValue: 5_567_729,
      myMinedValue: 2_699_093,
      myShare: null,
      paymentsCount: 0,
      paidPaymentsCount: 0,
    },
    ...overrides,
  })
}

export function completedE1Valuation(): MiningOperationValuation {
  return { ...eachForThemselvesE1Valuation(), status: 'completed', frozen: true, sharesFrozenAt: COMPLETED_AT, pricesReadAt: COMPLETED_AT, freezeBlockers: ['not_closing'], canComplete: false, completionBlockers: [] }
}

export function closingEachForThemselvesWithBrannUnknown(): MiningOperationValuation {
  const valuation = eachForThemselvesE1Valuation()
  return {
    ...valuation,
    totalValue: null,
    iskPerHour: null,
    participants: valuation.participants.map(participant =>
      participant.characterName === 'Brann Holt' ? { ...participant, quantityState: 'unknown', totalQuantity: null, minedValue: null, iskPerHour: null } : participant,
    ),
    canComplete: false,
    completionBlockers: ['quantities_not_known'],
  }
}

export function completedP2Operation(): MiningOperation {
  return completedE1Operation({
    id: OPERATION_P2,
    label: P2_LABEL,
    archivedAt: '2026-11-04T22:10:00+00:00',
    archiveSummary: {
      archivedAt: '2026-11-04T22:10:00+00:00',
      archivedByName: 'Aria Solberg',
      totalValue: 812_300,
      myMinedValue: 812_300,
      myShare: null,
      paymentsCount: 0,
      paidPaymentsCount: 0,
    },
  })
}

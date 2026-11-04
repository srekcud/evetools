import type {
  MiningBoostShipOption,
  MiningFleetStorage,
  MiningFleetStorageDeclaration,
  MiningFleetStorageVerification,
  MiningOperation,
  MiningOperationLedger,
  MiningOperationValuation,
  MiningPayment,
} from '@/stores/miningOperation'
import { BOOST_SHIPS_PATH, ENDED_AT, OPERATION_O, boostShipCatalogue, ledger, ledgerCharacter, participant, startedSpodumainOperation } from './miningOperationLedgerFixtures'
import { BITUMENS_TYPE_ID, CYRA_BOOSTING_ON_JITA_SELL, FROZEN_AT, VELDSPAR_TYPE_ID, e1Participants, e1Valuation } from './miningOperationValuationFixtures'
import { payment } from './miningOperationPaymentFixtures'

export const FLEET_STORAGE_PATH = `/mining-operations/${OPERATION_O}/fleet-storage`
export const VERIFICATION_PATH = `/mining-operations/${OPERATION_O}/fleet-storage/verification`
export const CORPORATION_ASSETS_REFRESH_PATH = '/me/corporation/assets/refresh'
export const CONTAINER_NAME = 'OPS-HAUL'
export const ASSETS_READ_AT = '2026-11-05T21:41:00+00:00'
export const ASSETS_READ_BEFORE_END_AT = '2026-11-05T20:40:00+00:00'

export function declaration(memberId: string, characterName: string, overrides: Partial<MiningFleetStorageDeclaration> = {}): MiningFleetStorageDeclaration {
  return { memberId, characterName, delivery: 'none', quantities: [], canDeclare: false, ...overrides }
}

export function fleetStorage(overrides: Partial<MiningFleetStorage> = {}): MiningFleetStorage {
  return {
    operationId: OPERATION_O,
    available: true,
    editable: true,
    recipientMemberId: null,
    recipientName: null,
    containerName: null,
    declarations: [
      declaration('member-aria', 'Aria Solberg', { canDeclare: true }),
      declaration('member-brann', 'Brann Holt', { canDeclare: true }),
      declaration('member-cyra', 'Cyra Lune', { canDeclare: true }),
    ],
    ...overrides,
  }
}

export function fs2FleetStorage(overrides: Partial<MiningFleetStorage> = {}): MiningFleetStorage {
  return fleetStorage({
    recipientMemberId: 'member-brann',
    recipientName: 'Brann Holt',
    containerName: CONTAINER_NAME,
    declarations: [
      declaration('member-aria', 'Aria Solberg', { delivery: 'partial', quantities: [{ typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 100_000 }], canDeclare: true }),
      declaration('member-brann', 'Brann Holt'),
      declaration('member-cyra', 'Cyra Lune', { canDeclare: true }),
    ],
    ...overrides,
  })
}

export function fs2FleetStorageSeenByCyra(): MiningFleetStorage {
  return fs2FleetStorage({
    declarations: [
      declaration('member-aria', 'Aria Solberg', { delivery: 'partial', quantities: [{ typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 100_000 }] }),
      declaration('member-brann', 'Brann Holt'),
      declaration('member-cyra', 'Cyra Lune', { canDeclare: true }),
    ],
  })
}

export function fs2Valuation(overrides: Partial<MiningOperationValuation> = {}): MiningOperationValuation {
  const [aria, brann, cyra] = e1Participants()
  return e1Valuation({
    fleetStorageRecipientMemberId: 'member-brann',
    fleetStorageRecipientName: 'Brann Holt',
    participants: [
      { ...aria, fleetStorageDelivery: 'partial', deliveredValue: 1_961_000, keptValue: 738_093, fleetStorageDebt: 1_691_091 },
      { ...brann, isFleetStorageRecipient: true, keptValue: 2_868_460, fleetStorageDebt: 0 },
      { ...cyra, keptValue: 176, fleetStorageDebt: 0 },
    ],
    ...overrides,
  })
}

export function fs2ValuationAboveMined(): MiningOperationValuation {
  const valuation = fs2Valuation({ canFreeze: false, freezeBlockers: ['fleet_storage_exceeds_mined'] })
  return {
    ...valuation,
    participants: valuation.participants.map(valued => (valued.memberId === 'member-aria' ? { ...valued, deliveredValue: null, keptValue: null, fleetStorageDebt: null } : valued)),
  }
}

export function fs2ClosingOperation(overrides: Partial<MiningOperation> = {}): MiningOperation {
  return startedSpodumainOperation({
    status: 'closing',
    endedAt: ENDED_AT,
    participantsCount: 3,
    charactersCount: 5,
    valuationSettings: CYRA_BOOSTING_ON_JITA_SELL,
    fleetStorageRecipientName: 'Brann Holt',
    ...overrides,
  })
}

export function fs2SharesOperation(): MiningOperation {
  return fs2ClosingOperation({ status: 'shares', sharesFrozenAt: FROZEN_AT })
}

export function fs2FrozenValuation(): MiningOperationValuation {
  return fs2Valuation({ status: 'shares', frozen: true, sharesFrozenAt: FROZEN_AT, pricesReadAt: FROZEN_AT, canFreeze: false, freezeBlockers: ['not_closing'] })
}

export function fs2Payments(): MiningPayment[] {
  return [
    payment(0, { payerMemberId: 'member-brann', payerName: 'Brann Holt', amount: 556_755 }),
    payment(1, { kind: 'fleet_storage_share', payerMemberId: 'member-brann', payerName: 'Brann Holt', beneficiaryMemberId: 'member-aria', beneficiaryName: 'Aria Solberg', amount: 1_691_091 }),
  ]
}

export function e1ClosingLedger(): MiningOperationLedger {
  const known = { state: 'known' as const }
  return ledger({
    status: 'closing',
    endedAt: ENDED_AT,
    participants: [
      participant('member-aria', 'Aria Solberg', [ledgerCharacter('Aria II', known), ledgerCharacter('Aria Solberg', known)], {
        ...known,
        totalQuantity: 103_333,
        quantities: [
          { typeId: BITUMENS_TYPE_ID, typeName: 'Bitumens', quantity: 3_333 },
          { typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 100_000 },
        ],
      }),
      participant('member-brann', 'Brann Holt', [ledgerCharacter('Brann Holt', known), ledgerCharacter('Brann II', known)], {
        ...known,
        totalQuantity: 43_338,
        quantities: [
          { typeId: BITUMENS_TYPE_ID, typeName: 'Bitumens', quantity: 10_001 },
          { typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 33_337 },
        ],
      }),
      participant('member-cyra', 'Cyra Lune', [ledgerCharacter('Cyra Lune', known)], {
        ...known,
        totalQuantity: 9,
        quantities: [{ typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 9 }],
      }),
    ],
  })
}

export function verification(overrides: Partial<MiningFleetStorageVerification> = {}): MiningFleetStorageVerification {
  return {
    operationId: OPERATION_O,
    status: 'verified',
    unknownReason: null,
    containerName: CONTAINER_NAME,
    assetsReadAt: ASSETS_READ_AT,
    lines: [{ typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', expectedQuantity: 100_000, foundQuantity: 100_000, missingQuantity: 0, status: 'verified' }],
    ...overrides,
  }
}

export function shortVerification(): MiningFleetStorageVerification {
  return verification({
    status: 'short',
    lines: [{ typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', expectedQuantity: 100_000, foundQuantity: 96_400, missingQuantity: 3_600, status: 'short' }],
  })
}

export function syncedBeforeEndVerification(): MiningFleetStorageVerification {
  return verification({
    status: 'unknown',
    unknownReason: 'assets_synced_before_end',
    assetsReadAt: ASSETS_READ_BEFORE_END_AT,
    lines: [{ typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', expectedQuantity: 100_000, foundQuantity: null, missingQuantity: null, status: 'unknown' }],
  })
}

export function nothingDeliveredVerification(): MiningFleetStorageVerification {
  return verification({ status: 'nothing_delivered', containerName: null, assetsReadAt: null, lines: [] })
}

const OPERATION_SITES_PATH_PREFIX = '/mining-sites?operationId='

export function answerWithoutFleetStorage(endpoint: string): MiningFleetStorage | MiningFleetStorageVerification | MiningBoostShipOption[] | never[] | undefined {
  if (endpoint.startsWith(OPERATION_SITES_PATH_PREFIX)) return []
  if (endpoint === BOOST_SHIPS_PATH) return boostShipCatalogue()
  if (endpoint === FLEET_STORAGE_PATH) return fleetStorage()
  if (endpoint === VERIFICATION_PATH) return nothingDeliveredVerification()
  return undefined
}

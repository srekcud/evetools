import type {
  MiningLedgerCharacter,
  MiningLedgerParticipant,
  MiningLedgerSnapshot,
  MiningOperation,
  MiningOperationLedger,
  MiningOperationMember,
  MiningValuationSettings,
} from '@/stores/miningOperation'

export const OPERATION_O = '4d2f8a61-3b7c-4e95-a0d8-6c1e9f2b7a53'
export const OPERATION_P2 = 'b7e4c2a9-1d63-4f58-8a0e-5c9d3f7b2e14'
export const DETAIL_PATH = `/mining-operations/${OPERATION_O}`
export const MEMBERS_PATH = `/mining-operations/${OPERATION_O}/members`
export const LEDGER_PATH = `/mining-operations/${OPERATION_O}/ledger`
export const S1 = 30_003_504
export const SPODUMAIN_TYPE_ID = 19
export const BRANN_II_ENROLMENT_ID = '6a2d9f4e-1c83-4b57-9e20-7f3c5a1d8b64'

export const STARTED_AT = '2026-11-05T20:00:00+00:00'
export const ENDED_AT = '2026-11-05T21:30:00+00:00'

export function valuationSettings(overrides: Partial<MiningValuationSettings> = {}): MiningValuationSettings {
  return {
    valuationForm: 'compressed',
    valuationMarket: 'jita',
    valuationSide: 'buy',
    structureId: null,
    structureName: null,
    reprocessYield: null,
    boosterCutPercent: 0,
    corporationCutPercent: 0,
    boosterMemberId: null,
    boosterCharacterName: null,
    ...overrides,
  }
}

export function spodumainOperation(overrides: Partial<MiningOperation> = {}): MiningOperation {
  return {
    id: OPERATION_O,
    label: 'Anomalie Spodumain — 20:00',
    plannedStartAt: STARTED_AT,
    status: 'planned',
    visibility: 'corporation',
    splitMode: 'value_prorata',
    corporationId: 98_262_101,
    ownerCharacterName: 'Aria Solberg',
    participantsCount: 2,
    charactersCount: 4,
    joinCode: '7f3a9c2e1b',
    myRole: 'owner',
    myStatus: 'accepted',
    createdAt: '2026-11-03T18:00:00+00:00',
    startedAt: null,
    endedAt: null,
    solarSystemId: null,
    solarSystemName: null,
    myDepartedAt: null,
    valuationSettings: valuationSettings(),
    sharesFrozenAt: null,
    archivedAt: null,
    archivedByName: null,
    archiveSummary: null,
    fleetStorageRecipientName: null,
    ...overrides,
  }
}

export function startedSpodumainOperation(overrides: Partial<MiningOperation> = {}): MiningOperation {
  return spodumainOperation({ status: 'in_progress', startedAt: STARTED_AT, solarSystemId: S1, solarSystemName: 'Aldranette', ...overrides })
}

export const MEMBERS: MiningOperationMember[] = [
  {
    id: 'member-aria',
    characterName: 'Aria Solberg',
    corporationId: 98_262_101,
    role: 'owner',
    status: 'accepted',
    joinedAt: '2026-11-03T18:00:00+00:00',
    departedAt: null,
    characters: [
      { id: 'character-aria-ii', eveCharacterId: 2_112_262_101, name: 'Aria II' },
      { id: 'character-aria', eveCharacterId: 2_112_262_102, name: 'Aria Solberg' },
    ],
  },
  {
    id: 'member-brann',
    characterName: 'Brann Holt',
    corporationId: 98_262_101,
    role: 'member',
    status: 'accepted',
    joinedAt: '2026-11-04T08:15:00+00:00',
    departedAt: null,
    characters: [
      { id: 'character-brann-holt', eveCharacterId: 2_112_262_103, name: 'Brann Holt' },
      { id: 'character-brann-ii', eveCharacterId: 2_112_262_104, name: 'Brann II' },
    ],
  },
]

export function snapshot(status: MiningLedgerSnapshot['status'], referenceAt: string, readyAt: string | null, takenAt: string | null = null): MiningLedgerSnapshot {
  return { status, referenceAt, readyAt, takenAt }
}

export function ledgerCharacter(name: string, overrides: Partial<MiningLedgerCharacter> = {}): MiningLedgerCharacter {
  return {
    id: `enrolment-${name}`,
    eveCharacterId: 2_112_262_100,
    name,
    state: 'measuring',
    unknownReason: null,
    totalQuantity: null,
    startSnapshot: snapshot('taken', STARTED_AT, STARTED_AT, '2026-11-05T20:00:02+00:00'),
    endSnapshot: null,
    canRetry: false,
    quantities: [],
    ...overrides,
  }
}

export function participant(memberId: string, characterName: string, characters: MiningLedgerCharacter[], overrides: Partial<MiningLedgerParticipant> = {}): MiningLedgerParticipant {
  return { memberId, characterName, departedAt: null, state: 'measuring', totalQuantity: null, quantities: [], characters, ...overrides }
}

export function ledger(overrides: Partial<MiningOperationLedger> = {}): MiningOperationLedger {
  return {
    operationId: OPERATION_O,
    status: 'in_progress',
    startedAt: STARTED_AT,
    endedAt: null,
    solarSystemId: S1,
    solarSystemName: 'Aldranette',
    endSnapshotsReadyAt: null,
    endSnapshotsPending: false,
    endSnapshotsSettled: 0,
    endSnapshotsTotal: 0,
    participants: [
      participant('member-aria', 'Aria Solberg', [ledgerCharacter('Aria II'), ledgerCharacter('Aria Solberg')]),
      participant('member-brann', 'Brann Holt', [ledgerCharacter('Brann Holt'), ledgerCharacter('Brann II')]),
    ],
    ...overrides,
  }
}

import type {
  MiningOperationMember,
  MiningOperationValuation,
  MiningValuationParticipant,
} from '@/stores/miningOperation'
import { MEMBERS, OPERATION_O, valuationSettings } from './miningOperationLedgerFixtures'

export const VALUATION_PATH = `/mining-operations/${OPERATION_O}/valuation`
export const REPROCESSING_PROFILE_PATH = '/mining/reprocessing-profile'
export const BRANN_DEPARTED_AT = '2026-11-05T21:05:00+00:00'
export const READ_AT = '2026-11-05T21:50:00+00:00'
export const FROZEN_AT = '2026-11-05T21:52:00+00:00'
export const JITA_SYNCED_AT = '2026-11-05T21:00:00+00:00'
export const VELDSPAR_TYPE_ID = 1230
export const BITUMENS_TYPE_ID = 45492

export const CYRA_MEMBER: MiningOperationMember = {
  id: 'member-cyra',
  characterName: 'Cyra Lune',
  corporationId: 98_262_102,
  role: 'member',
  status: 'accepted',
  joinedAt: '2026-11-04T09:30:00+00:00',
  departedAt: null,
  presence: null,
  presenceIdleMinutes: null,
  characters: [{ id: 'character-cyra', eveCharacterId: 2_112_262_105, name: 'Cyra Lune' }],
}

export const MEMBERS_WITH_CYRA: MiningOperationMember[] = [...MEMBERS, CYRA_MEMBER]

export const CYRA_BOOSTING_ON_JITA_SELL = valuationSettings({ valuationSide: 'sell', boosterCutPercent: 10, boosterMemberId: 'member-cyra', boosterCharacterName: 'Cyra Lune' })

export function valuedParticipant(memberId: string, characterName: string, overrides: Partial<MiningValuationParticipant> = {}): MiningValuationParticipant {
  return {
    memberId,
    characterName,
    isBooster: false,
    quantityState: 'known',
    totalQuantity: null,
    minedValue: null,
    valuePercent: null,
    share: null,
    roundingAdjustment: null,
    iskPerHour: null,
    durationSeconds: null,
    departedAt: null,
    characters: [],
    fleetStorageDelivery: 'none',
    isFleetStorageRecipient: false,
    deliveredValue: 0,
    keptValue: null,
    fleetStorageDebt: null,
    ...overrides,
  }
}

export function e1Participants(): MiningValuationParticipant[] {
  return [
    valuedParticipant('member-aria', 'Aria Solberg', {
      totalQuantity: 103_333,
      minedValue: 2_699_093,
      keptValue: 2_699_093,
      fleetStorageDebt: 0,
      valuePercent: 48.47744924366829,
      share: 2_429_184,
      roundingAdjustment: 1,
      iskPerHour: 1_799_395.3333333333,
      durationSeconds: 5_400,
      characters: [
        { id: 'enrolment-Aria II', name: 'Aria II', quantityState: 'known', totalQuantity: 3_333, minedValue: 738_093 },
        { id: 'enrolment-Aria Solberg', name: 'Aria Solberg', quantityState: 'known', totalQuantity: 100_000, minedValue: 1_961_000 },
      ],
    }),
    valuedParticipant('member-brann', 'Brann Holt', {
      totalQuantity: 43_338,
      minedValue: 2_868_460,
      keptValue: 2_868_460,
      fleetStorageDebt: 0,
      valuePercent: 51.51938968293896,
      share: 2_581_614,
      roundingAdjustment: 0,
      iskPerHour: 2_647_809.2307692305,
      durationSeconds: 3_900,
      departedAt: BRANN_DEPARTED_AT,
      characters: [
        { id: 'enrolment-Brann Holt', name: 'Brann Holt', quantityState: 'known', totalQuantity: 10_001, minedValue: 2_214_721 },
        { id: 'enrolment-Brann II', name: 'Brann II', quantityState: 'known', totalQuantity: 33_337, minedValue: 653_739 },
      ],
    }),
    valuedParticipant('member-cyra', 'Cyra Lune', {
      isBooster: true,
      totalQuantity: 9,
      minedValue: 176,
      keptValue: 176,
      fleetStorageDebt: 0,
      valuePercent: 0.003161073392760316,
      share: 158,
      roundingAdjustment: 0,
      iskPerHour: 117.33333333333333,
      durationSeconds: 5_400,
      characters: [{ id: 'enrolment-Cyra Lune', name: 'Cyra Lune', quantityState: 'known', totalQuantity: 9, minedValue: 176 }],
    }),
  ]
}

export function e1Valuation(overrides: Partial<MiningOperationValuation> = {}): MiningOperationValuation {
  return {
    operationId: OPERATION_O,
    status: 'closing',
    splitMode: 'value_prorata',
    frozen: false,
    sharesFrozenAt: null,
    pricesReadAt: READ_AT,
    pricesSyncedAt: JITA_SYNCED_AT,
    settings: CYRA_BOOSTING_ON_JITA_SELL,
    unitPrices: [
      { typeId: BITUMENS_TYPE_ID, typeName: 'Bitumens', unitPrice: 221.45 },
      { typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', unitPrice: 19.61 },
    ],
    missingPriceTypeIds: [],
    totalValue: 5_567_729,
    boosterCut: 556_773,
    corporationCut: 0,
    iskPerHour: 3_711_819.3333333335,
    durationSeconds: 5_400,
    roundingRemainder: 2,
    boosterRoundingAdjustment: 1,
    corporationRoundingAdjustment: 0,
    participants: e1Participants(),
    canFreeze: true,
    freezeBlockers: [],
    canComplete: false,
    completionBlockers: null,
    fleetStorageRecipientMemberId: null,
    fleetStorageRecipientName: null,
    ...overrides,
  }
}

export function e1ValuationWithBrannUnknown(): MiningOperationValuation {
  const [aria, brann, cyra] = e1Participants()
  const unknownShare = { valuePercent: null, share: null, roundingAdjustment: null, fleetStorageDebt: null }
  return e1Valuation({
    totalValue: null,
    boosterCut: null,
    corporationCut: null,
    iskPerHour: null,
    roundingRemainder: null,
    boosterRoundingAdjustment: null,
    corporationRoundingAdjustment: null,
    canFreeze: false,
    freezeBlockers: ['quantities_not_known'],
    participants: [
      { ...aria, ...unknownShare },
      { ...brann, ...unknownShare, quantityState: 'unknown', totalQuantity: null, minedValue: null, iskPerHour: null, deliveredValue: null, keptValue: null },
      { ...cyra, ...unknownShare },
    ],
  })
}

export function eachForThemselvesE1Valuation(): MiningOperationValuation {
  return e1Valuation({
    splitMode: 'each_for_themselves',
    settings: valuationSettings({ valuationSide: 'sell' }),
    boosterCut: null,
    corporationCut: null,
    roundingRemainder: null,
    boosterRoundingAdjustment: null,
    corporationRoundingAdjustment: null,
    canFreeze: false,
    freezeBlockers: ['each_for_themselves'],
    participants: e1Participants().map(participant => ({ ...participant, isBooster: false, valuePercent: null, share: null, roundingAdjustment: null, fleetStorageDebt: null })),
  })
}

export function frozenE1Valuation(): MiningOperationValuation {
  return e1Valuation({
    status: 'shares',
    frozen: true,
    sharesFrozenAt: FROZEN_AT,
    pricesReadAt: FROZEN_AT,
    canFreeze: false,
    freezeBlockers: ['not_closing'],
  })
}

export function unmeasuredValuation(status: MiningOperationValuation['status'], overrides: Partial<MiningOperationValuation> = {}): MiningOperationValuation {
  return e1Valuation({
    status,
    settings: valuationSettings(),
    pricesSyncedAt: null,
    unitPrices: [],
    totalValue: null,
    boosterCut: null,
    corporationCut: null,
    iskPerHour: null,
    durationSeconds: null,
    roundingRemainder: null,
    boosterRoundingAdjustment: null,
    corporationRoundingAdjustment: null,
    participants: [],
    canFreeze: false,
    freezeBlockers: status === 'closing' ? ['quantities_not_known'] : ['not_closing', 'quantities_not_known'],
    ...overrides,
  })
}

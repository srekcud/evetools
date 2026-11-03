import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import type {
  MinedTypeQuantity,
  MiningOperation,
  MiningOperationLedger,
  MiningOperationValuation,
  MiningUnitPrice,
} from '@/stores/miningOperation'
import { SPODUMAIN_TYPE_ID, ledger, ledgerCharacter, participant, startedSpodumainOperation } from './miningOperationLedgerFixtures'
import {
  BITUMENS_TYPE_ID,
  CYRA_BOOSTING_ON_JITA_SELL,
  VELDSPAR_TYPE_ID,
  e1Valuation,
  e1ValuationWithBrannUnknown,
  eachForThemselvesE1Valuation,
} from './miningOperationValuationFixtures'

export const KERNITE_TYPE_ID = 20
export const RECAP_ENDED_AT = '2026-11-06T00:00:00+00:00'
export const RECAP_BASIS = 'Compressed, Jita Sell'
export const UNKNOWN_PILOT_LABEL = 'Unknown pilot'
export const PARTICIPANT_NAME_PARTS = ['Aria', 'Brann', 'Cyra', 'Solberg', 'Holt', 'Lune']

interface MinedType {
  typeId: number
  typeName: string
  ariaQuantity: number
  brannQuantity: number
}

export const SPODUMAIN_MINED: MinedType = { typeId: SPODUMAIN_TYPE_ID, typeName: 'Spodumain', ariaQuantity: 500_000, brannQuantity: 300_000 }
export const BITUMENS_MINED: MinedType = { typeId: BITUMENS_TYPE_ID, typeName: 'Bitumens', ariaQuantity: 1_000_000, brannQuantity: 1_000_000 }
export const KERNITE_MINED: MinedType = { typeId: KERNITE_TYPE_ID, typeName: 'Kernite', ariaQuantity: 250_000, brannQuantity: 150_000 }
export const VELDSPAR_MINED: MinedType = { typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', ariaQuantity: 100_000, brannQuantity: 50_000 }

export const FOUR_MINED_TYPES: MinedType[] = [SPODUMAIN_MINED, BITUMENS_MINED, KERNITE_MINED, VELDSPAR_MINED]

export function recapUnitPrices(overrides: Record<number, number | null> = {}): MiningUnitPrice[] {
  const prices: Record<number, number | null> = {
    [SPODUMAIN_TYPE_ID]: 1_500,
    [BITUMENS_TYPE_ID]: 400,
    [KERNITE_TYPE_ID]: 120,
    [VELDSPAR_TYPE_ID]: 20,
    ...overrides,
  }
  return FOUR_MINED_TYPES.map(mined => ({ typeId: mined.typeId, typeName: mined.typeName, unitPrice: prices[mined.typeId] ?? null }))
}

function quantities(minedTypes: MinedType[], pick: (mined: MinedType) => number): MinedTypeQuantity[] {
  return minedTypes.map(mined => ({ typeId: mined.typeId, typeName: mined.typeName, quantity: pick(mined) }))
}

export function minedLedger(minedTypes: MinedType[] = FOUR_MINED_TYPES, overrides: Partial<MiningOperationLedger> = {}): MiningOperationLedger {
  return ledger({
    status: 'shares',
    endedAt: RECAP_ENDED_AT,
    participants: [
      participant('member-aria', 'Aria Solberg', [ledgerCharacter('Aria II'), ledgerCharacter('Aria Solberg')], { state: 'known', quantities: quantities(minedTypes, mined => mined.ariaQuantity) }),
      participant('member-brann', 'Brann Holt', [ledgerCharacter('Brann Holt'), ledgerCharacter('Brann II')], { state: 'known', quantities: quantities(minedTypes, mined => mined.brannQuantity) }),
    ],
    ...overrides,
  })
}

export function minedLedgerWithBrannUnknown(): MiningOperationLedger {
  const known = minedLedger()
  return { ...known, participants: known.participants.map(member => (member.memberId === 'member-brann' ? { ...member, state: 'unknown' as const, quantities: [] } : member)) }
}

export function recapOperation(overrides: Partial<MiningOperation> = {}): MiningOperation {
  return startedSpodumainOperation({
    status: 'shares',
    endedAt: RECAP_ENDED_AT,
    participantsCount: 3,
    charactersCount: 5,
    valuationSettings: CYRA_BOOSTING_ON_JITA_SELL,
    ...overrides,
  })
}

export function recapValuation(overrides: Partial<MiningOperationValuation> = {}): MiningOperationValuation {
  return e1Valuation({
    status: 'shares',
    settings: CYRA_BOOSTING_ON_JITA_SELL,
    unitPrices: recapUnitPrices(),
    totalValue: 2_051_000_000,
    boosterCut: 205_100_000,
    iskPerHour: 512_750_000,
    durationSeconds: 14_400,
    ...overrides,
  })
}

export function recapValuationWithBrannUnknown(): MiningOperationValuation {
  return { ...e1ValuationWithBrannUnknown(), unitPrices: recapUnitPrices() }
}

export function recapValuationEachForThemselves(): MiningOperationValuation {
  return {
    ...eachForThemselvesE1Valuation(),
    status: 'completed',
    unitPrices: recapUnitPrices(),
    totalValue: 2_051_000_000,
    iskPerHour: 512_750_000,
    durationSeconds: 14_400,
  }
}

export function recapOperationEachForThemselves(): MiningOperation {
  return recapOperation({ status: 'completed', splitMode: 'each_for_themselves' })
}

export const PROPORTIONAL_RECAP_LINES = [
  'Anomalie Spodumain — 20:00 · Aldranette · 2026-11-05 20:00 → 00:00 EVE',
  `Value: 2.05B ISK · ${RECAP_BASIS}`,
  'ISK/h: 512.75M/h · duration 4 h 00 · 3 participants',
  'Top ores: Spodumain 1.20B · Bitumens 800.00M · Kernite 48.00M',
  'Booster share 10 %: 205.10M',
  'evetools · OP-2B7A53',
]

export function translator() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return (key: string, params?: Record<string, unknown>): string => i18n.global.t(key, params ?? {}) as string
}

import type { MiningOperationValuation } from '@/stores/miningOperation'

export function unknownParticipantNames(valuation: MiningOperationValuation, unknownPilotLabel: string): string {
  return valuation.participants
    .filter(participant => participant.quantityState !== 'known')
    .map(participant => participant.characterName ?? unknownPilotLabel)
    .join(', ')
}

export function missingTypeNames(valuation: MiningOperationValuation): string {
  return valuation.unitPrices
    .filter(unitPrice => unitPrice.unitPrice === null)
    .map(unitPrice => unitPrice.typeName ?? String(unitPrice.typeId))
    .join(', ')
}

import type { BomItem } from '@/stores/group-industry/types'

export function pendingQuantityOf(item: BomItem, participantId: string): number {
  return item.pendingContributions
    .filter(contribution => contribution.participantId === participantId)
    .reduce((total, contribution) => total + contribution.quantity, 0)
}

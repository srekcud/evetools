import type { BomItem } from '@/stores/group-industry/types'

export function reservedLineIdsOfMine(items: readonly Pick<BomItem, 'id' | 'reservations'>[]): string[] {
  return items.filter(item => item.reservations.some(reservation => reservation.isMine)).map(item => item.id)
}

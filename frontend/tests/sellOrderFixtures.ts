import type { ProjectSellOrder } from '@/stores/group-industry/types'

export function sellOrder(overrides: Partial<ProjectSellOrder> = {}): ProjectSellOrder {
  return {
    orderId: 6_350_000_001,
    characterName: 'Aria Solberg',
    typeId: 11_993,
    typeName: 'Hammerhead II',
    volumeRemain: 4,
    locationId: 60_003_760,
    marketName: 'Jita IV - Moon 4',
    price: 760_000,
    bestPrice: 690_000,
    rank: 3,
    state: 'undercut',
    unknownReason: null,
    undercutSince: '2026-11-10T12:10:00+00:00',
    suggestedPrice: 689_900,
    ...overrides,
  }
}

import type { ProjectTemplate } from '@/stores/group-industry/types'

export const HAMMERHEAD_LINE_FROM_ALICE: ProjectTemplate = {
  id: 'template-hammerhead-line',
  name: 'Hammerhead line',
  items: [
    { typeId: 2185, typeName: 'Hammerhead II', meLevel: 10, teLevel: 20, runs: 50 },
    { typeId: 2446, typeName: 'Warrior II', meLevel: 5, teLevel: 10, runs: 20 },
  ],
  blacklistGroupIds: [18],
  blacklistTypeIds: [34],
  acceptsOutsideFavoriteSystem: true,
  minimumOutsideSaving: 5_000_000,
  sharedWithCorporation: true,
  ownerName: 'Alice Ferrand',
  isOwn: false,
  usageCount: 3,
  createdAt: '2026-11-02T09:30:00+00:00',
}

export const SABRE_LINE_OF_BOB: ProjectTemplate = {
  id: 'template-sabre-line',
  name: 'Sabre line',
  items: [{ typeId: 22456, typeName: 'Sabre', meLevel: 4, teLevel: 8, runs: 12 }],
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  acceptsOutsideFavoriteSystem: false,
  minimumOutsideSaving: 750_000,
  sharedWithCorporation: false,
  ownerName: 'Bob Lenoir',
  isOwn: true,
  usageCount: 0,
  createdAt: '2026-11-04T18:05:00+00:00',
}

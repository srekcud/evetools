import type { Distribution, GroupProject, GroupProjectItem, MemberDistribution } from '@/stores/group-industry/types'

export const HAMMERHEAD: GroupProjectItem = { typeId: 2185, typeName: 'Hammerhead II', meLevel: 2, teLevel: 4, runs: 50 }
export const HORNET: GroupProjectItem = { typeId: 2206, typeName: 'Hornet II', meLevel: 0, teLevel: 0, runs: 20 }

export function completedProject(overrides: Partial<GroupProject> = {}): GroupProject {
  return {
    id: 'project-sabre',
    name: 'Sabre run',
    status: 'completed',
    shortLinkCode: 'abc123',
    containerName: null,
    private: false,
    acceptsOutsideFavoriteSystem: false,
    excludesInvention: false,
    minimumOutsideSaving: 0,
    sellStructureId: null,
    sellStructureName: null,
    notes: null,
    ownerCharacterName: 'Aria Solberg',
    ownerCorporationId: null,
    membersCount: 2,
    items: [HAMMERHEAD],
    totalBomValue: 1_200_000,
    fulfillmentPercent: 100,
    approvedContributionValue: 1_200_000,
    brokerFeePercent: 3.5,
    salesTaxPercent: 3.5,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-11-02T10:00:00+00:00',
    myRole: 'owner',
    myStatus: 'accepted',
    ...overrides,
  }
}

export function memberDistribution(overrides: Partial<MemberDistribution> = {}): MemberDistribution {
  return {
    memberId: 'member-aria',
    characterName: 'Aria Solberg',
    totalCostsEngaged: 800_000,
    materialCosts: 800_000,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    courierCosts: 0,
    sharePercent: 66.67,
    profitPart: 440_000,
    payoutTotal: 1_240_000,
    ...overrides,
  }
}

export function profitableDistribution(overrides: Partial<Distribution> = {}): Distribution {
  return {
    id: 'project-sabre',
    totalRevenue: 2_000_000,
    brokerFee: 70_000,
    salesTax: 70_000,
    netRevenue: 1_860_000,
    totalProjectCost: 1_200_000,
    marginPercent: 55,
    awaitingSales: false,
    unsoldProducts: [],
    projectedMarginPercent: null,
    members: [
      memberDistribution(),
      memberDistribution({
        memberId: 'member-brann',
        characterName: 'Brann Holt',
        totalCostsEngaged: 400_000,
        materialCosts: 400_000,
        sharePercent: 33.33,
        profitPart: 220_000,
        payoutTotal: 620_000,
      }),
    ],
    ...overrides,
  }
}

export const DISCORD_MESSAGE_LIMIT = 2000

import { participantRowsOf, settlementCascadeOf, settlementProfitOf } from '@/components/group-industry/projectSettlementBlock'
import type { Distribution } from '@/stores/group-industry/types'
import { memberDistribution, profitableDistribution } from './projectDiscordRecapFixtures'
import { projectSettlement, projectTransfer } from './projectSettlementFixtures'

const NET_REVENUE = 1_000_000
const PROJECT_COST = 700_000
const STORED_SURPLUS_VALUE = 50_000

function settledDistribution(overrides: Partial<Distribution> = {}): Distribution {
  return profitableDistribution({
    netRevenue: NET_REVENUE,
    totalProjectCost: PROJECT_COST,
    surplusStored: false,
    storedSurplusValue: null,
    members: [
      memberDistribution({ memberId: 'member-aria', characterName: 'Aria Solberg', materialCosts: 370_000, jobInstallCosts: 40_000, bpcCosts: 10_000, lineRentalCosts: 0, courierCosts: 0, totalCostsEngaged: 420_000, sharePercent: 60, payoutTotal: 600_000 }),
      memberDistribution({ memberId: 'member-brann', characterName: 'Brann Holt', materialCosts: 230_000, jobInstallCosts: 30_000, bpcCosts: 0, lineRentalCosts: 20_000, courierCosts: 0, totalCostsEngaged: 280_000, sharePercent: 40, payoutTotal: 400_000 }),
    ],
    ...overrides,
  })
}

describe('settlementProfitOf', () => {
  it('adds the value of the stored surplus to the net revenue minus the cost and leaves it out when it is not stored', () => {
    expect(settlementProfitOf(settledDistribution({ surplusStored: true, storedSurplusValue: STORED_SURPLUS_VALUE }))).toBe(350_000)
    expect(settlementProfitOf(settledDistribution())).toBe(300_000)
  })

  it('is unknown, never zero, when the net revenue, the cost or the value of the stored surplus is unknown', () => {
    const storedWithoutValue = settledDistribution({ surplusStored: true })

    expect({
      storedWithUnknownValue: settlementProfitOf(settledDistribution({ surplusStored: true, storedSurplusValue: null })),
      storedWithOmittedValue: settlementProfitOf(storedWithoutValue),
      unknownCost: settlementProfitOf(settledDistribution({ totalProjectCost: null })),
      unknownNetRevenue: settlementProfitOf(settledDistribution({ netRevenue: null })),
    }).toEqual({ storedWithUnknownValue: null, storedWithOmittedValue: null, unknownCost: null, unknownNetRevenue: null })
  })
})

describe('settlementCascadeOf', () => {
  it('lists the net revenue, the materials, the installations, the stored surplus and the profit, a line staying unknown as soon as one of its terms is', () => {
    const [aria, brann] = settledDistribution().members

    expect(settlementCascadeOf(settledDistribution({ surplusStored: true, storedSurplusValue: STORED_SURPLUS_VALUE }))).toEqual([
      { key: 'netRevenue', value: 1_000_000 },
      { key: 'materials', value: 600_000 },
      { key: 'installations', value: 100_000 },
      { key: 'surplus', value: 50_000 },
      { key: 'profit', value: 350_000 },
    ])
    expect(settlementCascadeOf(settledDistribution({ members: [{ ...aria, materialCosts: null }, { ...brann, lineRentalCosts: null }] }))).toEqual([
      { key: 'netRevenue', value: 1_000_000 },
      { key: 'materials', value: null },
      { key: 'installations', value: null },
      { key: 'surplus', value: null },
      { key: 'profit', value: 300_000 },
    ])
  })
})

describe('participantRowsOf', () => {
  const corinToAria = projectTransfer({ fromParticipantId: 'member-corin', fromName: 'Corin Vale', toParticipantId: 'member-aria', toName: 'Aria Solberg', amount: 500_000, received: true })
  const corinToBrann = projectTransfer({ fromParticipantId: 'member-corin', fromName: 'Corin Vale', toParticipantId: 'member-brann', toName: 'Brann Holt', amount: 300_000 })

  function threeMembers(): Distribution {
    const [aria, brann] = settledDistribution().members
    return settledDistribution({
      members: [
        { ...aria, sharePercent: 50, payoutTotal: 500_000 },
        { ...brann, sharePercent: 30, payoutTotal: 300_000 },
        memberDistribution({ memberId: 'member-corin', characterName: 'Corin Vale', sharePercent: 20, payoutTotal: 200_000 }),
      ],
    })
  }

  it('gives one row per member with the transfers received and sent and who holds the sales, and claims nothing while the transfers are unknown', () => {
    expect(participantRowsOf(threeMembers(), projectSettlement({ transfers: [corinToAria, corinToBrann] }))).toEqual([
      { memberId: 'member-aria', characterName: 'Aria Solberg', sharePercent: 50, payoutTotal: 500_000, transfers: [corinToAria], sends: [], holdsSales: false },
      { memberId: 'member-brann', characterName: 'Brann Holt', sharePercent: 30, payoutTotal: 300_000, transfers: [corinToBrann], sends: [], holdsSales: false },
      { memberId: 'member-corin', characterName: 'Corin Vale', sharePercent: 20, payoutTotal: 200_000, transfers: [], sends: [corinToAria, corinToBrann], holdsSales: true },
    ])
    for (const settlement of [projectSettlement({ transfers: null, transfersUnavailableReason: 'awaiting_sales' }), null]) {
      expect(participantRowsOf(threeMembers(), settlement).map(row => [row.memberId, row.transfers, row.sends, row.holdsSales])).toEqual([
        ['member-aria', [], [], false],
        ['member-brann', [], [], false],
        ['member-corin', [], [], false],
      ])
    }
  })
})

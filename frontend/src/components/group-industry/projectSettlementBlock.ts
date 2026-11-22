import type { Distribution, MemberDistribution, ProjectSettlement, ProjectTransfer } from '@/stores/group-industry/types'

export type SettlementCascadeKey = 'netRevenue' | 'materials' | 'installations' | 'surplus' | 'profit'

export interface SettlementCascadeLine {
  key: SettlementCascadeKey
  value: number | null
}

export interface SettlementParticipantRow {
  memberId: MemberDistribution['memberId']
  characterName: string | null
  sharePercent: number | null
  payoutTotal: number | null
  transfers: ProjectTransfer[]
  sends: ProjectTransfer[]
  holdsSales: boolean
}

function sumOrUnknown(values: (number | null)[]): number | null {
  let sum = 0
  for (const value of values) {
    if (value === null) return null
    sum += value
  }
  return sum
}

function storedSurplusValueOf(distribution: Distribution): number | null {
  return distribution.surplusStored === true ? distribution.storedSurplusValue ?? null : null
}

export function settlementProfitOf(distribution: Distribution): number | null {
  if (distribution.netRevenue === null || distribution.totalProjectCost === null) return null
  const profitBeforeSurplus = distribution.netRevenue - distribution.totalProjectCost
  if (distribution.surplusStored !== true) return profitBeforeSurplus
  const surplusValue = storedSurplusValueOf(distribution)
  return surplusValue === null ? null : profitBeforeSurplus + surplusValue
}

export function settlementCascadeOf(distribution: Distribution): SettlementCascadeLine[] {
  const members = distribution.members
  return [
    { key: 'netRevenue', value: distribution.netRevenue },
    { key: 'materials', value: sumOrUnknown(members.map(member => member.materialCosts)) },
    {
      key: 'installations',
      value: sumOrUnknown(members.flatMap(member => [member.jobInstallCosts, member.bpcCosts, member.lineRentalCosts, member.courierCosts])),
    },
    { key: 'surplus', value: storedSurplusValueOf(distribution) },
    { key: 'profit', value: settlementProfitOf(distribution) },
  ]
}

export function participantRowsOf(distribution: Distribution, settlement: ProjectSettlement | null): SettlementParticipantRow[] {
  const transfers = settlement?.transfers ?? null
  return distribution.members.map((member) => {
    const received = transfers?.filter(transfer => transfer.toParticipantId === member.memberId) ?? []
    const sends = transfers?.filter(transfer => transfer.fromParticipantId === member.memberId) ?? []
    return {
      memberId: member.memberId,
      characterName: member.characterName,
      sharePercent: member.sharePercent,
      payoutTotal: member.payoutTotal,
      transfers: received,
      sends,
      holdsSales: transfers !== null && received.length === 0 && sends.length > 0,
    }
  })
}

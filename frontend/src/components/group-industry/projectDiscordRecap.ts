import type { Distribution, GroupProject, GroupProjectItem, ProjectSettlement } from '@/stores/group-industry/types'

export type RecapTranslate = (key: string, named?: Record<string, unknown>, plural?: number) => string

export interface ProjectDiscordRecapInput {
  project: GroupProject
  distribution: Distribution
  settlement?: ProjectSettlement | null
  t: RecapTranslate
  formatIsk: (value: number) => string
  formatExactIsk?: (value: number) => string
  pilotName: (name: string | null) => string
}

const PARTICIPANT_NAME_MAX_LENGTH = 20
const MARGIN_DECIMALS = 1
const COLUMN_SEPARATOR = '  '
const SEGMENT_SEPARATOR = ' · '
const CODE_FENCE = '```'
const KEY_PREFIX = 'groupIndustry.discordRecap.'

interface PayoutRow {
  participant: string
  costs: string
  payout: string
}

function fitName(name: string): string {
  return name.length > PARTICIPANT_NAME_MAX_LENGTH ? `${name.slice(0, PARTICIPANT_NAME_MAX_LENGTH - 1)}…` : name
}

function sumOrNull(values: (number | null)[]): number | null {
  let sum = 0
  for (const value of values) {
    if (value === null) return null
    sum += value
  }
  return sum
}

function profitOf(distribution: Distribution): number | null {
  if (distribution.netRevenue === null || distribution.totalProjectCost === null) return null
  return distribution.netRevenue - distribution.totalProjectCost
}

function productLine(item: GroupProjectItem): string {
  return `**${item.runs} × ${item.typeName}** · ME ${item.meLevel} / TE ${item.teLevel}`
}

function alignedTable(rows: PayoutRow[]): string[] {
  const widthOf = (cell: keyof PayoutRow): number => Math.max(...rows.map(row => row[cell].length))
  const participantWidth = widthOf('participant')
  const costsWidth = widthOf('costs')
  const payoutWidth = widthOf('payout')
  return rows.map(row => [
    row.participant.padEnd(participantWidth),
    row.costs.padStart(costsWidth),
    row.payout.padStart(payoutWidth),
  ].join(COLUMN_SEPARATOR))
}

export function buildProjectDiscordRecap({ project, distribution, settlement = null, t, formatIsk, formatExactIsk = formatIsk, pilotName }: ProjectDiscordRecapInput): string {
  const label = (key: string, named?: Record<string, unknown>, plural?: number): string => t(`${KEY_PREFIX}${key}`, named, plural)
  const unknown = label('unknown')
  const money = (value: number | null): string => (value === null ? unknown : formatIsk(value))

  const profit = profitOf(distribution)
  const margin = distribution.marginPercent === null ? unknown : `${distribution.marginPercent.toFixed(MARGIN_DECIMALS)}%`
  const participantsCount = distribution.members.length

  const payoutRows: PayoutRow[] = [
    { participant: label('participant'), costs: label('costs'), payout: label('payout') },
    ...distribution.members.map(member => ({
      participant: fitName(pilotName(member.characterName)),
      costs: money(member.totalCostsEngaged),
      payout: money(member.payoutTotal),
    })),
    {
      participant: label('total'),
      costs: money(distribution.totalProjectCost),
      payout: money(sumOrNull(distribution.members.map(member => member.payoutTotal))),
    },
  ]

  const reference = settlement === null ? '' : `${SEGMENT_SEPARATOR}${label('referenceShort')} ${settlement.reference}`
  const transferLines = settlement === null || settlement.transfers === null || settlement.transfers.length === 0
    ? []
    : [
        `**${label('transfers')}** · ${label('reference')} \`${settlement.reference}\``,
        ...settlement.transfers.map(transfer => `- ${pilotName(transfer.fromName)} → ${pilotName(transfer.toName)}: **${formatExactIsk(transfer.amount)}**`),
      ]

  return [
    `## 🏭 ${label('completed', { name: project.name ?? label('unnamedProject') })}`,
    `-# ${label('subline', { count: participantsCount }, participantsCount)}${reference}`,
    '',
    ...project.items.map(productLine),
    `**${label('profit')} ${money(profit)}** · ${label('margin')} **${margin}**`,
    `> ${[
      `${label('sales')} ${formatIsk(distribution.totalRevenue)}`,
      `${label('brokerFee')} ${money(distribution.brokerFee)}`,
      `${label('salesTax')} ${money(distribution.salesTax)}`,
      `${label('cost')} ${money(distribution.totalProjectCost)}`,
    ].join(SEGMENT_SEPARATOR)}`,
    '',
    `**${label('payouts')}**`,
    CODE_FENCE,
    ...alignedTable(payoutRows),
    CODE_FENCE,
    ...transferLines,
    `-# ${label('footer')}`,
  ].join('\n')
}

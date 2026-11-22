import type { BomItem, GroupContribution, MaterialSourcingRow, PlanningJob, ProjectInventionProduct, ProjectLineBlueprint, ProjectPlanning, ProjectSellOrder, ResearchRow, SellForecastProduct, SourcingDecisions, SourcingRow, StructureHealthRow } from '@/stores/group-industry/types'
import { lineOfTypes, type FlowCard, type FlowCardState } from './projectFlow'
import { optionTotalOf } from './projectStartupChoices'

export type DetailBlockKind =
  | 'buyOrBuild'
  | 'whereToBuy'
  | 'courier'
  | 'contributions'
  | 'useMyStock'
  | 'depositToCheck'
  | 'blueprints'
  | 'research'
  | 'jobs'
  | 'reservation'
  | 'planning'
  | 'structure'
  | 'decryptors'
  | 'whereToSell'
  | 'sellOrders'
  | 'salesToMatch'

export interface DetailBlockInput {
  card: FlowCard
  line: BomItem | undefined
  isFinalProduct: boolean
  personalUse: boolean
  blacklistTypeIds: number[]
}

export type DetailStoreKey =
  | 'sourcingDecisions'
  | 'sourcing'
  | 'courier'
  | 'lineBlueprints'
  | 'research'
  | 'planning'
  | 'structureHealth'
  | 'sellForecast'
  | 'sellOrders'
  | 'contributions'
  | 'saleSuggestions'
  | 'contractSales'
  | 'invention'

const ORIGIN_KEY_PREFIX = 'groupIndustry.flow.detail.origins'
const INTERMEDIATE_ACTIVITIES = ['manufacturing', 'reaction']
const SUPPLY_STATES: FlowCardState[] = ['toBuy', 'toBring', 'inTransit']
const SUPPLY_BLOCKS: DetailBlockKind[] = ['whereToBuy', 'courier', 'contributions']
const JOB_BLOCKS: DetailBlockKind[] = ['jobs', 'reservation', 'planning', 'structure']
const BLUEPRINT_BLOCKS: DetailBlockKind[] = ['blueprints', 'research']
const SALES_BLOCKS: DetailBlockKind[] = ['whereToSell', 'sellOrders', 'salesToMatch']
const LINE_RENTAL_CONTRIBUTION_TYPE = 'line_rental'
const BLOCK_KINDS: DetailBlockKind[] = [
  'buyOrBuild', 'whereToBuy', 'courier', 'contributions', 'useMyStock', 'depositToCheck', 'blueprints', 'research', 'jobs',
  'reservation', 'planning', 'structure', 'decryptors', 'whereToSell', 'sellOrders', 'salesToMatch',
]

export const DETAIL_BLOCK_ORIGINS = Object.fromEntries(
  BLOCK_KINDS.map(kind => [kind, `${ORIGIN_KEY_PREFIX}.${kind}`]),
) as Record<DetailBlockKind, string>

export const DETAIL_BLOCK_TABS: Record<DetailBlockKind, string> = {
  buyOrBuild: 'bom',
  whereToBuy: 'bom',
  courier: 'bom',
  contributions: 'contributions',
  useMyStock: 'bom',
  depositToCheck: 'contributions',
  blueprints: 'bom',
  research: 'chain',
  jobs: 'chain',
  reservation: 'chain',
  planning: 'planning',
  structure: 'chain',
  decryptors: 'invention',
  whereToSell: 'distribution',
  sellOrders: 'sales',
  salesToMatch: 'sales',
}

export type DetailBlockFamily = 'buyOrBuild' | 'supply' | 'job' | 'blueprint' | 'sales'

export const DETAIL_BLOCK_FAMILIES: Record<DetailBlockKind, DetailBlockFamily> = {
  buyOrBuild: 'buyOrBuild',
  whereToBuy: 'supply',
  courier: 'supply',
  contributions: 'supply',
  useMyStock: 'supply',
  depositToCheck: 'supply',
  blueprints: 'blueprint',
  research: 'blueprint',
  decryptors: 'blueprint',
  jobs: 'job',
  reservation: 'job',
  planning: 'job',
  structure: 'job',
  whereToSell: 'sales',
  sellOrders: 'sales',
  salesToMatch: 'sales',
}

export function detailStoreKeysOf(kind: DetailBlockKind, canRecordSales: boolean): DetailStoreKey[] {
  switch (kind) {
    case 'buyOrBuild': return ['sourcingDecisions']
    case 'whereToBuy': return ['sourcing']
    case 'courier': return ['courier']
    case 'blueprints': return ['lineBlueprints']
    case 'research': return ['research']
    case 'reservation': return ['contributions']
    case 'planning': return ['planning']
    case 'structure': return ['structureHealth']
    case 'decryptors': return ['invention']
    case 'whereToSell': return ['sellForecast']
    case 'sellOrders': return ['sellOrders']
    case 'salesToMatch': return canRecordSales ? ['saleSuggestions', 'contractSales'] : []
    default: return []
  }
}

function materialBlocksOf(card: FlowCard, line: BomItem): DetailBlockKind[] {
  if (SUPPLY_STATES.includes(card.state)) return SUPPLY_BLOCKS
  const awaitingCheck = line.pendingContributions.length > 0
  if (card.source === 'stock' || awaitingCheck) return awaitingCheck ? ['useMyStock', 'depositToCheck'] : ['useMyStock']
  return ['contributions']
}

function jobBlocksOf(card: FlowCard): DetailBlockKind[] {
  return [
    ...(card.bpc ? BLUEPRINT_BLOCKS : []),
    ...JOB_BLOCKS,
    ...(card.activity === 'invention' ? ['decryptors' as const] : []),
  ]
}

function isIntermediate(line: BomItem, isFinalProduct: boolean, blacklistTypeIds: number[]): boolean {
  if (isFinalProduct) return false
  if (line.isJob) return line.activityType !== null && INTERMEDIATE_ACTIVITIES.includes(line.activityType)
  return blacklistTypeIds.includes(line.typeId)
}

export function detailBlockKindsOf({ card, line, isFinalProduct, personalUse, blacklistTypeIds }: DetailBlockInput): DetailBlockKind[] {
  if (line === undefined) return []
  return [
    ...(isIntermediate(line, isFinalProduct, blacklistTypeIds) ? ['buyOrBuild' as const] : []),
    ...(line.isJob ? jobBlocksOf(card) : materialBlocksOf(card, line)),
    ...(isFinalProduct && !personalUse ? SALES_BLOCKS : []),
  ]
}

export function lineOfCard(card: FlowCard, bomItems: BomItem[]): BomItem | undefined {
  return card.typeId === null ? undefined : lineOfTypes(bomItems).get(card.typeId)
}

export function sourcingRowOf(rows: MaterialSourcingRow[], typeId: number): MaterialSourcingRow | undefined {
  return rows.find(row => row.typeId === typeId)
}

export type SourcingComparison =
  | { state: 'none' }
  | { state: 'onRecommended' }
  | { state: 'off'; gap: number | null }

export function sourcingRowOfDecisions(decisions: SourcingDecisions | null, projectId: string, typeId: number): SourcingRow | undefined {
  if (decisions === null || decisions.id !== projectId) return undefined
  const row = decisions.rows.find(candidate => candidate.typeId === typeId)
  return row === undefined || row.stage === 'base' ? undefined : row
}

export function sourcingComparisonOf(row: SourcingRow): SourcingComparison {
  const { chosen, recommended } = row
  if (chosen === null || recommended === null) return { state: 'none' }
  if (chosen === recommended) return { state: 'onRecommended' }
  const chosenTotal = optionTotalOf(row, chosen)
  const recommendedTotal = optionTotalOf(row, recommended)
  return { state: 'off', gap: chosenTotal === null || recommendedTotal === null ? null : chosenTotal - recommendedTotal }
}

export function blueprintsOfLine(blueprints: ProjectLineBlueprint[], lineId: string): ProjectLineBlueprint[] {
  return blueprints.filter(blueprint => blueprint.bomLineId === lineId)
}

export function researchRowOf(rows: ResearchRow[], typeId: number): ResearchRow | undefined {
  return rows.find(row => row.typeId === typeId)
}

export function planningJobsOfLine(planning: ProjectPlanning, lineId: string): PlanningJob[] {
  return planning.characters.flatMap(character => character.jobs).filter(job => job.lineId === lineId)
}

export function structureRowOf(rows: StructureHealthRow[], facilityName: string): StructureHealthRow | undefined {
  return rows.find(row => row.name === facilityName)
}

export function facilityNamesOf(line: BomItem): string[] {
  return [...new Set(line.jobMatches.flatMap(match => (match.facilityName === null ? [] : [match.facilityName])))]
}

export function inventionProductOf(products: ProjectInventionProduct[], typeId: number): ProjectInventionProduct | undefined {
  return products.find(product => product.typeId === typeId)
}

export function sellForecastProductOf(products: SellForecastProduct[], typeId: number): SellForecastProduct | undefined {
  return products.find(product => product.typeId === typeId)
}

export function sellOrdersOfType(orders: ProjectSellOrder[], typeId: number): ProjectSellOrder[] {
  return orders.filter(order => order.typeId === typeId)
}

export function lineRentalsOfLine(contributions: GroupContribution[], lineId: string): GroupContribution[] {
  return contributions.filter(contribution => contribution.type === LINE_RENTAL_CONTRIBUTION_TYPE && contribution.bomItemId === lineId)
}

export function ofType<Row extends { typeId: number }>(rows: Row[], typeId: number): Row[] {
  return rows.filter(row => row.typeId === typeId)
}

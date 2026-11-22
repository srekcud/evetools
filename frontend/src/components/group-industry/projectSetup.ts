import type {
  ProjectWorkStations,
  SetupMarketWrite,
  SetupSourcingChoice,
  SourcingDecisions,
  SourcingRow,
  WorkStationType,
} from '@/stores/group-industry/types'

export type StationPicks = Record<string, string>
export type SourcingPicks = Record<number, SetupSourcingChoice>

export type StationsSummary = {
  totalCost: number | null
  gapToRecommended: number | null
  transportCost: number | null
  stationIds: string[]
}

export type SourcingSummary = {
  total: number | null
  gapToBaseline: number | null
  jita: { amount: number | null; types: number; importCost: number | null }
  structure: { amount: number | null; types: number }
}

type PurchaseMarket = 'jita' | 'structure'

function isPurchaseMarket(choice: SetupSourcingChoice | null): choice is PurchaseMarket {
  return choice === 'jita' || choice === 'structure'
}

function sumOrNull(amounts: Array<number | null>): number | null {
  let sum = 0
  for (const amount of amounts) {
    if (amount === null) return null
    sum += amount
  }
  return sum
}

export function selectedStructureId(type: WorkStationType, picks: StationPicks): string | null {
  return picks[type.workType] ?? type.chosenStructureId ?? type.recommendedStructureId
}

export function stationsPayload(types: WorkStationType[], picks: StationPicks): Record<string, string> {
  const payload: Record<string, string> = {}
  for (const type of types) {
    const structureId = selectedStructureId(type, picks)
    if (type.locked || structureId === null) continue
    payload[type.workType] = structureId
  }
  return payload
}

export function stationsSummary(workStations: ProjectWorkStations, picks: StationPicks): StationsSummary {
  const stationIds: string[] = []
  const chosenTotals: Array<number | null> = []
  const recommendedTotals: Array<number | null> = []
  const chosenTransports: Array<number | null> = []

  for (const type of workStations.workTypes) {
    const structureId = selectedStructureId(type, picks)
    if (structureId === null) continue
    if (!stationIds.includes(structureId)) stationIds.push(structureId)
    if (!type.costsCompared) continue

    const chosen = type.rows.find(row => row.structureId === structureId)
    const recommended = type.rows.find(row => row.structureId === type.recommendedStructureId)
    chosenTotals.push(chosen?.totalCost ?? null)
    recommendedTotals.push(recommended?.totalCost ?? null)
    chosenTransports.push(chosen?.transportCost ?? null)
  }

  const totalCost = sumOrNull(chosenTotals)
  const recommendedTotal = sumOrNull(recommendedTotals)

  return {
    totalCost,
    gapToRecommended: totalCost === null || recommendedTotal === null ? null : totalCost - recommendedTotal,
    transportCost: sumOrNull(chosenTransports),
    stationIds,
  }
}

export function effectiveChoice(row: SourcingRow, picks: SourcingPicks): SetupSourcingChoice | null {
  return picks[row.typeId] ?? row.chosen
}

export function sourcingQuery(rows: SourcingRow[], picks: SourcingPicks): { buy: number[]; build: number[] } {
  const buy: number[] = []
  const build: number[] = []
  for (const [key, choice] of Object.entries(picks)) {
    const typeId = Number(key)
    if (rows.find(row => row.typeId === typeId)?.stage === 'base') continue
    if (choice === 'build') build.push(typeId)
    else buy.push(typeId)
  }
  return { buy, build }
}

export function blacklistAfter(current: number[], rows: SourcingRow[], picks: SourcingPicks): number[] | null {
  const next = new Set(current)
  for (const row of rows) {
    if (row.options.build === undefined) continue
    const choice = effectiveChoice(row, picks)
    if (choice === null) continue
    if (choice === 'build') next.delete(row.typeId)
    else next.add(row.typeId)
  }
  const sorted = [...next].sort((first, second) => first - second)
  const sortedCurrent = [...new Set(current)].sort((first, second) => first - second)
  const unchanged = sorted.length === sortedCurrent.length && sorted.every((typeId, index) => typeId === sortedCurrent[index])
  return unchanged ? null : sorted
}

export function marketsToWrite(rows: SourcingRow[], picks: SourcingPicks): SetupMarketWrite[] {
  const writes: SetupMarketWrite[] = []
  for (const row of rows) {
    const picked = picks[row.typeId]
    if (picked !== undefined && isPurchaseMarket(picked)) writes.push({ typeId: row.typeId, market: picked })
  }
  return writes
}

export function sourcingSummary(decisions: SourcingDecisions, picks: SourcingPicks, baselineTotal: number | null): SourcingSummary {
  const adjustments: Array<number | null> = []
  const jitaTotals: Array<number | null> = []
  const jitaImports: Array<number | null> = []
  const structureTotals: Array<number | null> = []

  for (const row of decisions.rows) {
    const choice = effectiveChoice(row, picks)
    if (choice === 'jita') {
      jitaTotals.push(row.options.jita?.total ?? null)
      jitaImports.push(row.options.jita?.transport ?? null)
    }
    if (choice === 'structure') structureTotals.push(row.options.structure?.total ?? null)

    if (isPurchaseMarket(choice) && isPurchaseMarket(row.chosen) && choice !== row.chosen) {
      const picked = row.options[choice]?.total ?? null
      const served = row.options[row.chosen]?.total ?? null
      adjustments.push(picked === null || served === null ? null : picked - served)
    }
  }

  const adjustment = sumOrNull(adjustments)
  const total = decisions.totals.total === null || adjustment === null ? null : decisions.totals.total + adjustment

  return {
    total,
    gapToBaseline: total === null || baselineTotal === null ? null : total - baselineTotal,
    jita: { amount: sumOrNull(jitaTotals), types: jitaTotals.length, importCost: sumOrNull(jitaImports) },
    structure: { amount: sumOrNull(structureTotals), types: structureTotals.length },
  }
}

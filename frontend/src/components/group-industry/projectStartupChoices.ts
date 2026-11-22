import type {
  ProjectWorkStations,
  SetupSourcingChoice,
  SourcingRow,
  WorkStationType,
  WorkTypeKey,
} from '@/stores/group-industry/types'

export type StationChoiceLine = {
  workType: WorkTypeKey
  stationName: string | null
  recommendedStationName: string | null
  differs: boolean
  gap: number | null
}

export type SourcingChoiceSummary = {
  build: number
  jita: number
  structure: number
  undecided: number
  offRecommended: number
  gap: number | null
}

function totalOf(type: WorkStationType, structureId: string | null): number | null {
  return type.rows.find(row => row.structureId === structureId)?.totalCost ?? null
}

function nameOf(type: WorkStationType, structureId: string | null): string | null {
  return type.rows.find(row => row.structureId === structureId)?.name ?? null
}

function gapOf(type: WorkStationType, chosenId: string, referenceId: string): number | null {
  if (!type.costsCompared) return null
  const chosenTotal = totalOf(type, chosenId)
  const referenceTotal = totalOf(type, referenceId)
  return chosenTotal === null || referenceTotal === null ? null : chosenTotal - referenceTotal
}

export function stationChoiceLines(workStations: ProjectWorkStations): StationChoiceLine[] {
  const lines: StationChoiceLine[] = []
  for (const type of workStations.workTypes) {
    const stationId = type.chosenStructureId ?? type.recommendedStructureId
    if (stationId === null) continue
    const referenceId = type.storedRecommendedStructureId ?? type.recommendedStructureId
    const differs = type.chosenStructureId !== null && referenceId !== null && type.chosenStructureId !== referenceId
    lines.push({
      workType: type.workType,
      stationName: nameOf(type, stationId),
      recommendedStationName: differs ? nameOf(type, referenceId) : null,
      differs,
      gap: differs && referenceId !== null ? gapOf(type, stationId, referenceId) : 0,
    })
  }
  return lines
}

export function canEditStations(workStations: ProjectWorkStations): boolean {
  return workStations.workTypes.length > 0 && workStations.workTypes.every(type => !type.locked)
}

export function optionTotalOf(row: SourcingRow, choice: SetupSourcingChoice): number | null {
  return row.options[choice]?.total ?? null
}

export function sourcingChoiceSummary(rows: SourcingRow[]): SourcingChoiceSummary {
  const summary: SourcingChoiceSummary = { build: 0, jita: 0, structure: 0, undecided: 0, offRecommended: 0, gap: 0 }
  for (const row of rows) {
    if (row.stage === 'base') continue
    if (row.chosen === null) {
      summary.undecided++
      continue
    }
    summary[row.chosen]++
    if (row.recommended === null || row.chosen === row.recommended) continue
    summary.offRecommended++
    const chosenTotal = optionTotalOf(row, row.chosen)
    const recommendedTotal = optionTotalOf(row, row.recommended)
    summary.gap = summary.gap === null || chosenTotal === null || recommendedTotal === null ? null : summary.gap + chosenTotal - recommendedTotal
  }
  return summary
}
